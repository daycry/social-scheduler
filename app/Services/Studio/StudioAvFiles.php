<?php

namespace App\Services\Studio;

use App\Connectors\Data\PublishMedia;
use App\Connectors\Support\MediaChunkReader;
use App\Models\Brand;
use App\Models\Studio\Design;
use App\Models\Studio\DesignExport;
use App\Models\Studio\StudioAvLease;
use App\Models\Studio\StudioAvWorkspace;
use App\Models\User;
use Closure;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

class StudioAvFiles
{
    public function inspect(StudioAvLease $lease, array $result, ?float $deadline = null): array
    {
        return $this->locked($lease, fn () => $this->inspectResult($lease, $result, min($deadline ?? microtime(true) + 85, microtime(true) + 85)));
    }

    private function inspectResult(StudioAvLease $lease, array $result, float $deadline): array
    {
        [$current, $workspace, $export] = $this->context($lease);
        $this->validateResult($current, $export, $result, false);
        $this->verifyPath($workspace, $result['file'], $result, $deadline);
        $this->context($current);

        return $result;
    }

    public function publishSource(StudioAvLease $lease, ?float $deadline = null): string
    {
        return $this->locked($lease, fn () => $this->promoteSource($lease, min($deadline ?? microtime(true) + 85, microtime(true) + 85)));
    }

    private function promoteSource(StudioAvLease $lease, float $deadline): string
    {
        [$current, $workspace] = $this->context($lease, true);
        if (! in_array($current->phase, ['asset', 'font'], true)) {
            throw new RuntimeException('El checkpoint no es un origen del diseño.');
        }
        $result = $this->verifyCheckpoint($current, $deadline);
        $relative = $current->phase === 'asset' ? 'assets/'.$current->item.'.bin' : 'fonts/'.$current->item.'.woff2';
        $destination = $this->path($workspace, $relative, true);
        if (! file_exists($destination)) {
            $source = $this->path($workspace, $result['file']);
            // A retry can reuse the same input inode. Never replace a concurrent accepted input.
            if (! @link($source, $destination) && ! is_file($destination)) {
                throw new RuntimeException('No se pudo preparar el origen privado del render.');
            }
        }
        $this->verifyPath($workspace, $relative, $result, $deadline);
        StudioAvLease::whereKey($current->id)->where('status', 'accepted')->update(['input_published' => true]);

        return $relative;
    }

    public function verify(StudioAvLease $lease, ?float $deadline = null): array
    {
        return $this->locked($lease, fn () => $this->verifyCheckpoint($lease, min($deadline ?? microtime(true) + 85, microtime(true) + 85)));
    }

    private function verifyCheckpoint(StudioAvLease $lease, float $deadline): array
    {
        [$current, $workspace, $export] = $this->context($lease, true);
        $result = $current->result;
        $this->validateResult($current, $export, $result, true);
        $this->verifyPath($workspace, $result['file'], $result, $deadline);

        return $result;
    }

    private function validateResult(StudioAvLease $current, DesignExport $export, array $result, bool $accepted): void
    {
        $expected = match ($current->phase) {
            'asset' => "imports/{$current->id}/asset-{$current->item}.bin",
            'font' => "imports/{$current->id}/font-{$current->item}.woff2",
            'normalize' => "audio-sources/{$current->id}/{$current->item}.pcm",
            'render' => sprintf('segments/%s/video-%06d.%s', $current->id, $current->item, $export->format),
            'mix' => sprintf('segments/%s/audio-%06d.wav', $current->id, $current->item),
            'assemble' => "outputs/{$current->id}/project.{$export->format}",
            default => null,
        };
        if ($expected === null || ($result['file'] ?? null) !== $expected || ($result['namespace'] ?? null) !== $current->id
            || ! is_int($result['size'] ?? null) || $result['size'] < 1 || $result['size'] > 8 * 1024 ** 3
            || ($accepted ? $result['size'] !== $current->actual_bytes : $result['size'] > $current->reserved_bytes)
            || preg_match('/^[a-f0-9]{64}$/D', $result['sha256'] ?? '') !== 1) {
            throw new StudioAvCheckpointCorrupt('El checkpoint privado no coincide con su fase.');
        }
    }

