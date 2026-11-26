<?php

namespace App\Services\Export;

use App\Models\DataExport;
use App\Models\MediaAsset;
use App\Models\Report;
use App\Models\User;
use App\Services\JobFailureNotifier;
use App\Support\Zip\ZipTooLarge;
use App\Support\Zip\ZipWriter;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Database\Query\Builder;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use League\Flysystem\Local\LocalFilesystemAdapter;
use RuntimeException;
use Throwable;

/**
 * Genera el ZIP con todos los datos propios de un usuario (T-84). Un fichero JSON por entidad (`datos/`), los ficheros originales en
 * carpetas (`medios/`, `informes/`, `estudio/`; solo con «incluir media»), un `LEEME.txt` y un `manifest.json` con la lista de ficheros y su
 * SHA-256. El ZIP usa ZIP64 (`ZipWriter`): sin límite de 4 GiB por fichero ni de 65 535 ficheros; el único tope es `exports.max_bytes`.
 *
 * Todo se lee con el `user_id` del propietario (nunca el usuario autenticado: corre en cola) y por lotes, y se escribe en streaming a un
 * fichero temporal con ruta conocida (`DataExport::temporaryPath()`): la media nunca se carga entera en memoria. El job late (`heartbeat_at`)
 * mientras trabaja y se aborta sin publicar si la fila desaparece. El ZIP solo se publica en el disco privado cuando está completo; si algo falla
 * (o se supera el tope) no queda nada en el disco ni en el directorio temporal.
 */
final class FullExport
{
    /** Versión del formato del ZIP: sube si cambia la estructura de carpetas o del manifiesto. */
    public const FORMAT_VERSION = 1;

    public const GENERIC_ERROR = 'No se pudo preparar la exportación. Inténtalo de nuevo; si se repite, avisa al administrador.';

    public const STUCK_ERROR = 'La exportación se quedó sin terminar. Inténtalo de nuevo.';

    private const CHUNK = 500;

    /** @var list<array{path: string, size: int, sha256: string}> */
    private array $files = [];

    /** @var array<string, int> */
    private array $counts = [];

    /** @var list<array{path: string, reason: string}> */
    private array $skipped = [];

    private ?DataExport $current = null;

    private float $lastBeat = 0.0;

    private bool $publishByRename = false;

    public function __construct(private readonly JobFailureNotifier $notices, private readonly DiskSpace $space) {}

    /**
     * Ejecuta la exportación pendiente: la marca en curso, genera el ZIP y la deja lista y avisada, o en error y avisada. Un fallo que no es de
     * tamaño ni de espacio se relanza para que el worker lo registre; en ningún caso queda un ZIP a medias ni la exportación en curso.
     */
    public function run(DataExport $export): void
    {
        if ($export->status !== DataExport::STATUS_PENDING) {
            return;
        }
        $export->forceFill(['status' => DataExport::STATUS_PROCESSING, 'started_at' => now(), 'heartbeat_at' => now()])->save();
        try {
            $result = $this->archive($export);
        } catch (ExportAborted) {
            return; // la fila ya no existe o ya no está en curso: nadie espera este ZIP
        } catch (ZipTooLarge) {
            $this->fail($export, $this->tooLargeMessage($export));

            return;
        } catch (InsufficientSpace $e) {
            $this->fail($export, $e->getMessage());

            return;
        } catch (Throwable $e) {
            Log::error('full_export.failed', ['export_id' => $export->id, 'exception' => $e::class, 'message' => $e->getMessage()]);
            $this->fail($export, self::GENERIC_ERROR);

            throw $e;
        }
        $published = DataExport::withoutUserScope()->whereKey($export->id)->where('in_progress', true)->update($result + [
            'status' => DataExport::STATUS_READY, 'in_progress' => null, 'error' => null, 'completed_at' => now(), 'expires_at' => now()->addHours((int) config('exports.retention_hours')), 'updated_at' => now(),
        ]);
        if ($published === 0) {
            DataExport::disk()->delete($result['path']);

            return;
        }
        $export->refresh();
        // La copia anterior solo se sustituye cuando la nueva se ha publicado bien.
        DataExport::withoutUserScope()->where('user_id', $export->user_id)->where('id', '!=', $export->id)->get()->each(function (DataExport $old): void {
            $old->deleteFile();
            $old->delete();
        });
        $this->notices->fullExportDone($export);
    }

