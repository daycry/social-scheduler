<?php

namespace App\Services\Studio;

use App\Models\Brand;
use App\Models\MediaAsset;
use App\Models\Studio\DesignExport;
use App\Models\Studio\DesignRevision;
use App\Models\User;
use App\Services\MediaUploadService;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/** Valida, empaqueta y descarta archivos producidos por el render estático del Estudio. */
final class StudioExportFiles
{
    public function __construct(private readonly MediaUploadService $uploads, private readonly StudioPptxExporter $presentations, private readonly StudioWatermark $watermarks) {}

    /**
     * Valida cada fichero generado y, en los formatos de imagen, lo guarda como media de la biblioteca.
     *
     * @param  array<string, mixed>  $document  el documento que se renderizó (su ancho y alto por la escala son las dimensiones esperadas)
     * @param  list<array<string, mixed>>  $files
     * @param  list<MediaAsset>  $createdMedia
     * @return array{0: list<string>, 1: list<int>} rutas relativas del disco `local` e ids de media creados
     */
    public function storeFiles(DesignExport $export, User $user, string $designName, array $document, array $files, string $directory, array &$createdMedia): array
    {
        $disk = Storage::disk('local');
        $extension = ['png' => 'png', 'jpeg' => 'jpg', 'webp' => 'webp', 'svg' => 'svg', 'pdf' => 'pdf', 'pptx' => 'png'][$export->format];
        $paths = [];
        $mediaIds = [];
        foreach ($files as $index => $file) {
            $name = sprintf('page-%03d.%s', $index + 1, $extension);
            // Con marca de agua Chromium entrega un PNG sin pérdida y la codificación final (JPEG o WebP) se hace una sola vez al superponerla.
            $rendered = isset($export->options['watermark']) ? sprintf('page-%03d.png', $index + 1) : $name;
            if (($file['name'] ?? null) !== $rendered) {
                throw new StudioRenderFailure('Nombre de archivo de render no válido.');
            }
            $path = $directory.'/'.$name;
            $limit = (int) config('studio.export.max_file_bytes', 64000000);
            // El PNG intermedio sin pérdida de una exportación con marca de agua no lleva tope de bytes: lo acota el tope de píxeles (`studio.watermark.max_pixels`). El límite se mide sobre el fichero final.
            if (! $disk->exists($directory.'/'.$rendered) || $disk->size($directory.'/'.$rendered) < 1 || ($rendered === $name && $disk->size($path) > $limit)) {
                throw new StudioRenderFailure('El archivo generado falta o supera el tamaño permitido.');
            }
            $paths[] = $path;
            if (in_array($export->format, StudioWatermark::FORMATS, true)) {
                if (isset($export->options['watermark'])) {
                    $this->watermarks->apply($user, Brand::ownedBy($user->id)->findOrFail($export->brand_id), $disk->path($directory.'/'.$rendered), $disk->path($path), $export->format, $export->options['watermark'], (float) ($export->options['quality'] ?? 0.94));
                    if ($rendered !== $name) {
                        $disk->delete($directory.'/'.$rendered);
                    }
                    if ($disk->size($path) > $limit) {
                        throw new StudioRenderFailure('El archivo generado supera el tamaño permitido.');
                    }
                }
                $mediaIds[] = $this->importImage($export, $user, $designName, $document, $path, $name, $extension, $createdMedia);
            }
        }

        return [$paths, $mediaIds];
    }

