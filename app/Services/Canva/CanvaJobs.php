<?php

namespace App\Services\Canva;

use App\Jobs\ImportRemoteMedia;
use App\Jobs\RunCanvaJob;
use App\Models\Brand;
use App\Models\Canva\CanvaConnection;
use App\Models\Canva\CanvaJob;
use App\Models\MediaAsset;
use App\Models\User;
use App\Services\JobFailureNotifier;
use App\Services\MediaUploadService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * Trabajos asincronos contra Canva (T-16).
 *  - Exportar: crea el job de exportacion de Canva (PNG, JPG, MP4 o PDF), lo sondea de forma ACOTADA y, al terminar, descarga cada URL con
 *    `CanvaSource` (UrlGuard, host de Canva, tipo real y tamaño) a la biblioteca con su origen. El PDF no entra en la biblioteca (solo admite
 *    imagen y vídeo): se devuelve el enlace temporal de Canva.
 *  - Enviar a Canva: sube los bytes de un recurso de la biblioteca como asset de Canva y sondea el job.
 * Nunca compra nada ni publica nada.
 */
class CanvaJobs
{
    public const FORMATS = ['png', 'jpg', 'pdf', 'mp4'];

    /** Estado intermedio: Canva ya exporto y las descargas a la biblioteca estan en curso. */
    public const IMPORTING = 'importing';

    /** Motivos de CanvaException que se pueden reintentar sin dar el trabajo por perdido. */
    public const TRANSIENT = ['unreachable', 'unavailable', 'rate_limited'];

    private const UPLOAD_MAX_BYTES = 50 * 1024 * 1024;

    public function __construct(
        private readonly CanvaClient $client,
        private readonly CanvaConnections $connections,
        private readonly MediaUploadService $uploads,
    ) {}

    /** @param  array{transparent?: bool, title?: ?string}  $options */
    public function startExport(User $user, Brand $brand, CanvaConnection $connection, string $designId, string $format, array $options = []): CanvaJob
    {
        $format = strtolower($format);
        if (! in_array($format, self::FORMATS, true)) {
            throw new CanvaException('Elige un formato de exportación: PNG, JPG, MP4 o PDF.', 422, 'bad_format');
        }
        $scopes = (array) $connection->scopes;
        $transparent = ($options['transparent'] ?? false) === true;
        if ($transparent && $format !== 'png') {
            throw new CanvaException('El fondo transparente solo existe en PNG.', 422, 'bad_option');
        }
        if (! CanvaCapabilities::allows('export', [], $scopes)) {
            throw new CanvaException((string) CanvaCapabilities::reason('export', [], $scopes), 403, 'scope_missing');
        }
        if ($transparent && ! CanvaCapabilities::allows('transparent_png', $this->connections->capabilities($connection), $scopes)) {
            throw new CanvaException((string) CanvaCapabilities::reason('transparent_png', [], $scopes), 403, 'capability_required');
        }
        $this->assertRoom($user);

        $job = new CanvaJob([
            'brand_id' => $brand->id, 'kind' => CanvaJob::KIND_EXPORT, 'design_id' => CanvaDesigns::validDesignId($designId), 'format' => $format,
            'title' => Str::limit(trim((string) ($options['title'] ?? '')), 255, ''), 'options' => ['transparent' => $transparent], 'status' => CanvaJob::QUEUED,
        ]);
        $job->user_id = $user->id;
        $job->save();
        RunCanvaJob::dispatch($job->id);

        return $job;
    }

    public function startUpload(User $user, Brand $brand, CanvaConnection $connection, int $mediaId): CanvaJob
    {
        if (! CanvaCapabilities::allows('send', [], (array) $connection->scopes)) {
            throw new CanvaException((string) CanvaCapabilities::reason('send', [], (array) $connection->scopes), 403, 'scope_missing');
        }
        $media = MediaAsset::withoutUserScope()->where('user_id', $user->id)->find($mediaId);
        if ($media === null || ! $media->isReady() || ! in_array($media->kind, [MediaAsset::KIND_IMAGE, MediaAsset::KIND_VIDEO], true)) {
            throw new CanvaException('Elige una imagen o un vídeo listo de tu biblioteca.', 422, 'bad_media');
        }
        if (($media->size ?? 0) > self::UPLOAD_MAX_BYTES) {
            throw new CanvaException('El recurso pesa demasiado para enviarlo a Canva (máximo 50 MB).', 422, 'too_big');
        }
        $this->assertRoom($user);
        $job = new CanvaJob([
            'brand_id' => $brand->id, 'kind' => CanvaJob::KIND_UPLOAD, 'media_id' => $media->id, 'title' => Str::limit((string) $media->original_name, 255, ''), 'status' => CanvaJob::QUEUED,
        ]);
        $job->user_id = $user->id;
        $job->save();
        RunCanvaJob::dispatch($job->id);

        return $job;
    }

