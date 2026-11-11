<?php

namespace App\Services\Studio;

use App\Models\MediaAsset;
use App\Models\Studio\DesignExport;
use App\Models\Studio\StudioAsset;
use App\Models\Studio\StudioAvLease;
use App\Models\Studio\StudioAvWorkspace;
use Aws\Exception\AwsException;
use Illuminate\Filesystem\AwsS3V3Adapter;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;

class StudioAvRegistrationCleanup
{
    public function __construct(private readonly StudioAvStorage $storage) {}

    public function run(int $workspaceId, ?float $deadline = null): bool
    {
        if (! StudioAvWorkspace::withoutUserScope()->whereKey($workspaceId)->exists()) {
            return true;
        }
        $deadline = min($deadline ?? microtime(true) + 25, microtime(true) + 25);
        $this->remaining($deadline);
        try {
            return $this->storage->locked($workspaceId, function (StudioAvWorkspace $workspace) use ($deadline) {
                $this->remaining($deadline);
                $receipt = $this->begin($workspace);
                if ($receipt === null) {
                    return false;
                }
                if ($receipt === []) {
                    return true;
                }
                $location = $receipt['location'];
                if ($location !== null) {
                    if ($location['driver'] === 's3') {
                        if (! $this->remote($workspace, $receipt, $deadline)) {
                            return false;
                        }
                    } else {
                        $this->local($receipt, $deadline);
                    }
                } elseif ($receipt['upload_id'] !== null) {
                    throw new RuntimeException('El multipart no tiene una ubicación durable.');
                }
                $this->remaining($deadline);

                return DB::transaction(function () use ($workspace, $receipt) {
                    DesignExport::withoutUserScope()->whereKey($workspace->export_id)->lockForUpdate()->first();
                    $current = StudioAvWorkspace::withoutUserScope()->whereKey($workspace->id)->lockForUpdate()->firstOrFail();
                    if (! $current->media_retiring || ($current->media_receipt['token'] ?? null) !== $receipt['token']) {
                        return false;
                    }
                    $media = MediaAsset::withoutUserScope()->whereKey($current->media_id)->lockForUpdate()->first();
                    if ($media && ($media->isReady() || $this->referenced($media))) {
                        return false;
                    }
                    $media?->delete();
                    $done = $current->media_receipt;
                    $done['state'] = 'released';
                    $done['upload_id'] = null;
                    unset($done['cursor']);
                    $current->update(['media_id' => null, 'media_reserved_bytes' => 0, 'media_retiring' => false, 'media_cleanup_after' => null, 'media_receipt' => $done]);

                    return true;
                });
            });
        } catch (StudioAvWorkspaceBusy) {
            return false;
        }
    }

    private function begin(StudioAvWorkspace $workspace): ?array
    {
        return DB::transaction(function () use ($workspace) {
            $export = DesignExport::withoutUserScope()->whereKey($workspace->export_id)->lockForUpdate()->first();
            $current = StudioAvWorkspace::withoutUserScope()->whereKey($workspace->id)->lockForUpdate()->firstOrFail();
            $receipt = $current->media_receipt;
            if ($receipt === null || ($receipt['state'] ?? '') === 'released') {
                return $current->media_reserved_bytes === 0 ? [] : null;
            }
            if (($receipt['version'] ?? null) !== 1 || ($receipt['media_id'] ?? null) !== $current->media_id
                || ! in_array($receipt['path'] ?? '', ['media/'.$current->user_id.'/studio-'.$current->uuid.'.mp4', 'media/'.$current->user_id.'/studio-'.$current->uuid.'.gif'], true)) {
                throw new RuntimeException('El recibo de retiro no pertenece al workspace.');
            }
            $media = MediaAsset::withoutUserScope()->whereKey($current->media_id)->lockForUpdate()->first();
            if ($media && ($media->user_id !== $current->user_id || $media->disk !== $receipt['disk'] || $media->path !== $receipt['path'])) {
                throw new RuntimeException('La reserva de biblioteca cambió de propietario o destino.');
            }
            if ($media?->isReady()) {
                return [];
            }
            if ($media && $this->referenced($media)) {
                return null;
            }
            if (! $current->media_retiring) {
                if (($export && ! in_array($export->status, ['failed', 'cancelled'], true)) || $current->active_until->isFuture()
                    || StudioAvLease::where('workspace_id', $current->id)->where('expires_at', '>', now())->exists()) {
                    return null;
                }
                $receipt['state'] = 'retiring';
                $receipt['token'] = (string) Str::uuid();
                $current->update(['media_retiring' => true, 'media_cleanup_after' => now(), 'media_receipt' => $receipt]);
            }

            return $receipt;
        });
    }

    private function referenced(MediaAsset $media): bool
    {
        return $media->usedByPosts() || StudioAsset::withoutUserScope()->where('media_asset_id', $media->id)->exists();
    }