    /**
     * Sube una imagen renderizada a la biblioteca y comprueba que tiene las dimensiones de la revision.
     *
     * @param  list<MediaAsset>  $createdMedia
     */
    private function importImage(DesignExport $export, User $user, string $designName, array $document, string $path, string $name, string $extension, array &$createdMedia): int
    {
        $disk = Storage::disk('local');
        $mime = ['png' => 'image/png', 'jpeg' => 'image/jpeg', 'webp' => 'image/webp'][$export->format];
        // A server-generated object is written directly, without opening an unused S3 multipart session.
        $media = new MediaAsset(['disk' => config('filesystems.media', 'local'), 'path' => 'media/'.$user->id.'/'.Str::lower((string) Str::ulid()).'.'.$extension,
            'original_name' => Str::limit($designName.'-'.$name, 200, ''), 'mime' => $mime, 'kind' => 'image',
            'declared_size' => $disk->size($path), 'status' => MediaAsset::STATUS_PENDING]);
        $media->user_id = $user->id;
        $media->save();
        $createdMedia[] = $media;
        $stream = $disk->readStream($path);
        try {
            if (! is_resource($stream) || ! Storage::disk($media->disk)->put($media->path, $stream)) {
                throw new StudioRenderFailure('No se pudo guardar la imagen renderizada.');
            }
        } finally {
            if (is_resource($stream)) {
                fclose($stream);
            }
        }
        $media = $this->uploads->finalize($media);
        $scale = (float) ($export->options['scale'] ?? 1);
        if (! $media->isReady() || $media->width !== (int) round($document['width'] * $scale) || $media->height !== (int) round($document['height'] * $scale)) {
            throw new StudioRenderFailure('La imagen generada no coincide con el formato y las dimensiones de la revisión.');
        }

        return $media->id;
    }

    /**
     * Sirve una página, un PPTX (borra los PNG intermedios) o un ZIP de varias páginas.
     *
     * @param  list<string>  $paths  En el mismo orden que las páginas de la exportación.
     */
    public function packageDownload(DesignExport $export, DesignRevision $revision, array $paths, string $directory): string
    {
        $disk = Storage::disk('local');
        if ($export->format === 'pptx') {
            $pageNames = array_column($revision->document['pages'], 'name', 'id');
            $download = $directory.'/design.pptx';
            $this->presentations->build($disk->path($download), array_map(fn (string $path, string $id) => ['path' => $disk->path($path), 'name' => $pageNames[$id] ?? ''], $paths, $export->pages),
                (int) $revision->document['width'], (int) $revision->document['height'], $revision->name);
            // Solo queda el PPTX: las imagenes intermedias no se sirven y se borran.
            $disk->delete($paths);

            return $download;
        }
        if (count($paths) > 1) {
            $download = $directory.'/pages.zip';
            class_exists(\ZipArchive::class) ? $this->zipWithExtension($disk->path($download), $disk, $paths) : $this->zipWithPython($disk, $download, $directory, $paths);

            return $download;
        }

        return $paths[0];
    }

    /** @param  list<string>  $paths */
    private function zipWithExtension(string $target, Filesystem $disk, array $paths): void
    {
        $zip = new \ZipArchive;
        if ($zip->open($target, \ZipArchive::CREATE | \ZipArchive::EXCL) !== true) {
            throw new StudioRenderFailure('No se pudo crear el archivo de páginas.');
        }
        try {
            foreach ($paths as $path) {
                $zip->addFile($disk->path($path), basename($path));
            }
        } finally {
            $zip->close();
        }
    }

    /** @param  list<string>  $paths */
    private function zipWithPython(Filesystem $disk, string $download, string $directory, array $paths): void
    {
        try {
            $archive = Process::path(base_path())->timeout(30)->run([config('studio.python', 'python3'), '-I', base_path('scripts/studio/archive.py'), $disk->path($directory), ...array_map('basename', $paths)]);
        } catch (\Throwable $e) {
            throw new StudioRenderFailure('El empaquetador de páginas no está disponible. Configura Python o la extensión ZIP de PHP.');
        }
        if (! $archive->successful() || ! $disk->exists($download)) {
            throw new StudioRenderFailure('No se pudo empaquetar el archivo de páginas. Revisa Python y los límites de almacenamiento.');
        }
    }

    /** @param  list<MediaAsset>  $createdMedia */
    public function discard(array $createdMedia, string $directory): void
    {
        foreach ($createdMedia as $media) {
            try {
                $this->uploads->delete($media);
            } catch (\Throwable) { /* Preserve any object concurrently attached to a real post. */
            }
        }
        // El renderer pasa únicamente el directorio único que generó para este intento.
        Storage::disk('local')->deleteDirectory($directory);
    }
}
