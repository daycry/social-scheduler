<?php

namespace App\Jobs;

use App\Models\MediaAsset;
use App\Services\Media\DriveFileSource;
use App\Services\Media\RemoteMediaImporter;
use App\Services\Media\RemoteSource;
use App\Services\Media\UrlGuard;
use App\Services\Media\UrlSource;
use App\Services\MediaStorage;
use App\Services\MediaUploadService;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Contracts\Queue\ShouldBeUniqueUntilProcessing;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

/**
 * Importa media de una URL (o de Drive) por pasadas: cada ejecucion descarga como mucho `media.import_budget_seconds` y se
 * vuelve a encolar SIN espera hasta que el fichero esta entero (una espera dejaba al worker con `--stop-when-empty` ~40 s parado
 * entre pasadas; el bucle lo acota el importador, que da por fallida una descarga que no avanza). Cifrado porque el token de Drive viaja en el payload.
 * `UntilProcessing`: el candado de unicidad se libera al EMPEZAR, para que la propia pasada pueda encolar la siguiente.
 */
class ImportRemoteMedia implements ShouldBeEncrypted, ShouldBeUniqueUntilProcessing, ShouldQueue
{
    use Queueable;

    /** Menor que el `--timeout` del worker (120 s) y que `retry_after` (180 s): ver config/queue.php. */
    public int $timeout = 110;

    /** Fallos transitorios consecutivos (red, 5xx) antes de dar el asset por invalido. */
    public int $tries = 3;

    public int $uniqueFor = 600;

    public function __construct(public readonly int $assetId, public readonly string $reference, #[\SensitiveParameter] public readonly ?string $token = null)
    {
        $this->onQueue((string) config('media.import_queue', 'media'));
    }

    public function uniqueId(): string
    {
        return 'import-media:'.$this->assetId;
    }

    /** @return list<int> */
    public function backoff(): array
    {
        return [30, 120];
    }

    public function handle(RemoteMediaImporter $importer): void
    {
        $asset = MediaAsset::withoutUserScope()->find($this->assetId);

        if ($asset === null || $asset->status !== MediaAsset::STATUS_PENDING) {
            return;
        }

        if (! $importer->run($asset, $this->sourceFor($asset))) {
            dispatch(new static($this->assetId, $this->reference, $this->token)); // sin argumentos variadicos: el token no iria en claro en una traza
        }
    }

    public function failed(?Throwable $e): void
    {
        $asset = MediaAsset::withoutUserScope()->find($this->assetId);

        if ($asset === null || $asset->status !== MediaAsset::STATUS_PENDING) {
            return;
        }

        app(MediaStorage::class)->disk()->delete($asset->path);
        app(MediaUploadService::class)->invalidate($asset, 'No se pudo descargar el fichero; inténtalo de nuevo más tarde.');
    }

    private function sourceFor(MediaAsset $asset): RemoteSource
    {
        return match ($asset->source) {
            MediaAsset::SOURCE_URL => new UrlSource(app(UrlGuard::class), $this->reference),
            MediaAsset::SOURCE_DRIVE => new DriveFileSource(app(UrlGuard::class), $this->reference, (string) $this->token),
            default => throw new \LogicException("Origen de importacion desconocido: {$asset->source}"),
        };
    }
}