    /**
     * Un paso del trabajo. Devuelve true si hay que volver a sondear (el job se reencola con espera) y false si ha terminado.
     */
    public function process(int $jobId): bool
    {
        // Un solo paso a la vez por trabajo: un reintento o un duplicado no crea dos veces el job remoto ni los recursos.
        return Cache::lock('canva.job.'.$jobId, 120)->block(5, fn () => $this->step($jobId));
    }

    private function step(int $jobId): bool
    {
        $job = CanvaJob::withoutUserScope()->find($jobId);
        if ($job === null || ! in_array($job->status, [CanvaJob::QUEUED, CanvaJob::PROCESSING], true)) {
            return false;
        }
        $connection = CanvaConnection::withoutUserScope()->where('user_id', $job->user_id)->where('brand_id', $job->brand_id)->first();
        if ($connection === null || $connection->status !== 'active') {
            return $this->fail($job, 'not_connected', 'La conexión con Canva ya no está activa. Vuelve a conectar la cuenta.');
        }

        try {
            $token = $this->connections->accessToken($connection);
            $remote = $job->canva_job_id === null ? $this->begin($job, $token) : $this->poll($job, $token);
        } catch (CanvaException $e) {
            // Red caida, 5xx o limite de Canva: transitorio, el job de cola reintenta con espera y el trabajo sigue vivo.
            if (in_array($e->reason, self::TRANSIENT, true)) {
                throw $e;
            }

            return $this->fail($job, $e->reason, $e->getMessage());
        }

        $status = (string) ($remote['status'] ?? '');
        if ($status === 'failed') {
            return $this->fail($job, (string) ($remote['error']['code'] ?? 'failed'), $this->remoteError($job, $remote));
        }
        if ($status === 'success') {
            return $job->kind === CanvaJob::KIND_EXPORT ? $this->finishExport($job, $remote) : $this->finishUpload($job, $remote);
        }

        $job->forceFill(['status' => CanvaJob::PROCESSING, 'polls' => $job->polls + 1])->save();
        if ($job->polls >= (int) config('services.canva.max_polls', 40)) {
            return $this->fail($job, 'timeout', 'Canva tarda demasiado en responder. Inténtalo de nuevo más tarde.');
        }

        return true;
    }

    /** Estado de un trabajo para la interfaz, la API y el MCP. @return array<string, mixed> */
    public function present(CanvaJob $job): array
    {
        $job = $this->settle($job);
        $media = [];
        foreach ((array) $job->output_media_ids as $id) {
            $asset = MediaAsset::withoutUserScope()->where('user_id', $job->user_id)->find($id);
            if ($asset !== null) {
                $media[] = $asset->toPublicArray();
            }
        }

        return [
            'id' => $job->id, 'kind' => $job->kind, 'status' => $job->status, 'design_id' => $job->design_id, 'title' => $job->title, 'format' => $job->format,
            'media_id' => $job->media_id, 'media' => $media,
            // Enlaces temporales (24 h) de Canva para los formatos que no entran en la biblioteca (PDF): explicitos, nunca descargados aqui.
            'download_urls' => $job->format === 'pdf' && $job->status === CanvaJob::READY ? array_values((array) $job->download_urls) : [],
            'canva_asset_id' => $job->canva_asset_id, 'error_code' => $job->error_code, 'error' => $job->error,
            'created_at' => $job->created_at?->toIso8601String(),
        ];
    }