    /** Deja la exportación en error, libera el cupo del usuario, borra cualquier resto (ZIP y temporal) y avisa una sola vez. */
    public function fail(DataExport $export, string $reason): void
    {
        $export->refresh();
        if (! $export->in_progress) {
            return;
        }
        DataExport::disk()->delete(DataExport::pathFor($export->user_id, $export->id));
        @unlink($export->temporaryPath());
        $export->forceFill(['path' => null, 'size' => null, 'file_count' => null, 'completed_at' => now()])->release(DataExport::STATUS_FAILED, $reason);
        $this->notices->fullExport($export, $reason);
    }

    private function tooLargeMessage(DataExport $export): string
    {
        $limit = (int) config('exports.max_bytes');
        $text = $limit >= 1_073_741_824 ? round($limit / 1_073_741_824, 1).' GB' : max(1, intdiv($limit, 1_048_576)).' MB';

        return $export->include_media
            ? "El archivo supera el tope de $text. Vuelve a pedir la exportación sin incluir los archivos de media: tendrás todos tus datos en JSON."
            : "El archivo supera el tope de $text. Pide al administrador que suba el tope.";
    }

    /** @return array{path: string, size: int, file_count: int} */
    private function archive(DataExport $export): array
    {
        $this->files = $this->skipped = $this->counts = [];
        $this->current = $export;
        $this->lastBeat = 0.0;
        $user = User::findOrFail($export->user_id);
        $disk = DataExport::disk();
        $path = DataExport::pathFor($user->id, $export->id);
        $temporary = $export->temporaryPath();
        $this->preflight($export, $disk, dirname($temporary));
        $handle = fopen($temporary, 'w+b');
        if ($handle === false) {
            throw new RuntimeException('No se pudo crear el fichero temporal de la exportación.');
        }
        $open = true;
        try {
            $zip = new ZipWriter($handle, (int) config('exports.max_bytes'), fn () => $this->beat());
            foreach (ExportInventory::ENTITIES as $name => $entity) {
                $this->addEntity($zip, $name, $entity, $user->id);
            }
            $this->addFiles($zip, $user->id, $export->include_media);
            $zip->addString('LEEME.txt', $this->readme($export->include_media));
            $zip->addString('manifest.json', $this->manifest($export->include_media));
            $size = $zip->finish();
            $this->beat(true);
            fclose($handle);
            $open = false;

            $this->publish($disk, $temporary, $path);

            return ['path' => $path, 'size' => $size, 'file_count' => count($this->files) + 2];
        } finally {
            if ($open) {
                fclose($handle);
            }
            @unlink($temporary);
        }
    }

    /**
     * Publica el ZIP terminado. En el mismo volumen que el destino local lo MUEVE (`rename`: atómico y sin una segunda copia); si no, lo
     * copia en streaming y, si algo falla, no deja el ZIP a medias en el destino.
     */
    private function publish(Filesystem $disk, string $temporary, string $path): void
    {
        try {
            if ($this->publishByRename) {
                $disk->makeDirectory(dirname($path));
                $target = $disk->path($path);
                if (@rename($temporary, $target)) {
                    @chmod($target, 0600);

                    return;
                }
            }
            $source = fopen($temporary, 'rb');
            try {
                if (! $disk->writeStream($path, $source)) {
                    throw new RuntimeException('No se pudo guardar el ZIP en el disco privado.');
                }
            } finally {
                fclose($source);
            }
        } catch (Throwable $e) {
            $disk->delete($path);
            throw $e;
        }
    }