    private function verifyPath(StudioAvWorkspace $workspace, string $relative, array $result, float $deadline): void
    {
        if (microtime(true) >= $deadline) {
            throw new StudioAvProcessFailed('phase_timeout');
        }
        $path = app(StudioAvStorage::class)->checkedPath($workspace, $relative);
        $stream = @fopen($path, 'rb');
        if ($stream === false) {
            if (! file_exists($path)) {
                throw new StudioAvCheckpointCorrupt('Falta el archivo del checkpoint privado.');
            }
            throw new RuntimeException('Falta el archivo del checkpoint privado.');
        }
        $hash = hash_init('sha256');
        $size = 0;
        try {
            if (fstat($stream)['size'] !== $result['size']) {
                throw new StudioAvCheckpointCorrupt('El checkpoint privado cambió de tamaño.');
            }
            while (! feof($stream)) {
                if (microtime(true) >= $deadline) {
                    throw new StudioAvProcessFailed('phase_timeout');
                }
                $chunk = fread($stream, 1024 * 1024);
                if ($chunk === false || ($chunk === '' && ! feof($stream))) {
                    throw new RuntimeException('No se pudo leer el checkpoint completo.');
                }
                $size += strlen($chunk);
                if ($size > $result['size']) {
                    throw new StudioAvCheckpointCorrupt('El checkpoint privado creció durante su lectura.');
                }
                hash_update($hash, $chunk);
            }
            if (microtime(true) >= $deadline) {
                throw new StudioAvProcessFailed('phase_timeout');
            }
            if ($size !== $result['size'] || ! hash_equals($result['sha256'], hash_final($hash))) {
                throw new StudioAvCheckpointCorrupt('El contenido del checkpoint privado fue alterado.');
            }
        } finally {
            fclose($stream);
        }
    }

    public function snapshot(StudioAvLease $lease, ?float $deadline = null): array
    {
        return $this->locked($lease, fn () => $this->copySnapshot($lease, min($deadline ?? microtime(true) + 85, microtime(true) + 85)));
    }

    private function copySnapshot(StudioAvLease $lease, float $deadline): array
    {
        [$current, $workspace] = $this->context($lease);
        if (! in_array($current->phase, ['asset', 'font'], true)) {
            throw new RuntimeException('La fase no admite una copia de origen.');
        }
        $user = User::findOrFail($workspace->user_id);
        $brand = Brand::withoutUserScope()->where('user_id', $user->id)->findOrFail($workspace->brand_id);
        $source = $current->phase === 'asset'
            ? app(StudioAssets::class)->owned($user, $brand, $current->item)
            : app(StudioFonts::class)->owned($user, $brand, $current->item);
        $pinned = StudioAvLease::where('workspace_id', $workspace->id)->where('category', 'snapshot')
            ->where('phase', $current->phase)->where('item', $current->item)->whereIn('status', ['accepted', 'invalid', 'released'])
            ->whereNotNull('result')->orderBy('created_at')->orderBy('id')->first(['result'])?->result;
        $remaining = $deadline - microtime(true);
        if ($remaining <= 0) {
            throw new StudioAvProcessFailed('phase_timeout');
        }
        $reader = MediaChunkReader::for(new PublishMedia(id: $source->id, kind: 'file', mime: $source->mime ?? 'font/woff2',
            url: '', disk: $source->disk, path: $source->path), timeout: min(5, $remaining));
        if ($reader->size < 1 || $reader->size !== $source->size || $reader->size > min($current->reserved_bytes, 2 * 1024 ** 3)) {
            throw new RuntimeException('El origen no coincide con su tamaño o reserva.');
        }
        $name = $current->phase.'-'.$current->item.($current->phase === 'asset' ? '.bin' : '.woff2');
        $relative = 'imports/'.$current->id.'/'.$name;
        $destination = $this->path($workspace, $relative, true);
        $temporary = $this->path($workspace, $relative.'.part', true);
        $stream = @fopen($temporary, 'x+b');
        if ($stream === false) {
            throw new RuntimeException('La copia privada ya está asignada o no puede abrirse.');
        }
        $hash = hash_init('sha256');
        try {
            for ($offset = 0; $offset < $reader->size;) {
                $remaining = $deadline - microtime(true);
                if ($remaining <= 0) {
                    throw new StudioAvProcessFailed('phase_timeout');
                }
                $length = min(1024 * 1024, $reader->size - $offset);
                $chunk = $reader->chunk($offset, $length, min(5, $remaining));
                if (strlen($chunk) !== $length || fwrite($stream, $chunk) !== $length) {
                    throw new RuntimeException('La copia privada está incompleta.');
                }
                hash_update($hash, $chunk);
                $offset += $length;
            }
            if (! fflush($stream)) {
                throw new RuntimeException('No se pudo completar la copia privada.');
            }
            fclose($stream);
            $stream = null;
            $digest = hash_final($hash);
            if ($current->phase === 'font' && (! is_string($source->sha256) || ! hash_equals($source->sha256, $digest))) {
                throw new RuntimeException('La fuente normalizada cambió desde su validación.');
            }
            if ($pinned !== null && (($pinned['size'] ?? null) !== $reader->size || ! is_string($pinned['sha256'] ?? null)
                || ! hash_equals($pinned['sha256'], $digest))) {
                throw new StudioRenderFailure('El recurso de esta revisión cambió. Inicia una exportación nueva para no mezclar contenido.');
            }
            // A cancellation/deletion during transport must not publish the finished snapshot.
            $this->context($current);
            $destination = $this->path($workspace, $relative, true);
            $temporary = $this->path($workspace, $relative.'.part');
            if (! @link($temporary, $destination)) {
                throw new RuntimeException('No se pudo publicar la copia privada sin reemplazar archivos.');
            }

            return ['file' => $relative, 'namespace' => $current->id, 'size' => $reader->size, 'sha256' => $digest];
        } finally {
            if (is_resource($stream)) {
                fclose($stream);
            }
            $safe = $this->path($workspace, $relative.'.part', true);
            if (is_file($safe) && ! @unlink($safe)) {
                throw new RuntimeException('La limpieza de la copia privada queda pendiente.');
            }
        }
    }