    public function find(User $user, Brand $brand, int $id): CanvaJob
    {
        return CanvaJob::withoutUserScope()->where('user_id', $user->id)->where('brand_id', $brand->id)->findOrFail($id);
    }

    /** @return list<CanvaJob> */
    public function recent(User $user, Brand $brand, int $limit = 15): array
    {
        return CanvaJob::withoutUserScope()->where('user_id', $user->id)->where('brand_id', $brand->id)->latest('id')->limit($limit)->get()->all();
    }

    /** @return array<string, mixed> */
    private function begin(CanvaJob $job, string $token): array
    {
        if ($job->kind === CanvaJob::KIND_UPLOAD) {
            $media = MediaAsset::withoutUserScope()->where('user_id', $job->user_id)->find($job->media_id);
            $disk = $media === null ? null : Storage::disk($media->disk);
            if ($media === null || $disk === null || ! $disk->exists($media->path)) {
                throw new CanvaException('El recurso ya no está en la biblioteca.', 404, 'bad_media');
            }
            $remote = $this->client->uploadAsset($token, (string) pathinfo((string) $media->original_name, PATHINFO_FILENAME) ?: 'recurso', (string) $disk->get($media->path));
        } else {
            $format = ['type' => $job->format];
            if ($job->format === 'jpg') {
                $format['quality'] = 90; // obligatorio en JPG
            }
            if ($job->format === 'png' && ($job->options['transparent'] ?? false)) {
                $format['transparent_background'] = true;
            }
            $remote = $this->client->createExport($token, (string) $job->design_id, $format);
        }
        if (! is_string($remote['id'] ?? null)) {
            throw new CanvaException('Canva no devolvió el trabajo. Inténtalo de nuevo.', 502, 'bad_response');
        }
        $job->forceFill(['canva_job_id' => $remote['id'], 'status' => CanvaJob::PROCESSING])->save();

        return $remote;
    }

    /** @return array<string, mixed> */
    private function poll(CanvaJob $job, string $token): array
    {
        $id = (string) $job->canva_job_id;

        return $job->kind === CanvaJob::KIND_UPLOAD ? $this->client->assetUploadJob($token, $id) : $this->client->exportJob($token, $id);
    }

    /** @param  array<string, mixed>  $remote */
    private function finishExport(CanvaJob $job, array $remote): bool
    {
        $urls = array_values(array_filter((array) ($remote['urls'] ?? []), 'is_string'));
        if ($urls === [] || count($urls) > (int) config('services.canva.max_pages', 10)) {
            return $this->fail($job, 'bad_response', $urls === [] ? 'Canva no devolvió ningún fichero.' : 'El diseño tiene demasiadas páginas para importarlo (máximo '.config('services.canva.max_pages', 10).').');
        }
        foreach ($urls as $url) {
            if (! CanvaSource::allowedUrl($url)) {
                return $this->fail($job, 'bad_url', 'Canva devolvió una dirección de descarga que no se acepta.');
            }
        }
        if ($job->format === 'pdf') {
            $job->forceFill(['status' => CanvaJob::READY, 'download_urls' => $urls])->save();

            return false;
        }

        $user = User::find($job->user_id);
        if ($user === null) {
            return $this->fail($job, 'not_connected', 'El usuario ya no existe.');
        }
        $base = Str::limit(preg_replace('/[\\\\\/:*?"<>|\x00-\x1f]+/u', ' ', (string) ($job->title ?: 'Diseño de Canva')) ?? 'Diseño de Canva', 120, '');
        // Idempotente: lo creado en un intento anterior se reutiliza (y se persiste tras cada recurso) para no duplicar ni dejar huerfanos.
        $ids = array_values((array) $job->output_media_ids);
        foreach ($urls as $i => $url) {
            if (isset($ids[$i]) && ($existing = MediaAsset::withoutUserScope()->where('user_id', $job->user_id)->find($ids[$i])) !== null) {
                if ($existing->status === MediaAsset::STATUS_PENDING) {
                    dispatch(new ImportRemoteMedia($existing->id, $url)); // unico por recurso: no se duplica si ya estaba en cola
                }

                continue;
            }
            try {
                $name = trim($base).(count($urls) > 1 ? ' ('.($i + 1).')' : '').'.'.($job->format === 'jpg' ? 'jpg' : $job->format);
                $asset = $this->uploads->beginImport($user, MediaAsset::SOURCE_CANVA, $this->stripQuery($url), $name);
            } catch (InvalidArgumentException $e) {
                return $this->fail($job, 'import_unavailable', $e->getMessage());
            }
            $asset->origin = ['provider' => 'canva', 'design_id' => $job->design_id, 'url' => CanvaDesigns::designLink((string) $job->design_id), 'title' => $job->title, 'job_id' => $job->id];
            $asset->save();
            $ids[$i] = $asset->id;
            $job->forceFill(['output_media_ids' => $ids])->save();
            // `dispatch(new ...)`: la URL firmada de Canva viaja en el payload CIFRADO del job, no en argumentos variadicos.
            dispatch(new ImportRemoteMedia($asset->id, $url));
        }
        $job->forceFill(['status' => self::IMPORTING, 'output_media_ids' => $ids])->save();

        return false;
    }