    /**
     * Antes de empezar: con media, si los ficheros ya superan el tope falla sin copiar nada; y comprueba que hay sitio para el ZIP más un margen
     * (que cubre los JSON de las entidades, que no se conocen de antemano). El directorio temporal y el disco local de destino suelen ser el MISMO
     * volumen: entonces el ZIP se publica con `rename` y basta una copia. Si están en volúmenes distintos se exige espacio en cada uno (la copia
     * del destino) y si el destino no es local solo en el temporal. La copia anterior, que se conserva hasta publicar la nueva, ya ocupa su sitio y
     * por eso ya está descontada del espacio libre que se mide. Si el espacio no se puede medir, falla de forma cerrada: un disco lleno tumbaría
     * también la base de datos, y es preferible negarse a arrancar.
     */
    private function preflight(DataExport $export, Filesystem $disk, string $temporaryDir): void
    {
        $files = $export->include_media ? $this->estimatedFileBytes($export->user_id) : 0;
        if ($files > (int) config('exports.max_bytes')) {
            throw new ZipTooLarge('Los ficheros superan el tope.');
        }
        if (! is_dir($temporaryDir) && ! @mkdir($temporaryDir, 0700, true) && ! is_dir($temporaryDir)) {
            throw new RuntimeException('No se pudo crear el directorio temporal de la exportación.');
        }
        $required = $files + $this->estimatedJsonBytes($export->user_id) + (int) config('exports.free_space_margin');
        $local = $disk instanceof FilesystemAdapter && $disk->getAdapter() instanceof LocalFilesystemAdapter ? $disk->path('') : null;
        $this->publishByRename = $local !== null && $this->space->sameVolume($temporaryDir, $local);
        $targets = [$temporaryDir];
        if ($local !== null && ! $this->publishByRename) {
            $targets[] = $local;
        }
        foreach ($targets as $directory) {
            $free = $this->space->free($directory);
            if ($free === false) {
                throw new InsufficientSpace('No se pudo comprobar el espacio libre del servidor para preparar la exportación. Avisa al administrador.');
            }
            if ($free < $required) {
                throw new InsufficientSpace('No hay espacio libre suficiente en el servidor para preparar la exportación. Avisa al administrador.');
            }
        }
    }

    /** Bytes de los ficheros que van al ZIP: media, informes, tipografías y exportaciones del Estudio (MP4, GIF, PDF...; su tamaño se lee del disco). */
    private function estimatedFileBytes(int $userId): int
    {
        $total = (int) DB::table('media_assets')->where('user_id', $userId)->where('status', MediaAsset::STATUS_READY)->sum(DB::raw('coalesce(size, declared_size, 0)'))
            + (int) DB::table('reports')->where('user_id', $userId)->where('status', Report::STATUS_READY)->sum('size')
            + (int) DB::table('studio_fonts')->where('user_id', $userId)->sum('size');
        DB::table('studio_design_exports')->where('user_id', $userId)->where('status', 'ready')->whereNotNull('path')->select(['id', 'disk', 'path'])
            ->chunkById(self::CHUNK, function ($rows) use (&$total): void {
                foreach ($rows as $row) {
                    try {
                        $total += (int) Storage::disk($row->disk ?? 'local')->size($row->path);
                    } catch (Throwable) {
                        // Un fichero que ya no está se anotará como omitido en el manifiesto.
                    }
                }
            }, 'id');

        return $total;
    }

    /** JSON conocidos de antemano: documentos de los diseños y la versión vigente de las tablas de datos (el resto va en el margen). */
    private function estimatedJsonBytes(int $userId): int
    {
        return (int) DB::table('studio_designs')->where('user_id', $userId)->sum(DB::raw('length(document)'))
            + (int) DB::table('studio_dataset_revisions as r')->join('studio_datasets as d', fn ($join) => $join->on('d.id', '=', 'r.dataset_id')->on('d.revision', '=', 'r.revision'))
                ->where('r.user_id', $userId)->sum('r.json_bytes');
    }

    /** Latido del job: marca de vida de la fila; si ya no es suya o ya no está en curso, aborta sin publicar. */
    private function beat(bool $force = false): void
    {
        $now = microtime(true);
        if (! $force && $now - $this->lastBeat < (int) config('exports.heartbeat_seconds')) {
            return;
        }
        $this->lastBeat = $now;
        $alive = DataExport::withoutUserScope()->whereKey($this->current->id)->where('user_id', $this->current->user_id)->where('in_progress', true)->update(['heartbeat_at' => now()]);
        if ($alive === 0) {
            throw new ExportAborted;
        }
    }

    // ------------------------------------------------------------------ datos en JSON

