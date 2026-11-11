<?php

namespace App\Services\Studio;

use App\Models\Studio\DesignExport;
use App\Models\Studio\StudioAvLease;
use App\Models\Studio\StudioAvWorkspace;
use Closure;
use FilesystemIterator;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

class StudioAvStorage
{
    /** Cancellation cannot acquire the mutex held by the native process it must interrupt. */
    public function signalCancellation(DesignExport $export): bool
    {
        $id = $export->render_state['cancelled_lease'] ?? null;
        if (! is_string($id) || ! preg_match('/^[a-f0-9]{8}(-[a-f0-9]{4}){3}-[a-f0-9]{12}$/D', $id)) {
            return false;
        }
        $workspace = StudioAvWorkspace::withoutUserScope()->where('export_id', $export->id)->where('user_id', $export->user_id)
            ->where('brand_id', $export->brand_id)->where('uuid', $export->render_state['workspace'])->first();
        if (! $workspace || ! StudioAvLease::whereKey($id)->where('workspace_id', $workspace->id)->where('generation', $export->render_state['generation'])->exists()) {
            return false;
        }
        $manifest = $this->checkedPath($workspace, 'work/'.$id.'.json');
        if (! is_file($manifest)) {
            return false;
        }
        $flag = $this->checkedPath($workspace, 'work/'.$id.'.cancel');
        if (is_file($flag)) {
            return true;
        }
        $stream = @fopen($flag, 'x+b');
        if ($stream === false) {
            if (! is_file($manifest) || is_file($flag)) {
                return false; // The worker can finish and remove its manifest concurrently.
            }
            throw new RuntimeException('No se pudo escribir la señal privada de cancelación.');
        }
        fclose($stream);

        return true;
    }

    public function manifest(StudioAvWorkspace $workspace, string $leaseId, array $input): string
    {
        $this->assertHeld($workspace);
        if (! preg_match('/^[a-f0-9]{8}(-[a-f0-9]{4}){3}-[a-f0-9]{12}$/D', $leaseId)) {
            throw new RuntimeException('El lease del manifiesto no es válido.');
        }
        $json = json_encode($input, JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION);
        if (strlen($json) > 8 * 1024 ** 2) {
            throw new RuntimeException('El manifiesto audiovisual supera 8MiB.');
        }
        $parts = ['studio-exports', (string) $workspace->user_id, (string) $workspace->export_id, $workspace->uuid, 'work', $leaseId.'.json'];
        $file = $this->resolve($parts, true);
        $stream = @fopen($file, 'x+b');
        if ($stream === false) {
            throw new RuntimeException('El manifiesto ya existe o no puede escribirse.');
        }
        try {
            if (fwrite($stream, $json) !== strlen($json) || ! fflush($stream)) {
                throw new RuntimeException('El manifiesto no se pudo completar.');
            }
        } finally {
            fclose($stream);
        }

        return dirname(dirname($file));
    }

    public function removeManifest(StudioAvWorkspace $workspace, string $leaseId): void
    {
        $this->assertHeld($workspace);
        $count = 0;
        foreach (['json', 'cancel'] as $extension) {
            $this->remove($this->workspacePath($workspace, 'work/'.$leaseId.'.'.$extension), microtime(true) + 3, $count);
        }
    }

    private array $held = [];

    public function locked(int $workspaceId, Closure $callback): mixed
    {
        $workspace = StudioAvWorkspace::withoutUserScope()->select(['id', 'user_id', 'brand_id', 'export_id', 'uuid', 'active_until'])->findOrFail($workspaceId);
        $file = $this->resolve(['studio-av-locks', $workspace->uuid.'.lock'], true);
        $stream = @fopen($file, 'c+b');
        if ($stream === false) {
            throw new RuntimeException('No se pudo abrir el bloqueo privado del trabajo.');
        }
        $acquired = false;
        try {
            if (! flock($stream, LOCK_EX | LOCK_NB)) {
                throw new StudioAvWorkspaceBusy('El espacio audiovisual está en uso; vuelve a intentarlo.');
            }
            $acquired = true;
            $this->held[$workspaceId] = true;

            return $callback($workspace);
        } finally {
            if ($acquired) {
                unset($this->held[$workspaceId]);
                flock($stream, LOCK_UN);
            }
            fclose($stream);
        }
    }