    /** @param  array<string, mixed>  $remote */
    private function finishUpload(CanvaJob $job, array $remote): bool
    {
        $id = $remote['asset']['id'] ?? null;
        $job->forceFill(['status' => CanvaJob::READY, 'canva_asset_id' => is_string($id) ? Str::limit($id, 80, '') : null])->save();

        return false;
    }

    /** Resuelve «importando» mirando el estado de los recursos descargados. */
    private function settle(CanvaJob $job): CanvaJob
    {
        if ($job->status !== self::IMPORTING) {
            return $job;
        }
        $assets = MediaAsset::withoutUserScope()->where('user_id', $job->user_id)->whereIn('id', (array) $job->output_media_ids)->get();
        if ($assets->contains('status', MediaAsset::STATUS_PENDING)) {
            return $job;
        }
        $bad = $assets->firstWhere('status', MediaAsset::STATUS_INVALID);
        if ($assets->count() < count((array) $job->output_media_ids) || $bad !== null) {
            $job->forceFill(['status' => CanvaJob::FAILED, 'error_code' => 'import_failed', 'error' => Str::limit((string) ($bad?->error ?: 'No se pudo guardar el fichero exportado en la biblioteca.'), 500, '')])->save();
            app(JobFailureNotifier::class)->canva($job);
        } else {
            $job->forceFill(['status' => CanvaJob::READY])->save();
        }

        return $job;
    }

    /** @param  array<string, mixed>  $remote */
    private function remoteError(CanvaJob $job, array $remote): string
    {
        return match ($remote['error']['code'] ?? null) {
            'license_required' => 'El diseño usa elementos premium de Canva que no has comprado. Cómpralos en Canva o cambia de plan; aquí no se compra nada.',
            'approval_required' => 'El diseño necesita la aprobación de un revisor en Canva antes de exportarse.',
            'file_too_big' => 'El fichero es demasiado grande para Canva.',
            'fetch_failed', 'import_failed' => 'Canva no pudo procesar el fichero.',
            default => $job->kind === CanvaJob::KIND_EXPORT ? 'Canva no pudo exportar el diseño. Inténtalo de nuevo más tarde.' : 'Canva no pudo guardar el recurso. Inténtalo de nuevo más tarde.',
        };
    }

    private function fail(CanvaJob $job, string $code, string $message): bool
    {
        $job->forceFill(['status' => CanvaJob::FAILED, 'error_code' => Str::limit($code, 40, ''), 'error' => Str::limit($message, 500, '')])->save();
        app(JobFailureNotifier::class)->canva($job);

        return false;
    }

    private function assertRoom(User $user): void
    {
        $active = CanvaJob::withoutUserScope()->where('user_id', $user->id)->whereIn('status', [CanvaJob::QUEUED, CanvaJob::PROCESSING, self::IMPORTING])
            ->where('created_at', '>', now()->subHour())->count();
        if ($active >= (int) config('services.canva.max_active_jobs', 3)) {
            throw new CanvaException('Ya tienes '.config('services.canva.max_active_jobs', 3).' trabajos de Canva en curso; espera a que terminen.', 429, 'too_many');
        }
    }

    private function stripQuery(string $url): string
    {
        return explode('?', $url, 2)[0];
    }
}