    /** @param  array<string, mixed>  $entity */
    private function addEntity(ZipWriter $zip, string $name, array $entity, int $userId): void
    {
        $columns = $this->columns($entity);
        $json = $entity['json'] ?? [];
        $stream = fopen('php://temp/maxmemory:8388608', 'w+b');
        $count = 0;
        try {
            fwrite($stream, "[\n");
            $each = function ($rows) use ($stream, $json, &$count): void {
                foreach ($rows as $row) {
                    $row = (array) $row;
                    foreach ($json as $column) {
                        if (isset($row[$column]) && is_string($row[$column])) {
                            $row[$column] = json_decode($row[$column], true) ?? $row[$column];
                        }
                    }
                    fwrite($stream, ($count++ > 0 ? ",\n" : '').$this->encode($row));
                }
                $this->beat();
            };
            $query = $this->query($entity, $userId)->select($columns);
            if (isset($entity['order'])) {
                foreach ($entity['order'] as $column) {
                    $query->orderBy($column);
                }
                $query->chunk(self::CHUNK, $each);
            } else {
                $query->chunkById(self::CHUNK, $each, 'id');
            }
            fwrite($stream, "\n]\n");
            rewind($stream);
            $this->record($zip->addStream('datos/'.$name.'.json', $stream, (int) fstat($stream)['size']), 'datos/'.$name.'.json');
        } finally {
            fclose($stream);
        }
        $this->counts[$name] = $count;
    }

    /** @param  array<string, mixed>  $entity */
    private function query(array $entity, int $userId): Builder
    {
        $query = DB::table($entity['table']);
        if (isset($entity['via'])) {
            [$column, $parent] = $entity['via'];
            $query->whereIn($column, DB::table($parent)->where('user_id', $userId)->select('id'));
        } else {
            $query->where($entity['by'] ?? 'user_id', $userId);
        }
        foreach ($entity['where'] ?? [] as $column => $value) {
            $query->where($column, $value);
        }

        return $query;
    }

    /**
     * Columnas a exportar: las de la lista blanca (o todas menos la lista negra) sin ninguna que parezca una credencial.
     *
     * @param  array<string, mixed>  $entity
     * @return list<string>
     */
    private function columns(array $entity): array
    {
        $columns = $entity['only'] ?? array_values(array_diff(Schema::getColumnListing($entity['table']), $entity['except'] ?? []));

        return array_values(array_filter($columns, fn (string $column) => ! preg_match(ExportInventory::SECRET_COLUMN, $column)));
    }

