<?php

namespace App\Services\Studio;

use App\Jobs\Studio\RenderStudioDesign;
use App\Models\Brand;
use App\Models\MediaAsset;
use App\Models\Studio\Design;
use App\Models\Studio\DesignExport;
use App\Models\User;
use App\Services\MediaUploadService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class StudioRenderer
{
    public function __construct(private readonly StudioService $studio, private readonly StudioExports $exports, private readonly StudioAssets $assets) {}

    public function export(User $user, Design $design, array $data): DesignExport
    {
        $design = $this->studio->owned($user, $design);
        $data = Validator::make($data, [
            'revision' => ['required', 'integer', 'min:1'],
            'format' => ['required', Rule::in(['png', 'jpeg', 'webp', 'svg', 'pdf'])],
            'pages' => ['required', 'array', 'min:1', 'max:100'], 'pages.*' => ['required', 'string', 'max:64', 'distinct'],
            'scale' => ['sometimes', 'numeric', 'min:0.1', 'max:4'], 'quality' => ['sometimes', 'numeric', 'min:0.1', 'max:1'], 'transparent' => ['sometimes', 'boolean'],
        ])->validate();
        $revision = $this->exports->revision($user, $design, (int) $data['revision']);
        if (! array_is_list($data['pages']) || array_diff($data['pages'], array_column($revision->document['pages'], 'id')) !== []) {
            throw ValidationException::withMessages(['pages' => ['Selecciona páginas existentes en la revisión indicada.']]);
        }
        $scale = (float) ($data['scale'] ?? 1);
        $width = (int) round($revision->document['width'] * $scale);
        $height = (int) round($revision->document['height'] * $scale);
        if ($width > 16000 || $height > 16000 || $width * $height * count($data['pages']) > 64000000) {
            throw ValidationException::withMessages(['scale' => ['El trabajo completo admite hasta 64 megapíxeles y 16000 píxeles por lado. Exporta menos páginas o reduce la escala.']]);
        }

        return DB::transaction(function () use ($user, $design, $data): DesignExport {
            $export = DesignExport::create(['user_id' => $user->id, 'brand_id' => $design->brand_id, 'design_id' => $design->id,
                'revision' => $data['revision'], 'format' => $data['format'], 'pages' => $data['pages'], 'status' => 'pending',
                'options' => ['scale' => (float) ($data['scale'] ?? 1), 'quality' => (float) ($data['quality'] ?? 0.94), 'transparent' => (bool) ($data['transparent'] ?? false)]]);
            RenderStudioDesign::dispatch($export->id)->afterCommit();

            return $export->refresh();
        });
    }

    public function render(int $exportId): void
    {
        // Claim once: a duplicate queue delivery never renders or creates media twice.
        if (DesignExport::withoutUserScope()->whereKey($exportId)->where('status', 'pending')->update(['status' => 'processing']) !== 1) {
            return;
        }
        $export = DesignExport::withoutUserScope()->findOrFail($exportId);
        $directory = 'studio-exports/'.$export->user_id.'/'.$export->id.'/'.Str::uuid();
        $disk = Storage::disk('local');
        $createdMedia = [];
        try {
            $user = User::findOrFail($export->user_id);
            $design = Design::withoutUserScope()->where('user_id', $user->id)->findOrFail($export->design_id);
            $revision = $this->exports->revision($user, $design, $export->revision);
            $brand = Brand::withoutUserScope()->where('user_id', $user->id)->findOrFail($export->brand_id);
            $assetIds = [];
            $walk = function (array $elements) use (&$walk, &$assetIds): void {
                foreach ($elements as $element) {
                    if (isset($element['asset_id'])) {
                        $assetIds[(int) $element['asset_id']] = true;
                    }
                    if ($element['type'] === 'group') {
                        $walk($element['elements']);
                    }
                }
            };
            foreach ($revision->document['pages'] as $page) {
                if (in_array($page['id'], $export->pages, true)) {
                    $walk($page['elements']);
                }
            }
            $assets = [];
            $bytes = 0;
            foreach ($this->assets->many($user, $brand, array_keys($assetIds)) as $asset) {
                $source = Storage::disk($asset->disk);
                $size = $source->size($asset->path);
                $bytes += $size;
                if ($bytes > 64000000) {
                    throw new StudioRenderFailure('Los recursos del trabajo superan 64 MB. Reduce su tamaño.');
                }
                $content = $source->get($asset->path);
                if (! is_string($content) || strlen($content) !== $size) {
                    throw new StudioRenderFailure('No se pudo leer un recurso del diseño.');
                }
                $assets[] = $asset->only(['id', 'kind', 'mime', 'width', 'height', 'duration']) + ['preview_url' => 'data:'.$asset->mime.';base64,'.base64_encode($content)];
            }
            $disk->makeDirectory($directory);
            $input = ['document' => $revision->document, 'assets' => $assets, 'format' => $export->format, 'pageIds' => $export->pages] + $export->options;
            $result = Process::path(base_path())->timeout(100)->input(json_encode($input, JSON_THROW_ON_ERROR))->run([
                config('studio.node', 'node'), base_path('scripts/studio/render.mjs'), $disk->path($directory),
            ]);
            if (! $result->successful()) {
                throw new StudioRenderFailure('El renderizador no pudo completar el archivo. Comprueba Node, Chromium y npm run build en el servidor.');
            }
            $manifest = json_decode($result->output(), true, 32, JSON_THROW_ON_ERROR);
            $files = $manifest['files'] ?? [];
            $expected = $export->format === 'pdf' ? 1 : count($export->pages);
            if (count($files) !== $expected) {
                throw new StudioRenderFailure('El renderizador devolvió un número de archivos incorrecto.');
            }
            $mediaIds = [];
            $paths = [];
            $extension = ['png' => 'png', 'jpeg' => 'jpg', 'webp' => 'webp', 'svg' => 'svg', 'pdf' => 'pdf'][$export->format];
            foreach ($files as $index => $file) {
                $name = sprintf('page-%03d.%s', $index + 1, $extension);
                if (($file['name'] ?? null) !== $name) {
                    throw new StudioRenderFailure('Nombre de archivo de render no válido.');
                }
                $path = $directory.'/'.$name;
                if (! $disk->exists($path) || $disk->size($path) < 1 || $disk->size($path) > 64000000) {
                    throw new StudioRenderFailure('El archivo generado falta o supera el tamaño permitido.');
                }
                $paths[] = $path;
                if (in_array($export->format, ['png', 'jpeg', 'webp'], true)) {
                    $mime = ['png' => 'image/png', 'jpeg' => 'image/jpeg', 'webp' => 'image/webp'][$export->format];
                    $uploads = app(MediaUploadService::class);
                    // A server-generated object is written directly, without opening an unused S3 multipart session.
                    $media = new MediaAsset(['disk' => config('filesystems.media', 'local'), 'path' => 'media/'.$user->id.'/'.Str::lower((string) Str::ulid()).'.'.$extension,
                        'original_name' => Str::limit($revision->name.'-'.$name, 200, ''), 'mime' => $mime, 'kind' => 'image',
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
                    $media = $uploads->finalize($media);
                    $scale = (float) ($export->options['scale'] ?? 1);
                    if (! $media->isReady() || $media->width !== (int) round($revision->document['width'] * $scale) || $media->height !== (int) round($revision->document['height'] * $scale)) {
                        throw new StudioRenderFailure('La imagen generada no coincide con el formato y las dimensiones de la revisión.');
                    }
                    $mediaIds[] = $media->id;
                }
            }
            $download = $paths[0];
            if (count($paths) > 1) {
                $download = $directory.'/pages.zip';
                $zip = new \ZipArchive;
                if ($zip->open($disk->path($download), \ZipArchive::CREATE | \ZipArchive::EXCL) !== true) {
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
            DB::transaction(function () use ($user, $brand, $mediaIds, $export, $download): void {
                foreach ($mediaIds as $id) {
                    $this->assets->attach($user, $brand, $id);
                }
                $export->forceFill(['status' => 'ready', 'disk' => 'local', 'path' => $download, 'media_ids' => $mediaIds, 'error' => null])->save();
            });
        } catch (\Throwable $e) {
            $export->forceFill(['status' => 'failed', 'error' => $e instanceof StudioRenderFailure ? Str::limit($e->getMessage(), 350) : 'No se pudo renderizar esta revisión. Revisa sus recursos y la configuración del renderizador.'])->save();
            foreach ($createdMedia as $media) {
                try {
                    app(MediaUploadService::class)->delete($media);
                } catch (\Throwable) { /* Preserve any object concurrently attached to a real post. */
                }
            }
            // Only the unique directory created for this attempt is removed; never a caller-supplied path.
            $disk->deleteDirectory($directory);
        }
    }
}
