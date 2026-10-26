<?php

namespace App\Services\Media;

use App\Models\MediaAsset;
use App\Services\MediaStorage;
use App\Services\MediaUploadService;
use InvalidArgumentException;

/**
 * Una PASADA de la importacion remota (B1). Descarga con `Range` a `media/{user}/{ulid}.part` durante como mucho
 * `media.import_budget_seconds`; el punto de control es el tamano del propio parcial en disco (no hay otro contador que
 * pueda desincronizarse). Devuelve `true` cuando el asset ya esta resuelto (`listo` o `invalido`) y `false` si hace falta otra
 * pasada. Al terminar renombra el parcial y deja la validacion final (tamano, tipo real, metadatos) a `finalize()`.
 */
class RemoteMediaImporter
{
    /** Pasadas CONSECUTIVAS «pobres» (menos de `media.import_min_pass_bytes` sin completar) tras las que se da la importacion por fallida. */
    private const MAX_STALLS = 3;

    public function __construct(
        private readonly MediaStorage $storage,
        private readonly MediaUploadService $uploads,
    ) {}

    public function run(MediaAsset $asset, RemoteSource $source): bool
    {
        if ($asset->status !== MediaAsset::STATUS_PENDING) {
            return true;
        }

        if ($this->storage->isS3()) {
            return $this->fail($asset, 'La importación remota necesita el almacenamiento local; descarga el fichero y súbelo.');
        }

        // Acotan el reencolado SIN espera (gap F5-2 #1, CWE-400/834): ni por edad ni por numero total de pasadas puede una importacion
        // vivir indefinidamente, aunque el remoto suelte unos pocos bytes por pasada.
        $age = $asset->created_at === null ? 0 : (int) $asset->created_at->diffInSeconds(now(), true);

        if ($age > (int) config('media.import_max_age_seconds', 7200) || (int) (((array) $asset->import_state)['passes'] ?? 0) >= (int) config('media.import_max_passes', 200)) {
            return $this->fail($asset, 'La descarga tarda demasiado; inténtalo de nuevo más tarde o descarga el fichero y súbelo.');
        }

        $disk = $this->storage->disk();
        $disk->makeDirectory(dirname($asset->path));
        $absolute = $disk->path($asset->path);

        $sink = fopen($absolute, 'c+b');
        clearstatcache(true, $absolute);
        $offset = (int) filesize($absolute);
        $state = (array) $asset->import_state;

        // Latido (gap F5-2 #3): la pasada deja constancia de que el worker ha empezado, para que la UI distinga una cola parada
        // (0 pasadas empezadas) de una pasada larga que aun no ha traido el primer byte.
        $state['runs'] = ($state['runs'] ?? 0) + 1;
        $this->save($asset, $state);

        $policy = function (?string $mime, ?int $total): int {
            try {
                $kind = $this->uploads->kindFor((string) $mime);
            } catch (InvalidArgumentException) {
                throw new ImportFailedException('Tipo de fichero no admitido. Usa JPG, PNG, WebP, MP4 o MOV.');
            }

            $cap = (int) config("media.max_bytes.$kind");

            if ($total !== null && $total > $cap) {
                throw new ImportFailedException('El fichero supera el tamaño máximo permitido para '.($kind === 'video' ? 'vídeos' : 'imágenes').'.');
            }

            return $cap;
        };

        // Se reserva tiempo al final del presupuesto para `finalize()` (tamano, tipo real, metadatos con ffprobe): sin ello la ultima
        // pasada podria agotar el timeout del job justo al terminar.
        $deadline = microtime(true) + max(1, (int) config('media.import_budget_seconds', 80) - (int) config('media.import_finalize_reserve_seconds', 10));

        try {
            $pass = $source->fetch($sink, $offset, $state['validator'] ?? null, $policy, (int) max((array) config('media.max_bytes')), $deadline, $state['total'] ?? null);
        } catch (ImportFailedException|UnsafeUrlException $e) {
            fclose($sink);

            return $this->fail($asset, $e->getMessage());
        } catch (\Throwable $e) {
            fclose($sink);
            // Transitorio (red, 5xx): el job reintenta y reanuda desde el parcial. Se relanza SIN la excepcion original: los mensajes
            // de Guzzle/cURL llevan la URL completa (con su query, que puede ser un token) y acabarian en `failed_jobs` y en el log.
            throw new \RuntimeException(preg_replace('#https?://\S+#i', '[url]', $e->getMessage()) ?: class_basename($e));
        }

        if ($pass->restart) {
            // El remoto ignoro `Range` o cambio: se descarta el parcial y se empieza de cero UNA vez.
            ftruncate($sink, 0);
            fclose($sink);

            if (($state['restarts'] ?? 0) >= 1) {
                return $this->fail($asset, match ($pass->restartReason) {
                    RangeIgnored::IGNORED => 'El servidor no permite descargas por partes; descárgalo y súbelo.',
                    RangeIgnored::CHANGED => 'El fichero remoto cambia durante la descarga; inténtalo de nuevo más tarde.',
                    default => 'El servidor remoto no permite reanudar la descarga o el fichero cambia mientras se descarga; descárgalo y súbelo.',
                });
            }

            $this->save($asset, ['bytes' => 0, 'total' => null, 'validator' => null, 'restarts' => ($state['restarts'] ?? 0) + 1, 'passes' => ($state['passes'] ?? 0) + 1] + $state);

            return false;
        }

        fclose($sink);
        $bytes = $offset + $pass->written;
        $state = array_replace($state, [
            'bytes' => $bytes,
            'total' => $pass->total ?? ($state['total'] ?? null),
            'validator' => $state['validator'] ?? $pass->validator,
            'ranges' => ($state['ranges'] ?? false) || $pass->ranges,
            'passes' => ($state['passes'] ?? 0) + 1,
            // Pasadas seguidas «pobres» (menos del ritmo minimo por pasada): con la cola reencolando sin espera, un remoto que suelta
            // 1 B por pasada (goteo) provocaba un bucle caliente que el contador de pasadas a 0 bytes no veia.
            'stalls' => $pass->complete || $pass->written >= (int) config('media.import_min_pass_bytes', 65536) ? 0 : ($state['stalls'] ?? 0) + 1,
        ]);

        if (($state['stalls'] ?? 0) >= self::MAX_STALLS) {
            return $this->fail($asset, 'La descarga no avanza; inténtalo de nuevo más tarde.');
        }

        if ($pass->mime !== null && $offset === 0) {
            $kind = $this->uploads->kindFor($pass->mime);
            $asset->forceFill(['mime' => $pass->mime, 'kind' => $kind]);
            $state['mime'] = $pass->mime;
        }

        $mime = (string) ($state['mime'] ?? $asset->mime);

        if (! ($state['sniffed'] ?? false) && ($bytes >= 16 || $pass->complete)) {
            if (! $this->sniffMatches($absolute, $mime)) {
                return $this->fail($asset, 'El contenido no coincide con el tipo de fichero declarado.');
            }

            $state['sniffed'] = true;
        }

        if ($pass->complete) {
            return $this->finish($asset, $state, $mime, $bytes);
        }

        if (! ($state['ranges'] ?? false)) {
            return $this->fail($asset, 'El servidor no permite descargas por partes; descárgalo y súbelo.');
        }

        $this->save($asset, $state);

        return false;
    }