    /** @param  array<string, mixed>  $value */
    private function encode(array $value): string
    {
        return json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT | JSON_INVALID_UTF8_SUBSTITUTE | JSON_THROW_ON_ERROR);
    }

    // ------------------------------------------------------------------ ficheros

    private function addFiles(ZipWriter $zip, int $userId, bool $includeMedia): void
    {
        if ($includeMedia) {
            $this->addBinaryFiles($zip, $userId);
        }

        // Datos del Estudio en JSON: van siempre, con o sin media. Solo la revisión vigente de cada tabla de datos.
        DB::table('studio_dataset_revisions as r')->join('studio_datasets as d', fn ($join) => $join->on('d.id', '=', 'r.dataset_id')->on('d.revision', '=', 'r.revision'))
            ->where('r.user_id', $userId)->select(['r.id', 'r.dataset_id', 'r.path', 'r.json_bytes'])->orderBy('r.id')->chunkById(self::CHUNK, function ($rows) use ($zip): void {
                foreach ($rows as $row) {
                    $this->addFile($zip, 'estudio/tablas-de-datos/'.$row->dataset_id.'.json', Storage::disk('local'), $row->path, (int) $row->json_bytes);
                }
            }, 'r.id', 'id');

        DB::table('studio_designs')->where('user_id', $userId)->select(['id', 'document'])->chunkById(50, function ($rows) use ($zip): void {
            foreach ($rows as $row) {
                $document = json_decode((string) $row->document, true) ?? [];
                $this->record($zip->addString('estudio/disenos/'.$row->id.'.json', $this->encode($document)), 'estudio/disenos/'.$row->id.'.json');
                $this->beat();
            }
        }, 'id');
    }

    /** Ficheros originales: media, informes generados, exportaciones del Estudio y tipografías. */
    private function addBinaryFiles(ZipWriter $zip, int $userId): void
    {
        $media = DB::table('media_assets')->where('user_id', $userId)->where('status', MediaAsset::STATUS_READY);
        $media->select(['id', 'disk', 'path', 'original_name', 'size'])->chunkById(self::CHUNK, function ($rows) use ($zip): void {
            foreach ($rows as $row) {
                $this->addFile($zip, 'medios/'.$row->id.'-'.$this->safeName($row->original_name, $row->path), Storage::disk($row->disk), $row->path, $row->size === null ? null : (int) $row->size);
            }
        }, 'id');

        $reports = DB::table('reports')->where('user_id', $userId)->where('status', Report::STATUS_READY)->whereNotNull('path');
        $reports->select(['id', 'format', 'path', 'size'])->chunkById(self::CHUNK, function ($rows) use ($zip): void {
            foreach ($rows as $row) {
                $this->addFile($zip, 'informes/'.$row->id.'.'.$row->format, Storage::disk((string) config('filesystems.media')), $row->path, $row->size === null ? null : (int) $row->size);
            }
        }, 'id');

        $exports = DB::table('studio_design_exports')->where('user_id', $userId)->where('status', 'ready')->whereNotNull('path');
        $exports->select(['id', 'disk', 'path'])->chunkById(self::CHUNK, function ($rows) use ($zip): void {
            foreach ($rows as $row) {
                $this->addFile($zip, 'estudio/exportaciones/'.$row->id.'/'.$this->safeName(basename($row->path), $row->path), Storage::disk($row->disk ?? 'local'), $row->path);
            }
        }, 'id');

        $fonts = DB::table('studio_fonts')->where('user_id', $userId)->whereNotNull('path');
        $fonts->select(['id', 'disk', 'path', 'name', 'size'])->chunkById(self::CHUNK, function ($rows) use ($zip): void {
            foreach ($rows as $row) {
                $this->addFile($zip, 'estudio/tipografias/'.$row->id.'-'.$this->safeName($row->name, $row->path), Storage::disk($row->disk), $row->path, (int) $row->size);
            }
        }, 'id');
    }

    /** Copia un fichero del disco al ZIP en streaming. Si ya no existe se anota en el manifiesto y se sigue. */
    private function addFile(ZipWriter $zip, string $entry, Filesystem $disk, string $path, ?int $size = null): void
    {
        $this->beat();
        if ($path === '' || str_contains($path, '..')) {
            $this->skipped[] = ['path' => $entry, 'reason' => 'Ruta no válida.'];

            return;
        }
        $source = $disk->readStream($path);
        if (! is_resource($source)) {
            $this->skipped[] = ['path' => $entry, 'reason' => 'El fichero ya no está en el almacenamiento.'];

            return;
        }
        try {
            $this->record($zip->addStream($entry, $source, $size), $entry);
        } finally {
            fclose($source);
        }
    }

    /** Nombre de entrada seguro: sin directorios, sin caracteres de control y acotado. */
    private function safeName(?string $name, string $fallback): string
    {
        $name = trim(preg_replace('/[\x00-\x1F\x7F\\\\\/:*?"<>|]+/u', '_', basename((string) ($name !== null && $name !== '' ? $name : $fallback))) ?? '');

        return Str::limit($name !== '' ? $name : 'fichero', 100, '');
    }

    /** @param  array{size: int, sha256: string}  $written */
    private function record(array $written, string $path): void
    {
        $this->files[] = ['path' => $path] + $written;
    }

    // ------------------------------------------------------------------ manifiesto y léeme

    private function manifest(bool $includeMedia): string
    {
        return $this->encode([
            'generated_at' => now()->toIso8601String(),
            'format_version' => self::FORMAT_VERSION,
            'app' => (string) config('app.name'),
            'include_media' => $includeMedia,
            'entities' => $this->counts,
            'skipped' => $this->skipped,
            'files' => $this->files,
        ]);
    }

    private function readme(bool $includeMedia): string
    {
        $lines = [
            config('app.name').' · exportación completa de tus datos',
            'Generada el '.now()->toIso8601String().' (UTC) · formato '.self::FORMAT_VERSION,
            '',
            'CONTENIDO',
            '  datos/      Un fichero JSON por tipo de dato (marcas, publicaciones, informes, sitios...). Cada fila lleva sus identificadores.',
            $includeMedia
                ? '  medios/     Los ficheros originales de tu biblioteca, como «<id>-<nombre>».'
                : '  (sin media)  Esta exportación se pidió sin los archivos de media, informes generados, exportaciones ni tipografías: solo datos.',
            '  informes/   Los informes PDF o CSV ya generados.',
            '  estudio/    El documento JSON de cada diseño, sus exportaciones, tus tipografías y las tablas de datos.',
            '  manifest.json  La lista de ficheros con su tamaño y su SHA-256 (sha256sum -c o Get-FileHash para comprobarlos).',
            '',
            'NO INCLUIDO (por seguridad y privacidad)',
        ];
        foreach (ExportInventory::EXCLUDED as $line) {
            $lines[] = '  - '.$line;
        }

        return implode("\n", $lines)."\n";
    }
}