    private function remote(StudioAvWorkspace $workspace, array $receipt, float $deadline): bool
    {
        $location = $receipt['location'];
        $disk = Storage::disk($receipt['disk']);
        if (! $disk instanceof AwsS3V3Adapter || $disk->getConfig()['bucket'] !== $location['bucket']
            || (string) $disk->getClient()->getEndpoint() !== $location['endpoint']
            || ($location['key'] !== $receipt['path'] && ! str_ends_with($location['key'], '/'.$receipt['path']))) {
            throw new RuntimeException('El backend original no está disponible para retirar la copia.');
        }
        $client = $disk->getClient();
        $address = ['Bucket' => $location['bucket'], 'Key' => $location['key']];
        if ($receipt['upload_id'] !== null) {
            $upload = $address + ['UploadId' => $receipt['upload_id']];
            try {
                $client->abortMultipartUpload($upload + $this->http($deadline));
                try {
                    $client->listParts($upload + ['MaxParts' => 1] + $this->http($deadline));

                    return false; // The upload ID is still queryable; retain it and confirm on another pass.
                } catch (AwsException $error) {
                    if ($error->getStatusCode() !== 404) {
                        throw $error;
                    }
                }
            } catch (AwsException $error) {
                if ($error->getStatusCode() !== 404) {
                    throw $error;
                }
            }
            $this->checkpoint($workspace, $receipt['token'], ['upload_id' => null]);
        }
        $cursor = $receipt['cursor'] ?? [];
        $listed = $client->listMultipartUploads(['Bucket' => $location['bucket'], 'Prefix' => $location['key'], 'MaxUploads' => 16]
            + $cursor + $this->http($deadline));
        foreach ($listed['Uploads'] ?? [] as $upload) {
            if (($upload['Key'] ?? null) === $location['key']) {
                $this->checkpoint($workspace, $receipt['token'], ['upload_id' => $upload['UploadId'], 'cursor' => []]);

                return false;
            }
        }
        if ($listed['IsTruncated'] ?? false) {
            $next = ['KeyMarker' => $listed['NextKeyMarker'] ?? '', 'UploadIdMarker' => $listed['NextUploadIdMarker'] ?? ''];
            if ($next === $cursor || $next['KeyMarker'] === '') {
                throw new RuntimeException('El listado de multipart no avanzó.');
            }
            $this->checkpoint($workspace, $receipt['token'], ['cursor' => $next]);

            return false;
        }
        $client->deleteObject($address + $this->http($deadline));
        try {
            $client->headObject($address + $this->http($deadline));

            return false;
        } catch (AwsException $error) {
            if ($error->getStatusCode() !== 404) {
                throw $error;
            }
        }

        return true;
    }

    private function checkpoint(StudioAvWorkspace $workspace, string $token, array $changes): void
    {
        DB::transaction(function () use ($workspace, $token, $changes) {
            $current = StudioAvWorkspace::withoutUserScope()->whereKey($workspace->id)->lockForUpdate()->firstOrFail();
            if (! $current->media_retiring || ($current->media_receipt['token'] ?? null) !== $token) {
                throw new RuntimeException('El retiro cambió de generación.');
            }
            $current->update(['media_receipt' => array_replace($current->media_receipt, $changes)]);
        });
    }

    private function local(array $receipt, float $deadline): void
    {
        $root = rtrim(Storage::disk($receipt['disk'])->path(''), '/\\');
        if ($root !== $receipt['location']['root'] || is_link($root) || ! is_dir($root) || ! is_readable($root)
            || (PHP_OS_FAMILY !== 'Windows' && ! is_executable($root))) {
            throw new RuntimeException('La raíz original de biblioteca cambió.');
        }
        foreach ([$receipt['path'].'.part', $receipt['path']] as $relative) {
            $path = $root;
            foreach (explode('/', $relative) as $piece) {
                $this->remaining($deadline);
                if (! preg_match('/^[a-zA-Z0-9][a-zA-Z0-9_.-]*$/D', $piece) || in_array($piece, ['.', '..'], true)) {
                    throw new RuntimeException('El destino de retiro no es válido.');
                }
                $path .= DIRECTORY_SEPARATOR.$piece;
                clearstatcache(true, $path);
                if (is_link($path) || (is_dir($path) && (! is_readable($path) || (PHP_OS_FAMILY !== 'Windows' && ! is_executable($path))))) {
                    throw new RuntimeException('El retiro no admite enlaces ni directorios inaccesibles.');
                }
            }
            if (file_exists($path) && (! is_file($path) || ! @unlink($path))) {
                throw new RuntimeException('No se pudo retirar el archivo privado de biblioteca.');
            }
            clearstatcache(true, $path);
            if (file_exists($path)) {
                throw new RuntimeException('El archivo privado sigue pendiente de retiro.');
            }
        }
    }

    private function http(float $deadline): array
    {
        $seconds = min(5, $this->remaining($deadline));

        return ['@retries' => 0, '@http' => ['timeout' => $seconds, 'connect_timeout' => min(2, $seconds)]];
    }

    private function remaining(float $deadline): float
    {
        $seconds = $deadline - microtime(true);
        if ($seconds <= 0 || ! is_finite($seconds)) {
            throw new RuntimeException('El retiro continuará en otra pasada.');
        }

        return $seconds;
    }
}