    private function locked(StudioAvLease $lease, Closure $callback): mixed
    {
        $current = StudioAvLease::findOrFail($lease->id);

        return app(StudioAvStorage::class)->locked($current->workspace_id, $callback);
    }

    /** Reread journal identity; no caller-supplied path or model attributes are trusted. */
    private function context(StudioAvLease $lease, bool $accepted = false): array
    {
        $current = StudioAvLease::findOrFail($lease->id);
        $workspace = StudioAvWorkspace::withoutUserScope()->select(['id', 'user_id', 'brand_id', 'export_id', 'uuid', 'fingerprint'])->findOrFail($current->workspace_id);
        $export = DesignExport::withoutUserScope()->where('user_id', $workspace->user_id)->where('brand_id', $workspace->brand_id)->findOrFail($workspace->export_id);
        $state = $export->render_state;
        if (($accepted ? $current->status !== 'accepted' : ($current->status !== 'running' || ! $current->expires_at->isFuture()
            || ! in_array($export->status, ['pending', 'processing'], true)
            || ($state['lease'] ?? null) !== $current->id || ($state['generation'] ?? null) !== $current->generation))
            || ($state['workspace'] ?? null) !== $workspace->uuid || ($state['allocation_id'] ?? null) !== $workspace->id
            || ($state['fingerprint'] ?? null) !== $workspace->fingerprint) {
            throw new RuntimeException('El intento audiovisual ya no está activo.');
        }
        Design::withoutUserScope()->where('user_id', $workspace->user_id)->where('brand_id', $workspace->brand_id)->findOrFail($export->design_id);

        return [$current, $workspace, $export];
    }

    /** Only internally generated segments; lstat every ancestor before descending. */
    private function path(StudioAvWorkspace $workspace, string $relative, bool $create = false): string
    {
        $base = Storage::disk('local')->path('');
        if (is_link(rtrim($base, '/\\')) || ! is_dir($base) || ($base = realpath($base)) === false) {
            throw new RuntimeException('La raíz privada audiovisual no está disponible.');
        }
        $parts = explode('/', 'studio-exports/'.$workspace->user_id.'/'.$workspace->export_id.'/'.$workspace->uuid.'/'.$relative);
        $path = $base;
        $last = count($parts) - 1;
        foreach ($parts as $index => $part) {
            if (! preg_match('/^[a-zA-Z0-9][a-zA-Z0-9_.-]*$/D', $part) || $part === '.' || $part === '..') {
                throw new RuntimeException('Ruta audiovisual no válida.');
            }
            $path .= DIRECTORY_SEPARATOR.$part;
            clearstatcache(true, $path);
            if (is_link($path)) {
                throw new RuntimeException('La ruta privada contiene un enlace no permitido.');
            }
            if ($index < $last) {
                if (! file_exists($path) && $create && ! @mkdir($path, 0700) && ! is_dir($path)) {
                    throw new RuntimeException('No se pudo preparar el directorio privado.');
                }
                if (! is_dir($path)) {
                    throw new RuntimeException('Falta un directorio privado del trabajo.');
                }
            } elseif (file_exists($path) && ! is_file($path)) {
                throw new RuntimeException('El destino privado no es un archivo.');
            }
        }

        return $path;
    }
}