    /** Called only while holding this workspace's filesystem lock. */
    public function removeLease(StudioAvWorkspace $workspace, StudioAvLease $lease): void
    {
        $this->assertHeld($workspace);
        if ($lease->workspace_id !== $workspace->id || ! in_array($lease->status, ['abandoned', 'failed', 'invalid'], true) || $lease->expires_at->isFuture()) {
            throw new RuntimeException('La asignación todavía no admite limpieza.');
        }
        $deadline = microtime(true) + 30;
        $count = 0;
        foreach (['imports', 'audio-sources', 'segments', 'outputs'] as $folder) {
            $this->remove($this->workspacePath($workspace, $folder.'/'.$lease->id), $deadline, $count);
        }
        foreach (['json', 'cancel', 'json.part'] as $extension) {
            $this->remove($this->workspacePath($workspace, 'work/'.$lease->id.'.'.$extension), $deadline, $count);
        }
        $root = $this->workspacePath($workspace);
        if (is_dir($root)) {
            $pattern = '/^'.preg_quote($lease->id, '/').'-[a-f0-9]{8}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{12}\.(video|audio)\.txt$/D';
            foreach (new FilesystemIterator($root, FilesystemIterator::SKIP_DOTS) as $entry) {
                if (++$count > 65536 || microtime(true) >= $deadline) {
                    throw new RuntimeException('La limpieza parcial continúa pendiente; vuelve a intentarlo.');
                }
                if (preg_match($pattern, $entry->getFilename())) {
                    $this->remove($entry->getPathname(), $deadline, $count);
                }
            }
        }
        if ($lease->status === 'invalid' && in_array($lease->phase, ['asset', 'font'], true)) {
            $relative = $lease->phase === 'asset' ? 'assets/'.$lease->item.'.bin' : 'fonts/'.$lease->item.'.woff2';
            $this->remove($this->workspacePath($workspace, $relative), $deadline, $count);
        }
    }

    /** The owner/export may already be gone; use only the surviving allocation journal. */
    public function removeWorkspace(StudioAvWorkspace $workspace): void
    {
        $this->assertHeld($workspace);
        $count = 0;
        $this->remove($this->workspacePath($workspace), microtime(true) + 30, $count);
    }

    private function workspacePath(StudioAvWorkspace $workspace, string $relative = ''): string
    {
        return $this->resolve(array_merge(['studio-exports', (string) $workspace->user_id, (string) $workspace->export_id, $workspace->uuid],
            $relative === '' ? [] : explode('/', $relative)));
    }

    /** Validate every existing ancestor even when the expected checkpoint itself has disappeared. */
    public function checkedPath(StudioAvWorkspace $workspace, string $relative): string
    {
        return $this->workspacePath($workspace, $relative);
    }

    private function resolve(array $parts, bool $create = false): string
    {
        $base = rtrim(Storage::disk('local')->path(''), '/\\');
        if (is_link($base) || ! is_dir($base) || ($path = realpath($base)) === false) {
            throw new RuntimeException('La raíz privada audiovisual no está disponible.');
        }
        foreach ($parts as $index => $part) {
            if (! is_string($part) || ! preg_match('/^[a-zA-Z0-9][a-zA-Z0-9_.-]*$/D', $part) || in_array($part, ['.', '..'], true)) {
                throw new RuntimeException('La ruta del journal audiovisual no es válida.');
            }
            $path .= DIRECTORY_SEPARATOR.$part;
            clearstatcache(true, $path);
            if (is_link($path)) {
                throw new RuntimeException('La limpieza no admite enlaces de archivos o directorios.');
            }
            if ($index < count($parts) - 1) {
                if (! file_exists($path) && $create && ! @mkdir($path, 0700) && ! is_dir($path)) {
                    throw new RuntimeException('No se pudo crear el directorio de bloqueo privado.');
                }
                if (file_exists($path) && ! is_dir($path)) {
                    throw new RuntimeException('Un componente del journal no es un directorio.');
                }
                if (is_dir($path) && (! is_readable($path) || (PHP_OS_FAMILY !== 'Windows' && ! is_executable($path)))) {
                    throw new RuntimeException('No se puede comprobar el contenido del directorio privado.');
                }
            }
        }

        return $path;
    }

    private function assertHeld(StudioAvWorkspace $workspace): void
    {
        if (! ($this->held[$workspace->id] ?? false)) {
            throw new StudioAvWorkspaceBusy('La limpieza necesita el bloqueo del espacio audiovisual.');
        }
    }

    private function remove(string $path, float $deadline, int &$count, int $depth = 0): void
    {
        if (++$count > 65536 || $depth > 32 || microtime(true) >= $deadline) {
            throw new RuntimeException('La limpieza parcial continúa pendiente; vuelve a intentarlo.');
        }
        clearstatcache(true, $path);
        if (is_link($path)) {
            throw new RuntimeException('La limpieza no admite enlaces de archivos o directorios.');
        }
        $stat = @lstat($path);
        if ($stat === false) {
            if (file_exists($path)) {
                throw new RuntimeException('No se pudo consultar un archivo privado.');
            }

            return;
        }
        if (is_dir($path)) {
            foreach (new FilesystemIterator($path, FilesystemIterator::SKIP_DOTS) as $entry) {
                $this->remove($entry->getPathname(), $deadline, $count, $depth + 1);
            }
            if (! @rmdir($path)) {
                throw new RuntimeException('No se pudo terminar la limpieza del directorio privado.');
            }
        } elseif (is_file($path)) {
            if (! @unlink($path)) {
                throw new RuntimeException('No se pudo retirar un archivo privado.');
            }
        } else {
            throw new RuntimeException('El journal contiene un tipo de archivo no compatible.');
        }
    }
}