    /**
     * Renombra el parcial al nombre final y deja a `finalize()` la comprobacion de tamano, tipo real y metadatos. El `path` final
     * solo se guarda en base de datos cuando `finalize()` lo persiste (o `invalidate()`); si `finalize()` revienta, el fichero vuelve
     * a su nombre de parcial para que el reintento lo reanude sin renombrarlo dos veces.
     */
    private function finish(MediaAsset $asset, array $state, string $mime, int $bytes): bool
    {
        $partial = $asset->path;
        $final = preg_replace('/\.(part|jpe?g|png|webp|mp4|mov)$/i', '', $partial).'.'.$this->uploads->extensionFor($mime);
        $disk = $this->storage->disk();
        $disk->move($partial, $final);

        $asset->forceFill([
            'path' => $final,
            'mime' => $mime,
            'kind' => $this->uploads->kindFor($mime),
            'declared_size' => $bytes,
            'import_state' => $state,
        ]);

        try {
            $this->uploads->finalize($asset);
        } catch (\Throwable $e) {
            if ($disk->exists($final)) {
                $disk->move($final, $partial);
            }

            throw $e;
        }

        return true;
    }

    /** El contenido real (`finfo`) debe ser del mismo tipo que declaro el servidor: una cabecera `image/jpeg` no basta. */
    private function sniffMatches(string $absolute, string $mime): bool
    {
        $head = (string) file_get_contents($absolute, false, null, 0, 4096);
        $real = (new \finfo(FILEINFO_MIME_TYPE))->buffer($head) ?: '';

        return $real === $mime;
    }

    private function save(MediaAsset $asset, array $state): void
    {
        $asset->forceFill(['import_state' => $state])->save();
    }

    private function fail(MediaAsset $asset, string $reason): bool
    {
        $this->storage->disk()->delete($asset->path);
        $this->uploads->invalidate($asset, $reason);

        return true;
    }
}
