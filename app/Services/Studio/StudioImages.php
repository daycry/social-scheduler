<?php

namespace App\Services\Studio;

use App\Jobs\Studio\ProcessStudioImage;
use App\Models\Brand;
use App\Models\MediaAsset;
use App\Models\Studio\StudioImageJob;
use App\Models\User;
use App\Services\Media\ImageDownscaler;
use App\Services\MediaUploadService;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

class StudioImages
{
    public function __construct(private readonly StudioAssets $assets) {}

    public function create(User $user, Brand $brand, array $data): StudioImageJob
    {
        $this->assets->assertBrand($user, $brand);
        $data = Validator::make($data, ['media_id' => ['required', 'integer', 'min:1'], 'operation' => ['required', 'in:remove_background']])->validate();
        $source = $this->assets->owned($user, $brand, (int) $data['media_id']);
        if ($source->kind !== 'image' || ! in_array($source->mime, ['image/png', 'image/jpeg', 'image/webp'], true)
            || $source->width < 1 || $source->height < 1 || $source->width * $source->height > (int) config('studio.images.max_pixels', 16000000)) {
            throw ValidationException::withMessages(['media_id' => ['Selecciona una imagen PNG, JPEG o WebP de hasta 16 megapíxeles.']]);
        }
        $model = (string) config('studio.images.model_path');
        $sha = (string) config('studio.images.model_sha256');
        if (! is_file($model) || preg_match('/^[a-f0-9]{64}$/D', $sha) !== 1) {
            throw ValidationException::withMessages(['operation' => ['El modelo de eliminación de fondo no está instalado o verificado. Configura el runtime del estudio.']]);
        }
        $inputSha = $this->inputHash($source);

        return DB::transaction(function () use ($user, $brand, $source, $data, $model, $sha, $inputSha): StudioImageJob {
            Brand::withoutUserScope()->where('user_id', $user->id)->lockForUpdate()->findOrFail($brand->id);
            $existing = StudioImageJob::withoutUserScope()->where('user_id', $user->id)->where('brand_id', $brand->id)
                ->where('source_media_id', $source->id)->where('operation', $data['operation'])->where('model_sha256', $sha)
                ->where('input_sha256', $inputSha)->whereIn('status', ['queued', 'processing'])->first();
            if ($existing) {
                return $existing;
            }
            if (StudioImageJob::withoutUserScope()->where('user_id', $user->id)->whereIn('status', ['queued', 'processing'])->count() >= 10) {
                throw ValidationException::withMessages(['operation' => ['Ya hay diez imágenes pendientes. Espera a que terminen antes de iniciar otra.']]);
            }
            $job = StudioImageJob::create(['user_id' => $user->id, 'brand_id' => $brand->id, 'source_media_id' => $source->id,
                'operation' => $data['operation'], 'status' => 'queued', 'model' => 'birefnet-general-lite', 'model_path' => $model,
                'model_sha256' => $sha, 'input_sha256' => $inputSha]);
            ProcessStudioImage::dispatch($job->id)->afterCommit();

            return $job;
        });
    }

    public function present(User $user, Brand $brand, StudioImageJob $job, bool $api = false): array
    {
        $this->assets->assertBrand($user, $brand);
        $job = StudioImageJob::withoutUserScope()->where('user_id', $user->id)->where('brand_id', $brand->id)->findOrFail($job->id);
        $output = null;
        $effective = [];
        if ($job->status === 'ready') {
            try {
                $media = $this->assets->owned($user, $brand, (int) $job->output_media_id);
                abort_unless(Storage::disk($media->disk)->exists($media->path), 404);
                $output = $this->assets->present($brand, $media);
                if ($api) {
                    $output['preview_url'] = route('api.studio.assets.file', ['brand' => $brand->id, 'media' => $media->id], absolute: false);
                }
            } catch (ModelNotFoundException|NotFoundHttpException) {
                $effective = ['status' => 'failed', 'error_code' => 'output_removed', 'error' => 'La imagen generada se ha eliminado. Inicia un nuevo procesamiento.'];
            }
        }

        return array_replace($job->only(['id', 'brand_id', 'source_media_id', 'operation', 'status', 'model', 'model_sha256', 'error', 'error_code', 'created_at', 'updated_at']),
            $effective, ['output_asset' => $output]);
    }

    public function process(int $id): void
    {
        if (StudioImageJob::withoutUserScope()->whereKey($id)->where('status', 'queued')->update(['status' => 'processing']) !== 1) {
            return;
        }
        $job = StudioImageJob::withoutUserScope()->find($id);
        if (! $job) {
            return;
        }
        $disk = Storage::disk('local');
        $directory = 'studio-image-jobs/'.Str::uuid();
        $media = null;
        $code = 'processing_failed';
        try {
            $user = User::findOrFail($job->user_id);
            $brand = Brand::withoutUserScope()->where('user_id', $user->id)->findOrFail($job->brand_id);
            $code = 'source_unavailable';
            $source = $this->assets->owned($user, $brand, (int) $job->source_media_id);
            $disk->makeDirectory($directory);
            $input = $disk->path($directory.'/input');
            $output = $disk->path($directory.'/result.png');
            $stream = Storage::disk($source->disk)->readStream($source->path);
            if (! is_resource($stream)) {
                throw new \RuntimeException;
            }
            $target = fopen($input, 'xb');
            try {
                if (! is_resource($target)) {
                    throw new \RuntimeException;
                }
                $bytes = stream_copy_to_stream($stream, $target, (int) config('studio.images.max_bytes', 30 * 1024 * 1024) + 1);
                if ($bytes === false || $bytes < 1 || $bytes > (int) config('studio.images.max_bytes', 30 * 1024 * 1024)) {
                    throw new \RuntimeException;
                }
            } finally {
                fclose($stream);
                if (is_resource($target)) {
                    fclose($target);
                }
            }
            $code = 'source_changed';
            if (! hash_equals($job->input_sha256, hash_file('sha256', $input))) {
                throw new \RuntimeException;
            }
            $code = 'runtime_unavailable';
            $result = Process::path(base_path())->timeout((int) config('studio.images.timeout', 85))->input(json_encode([
                'input' => $input, 'output' => $output, 'model' => $job->model_path, 'model_sha256' => $job->model_sha256,
                'max_pixels' => (int) config('studio.images.max_pixels', 16000000), 'max_bytes' => (int) config('studio.images.max_bytes', 30 * 1024 * 1024),
            ], JSON_THROW_ON_ERROR))->run([config('studio.python', 'python'), base_path('scripts/studio/process-image.py')]);
            $code = 'invalid_output';
            $manifest = json_decode($result->output(), true, 16, JSON_THROW_ON_ERROR);
            if (! $result->successful() || ($manifest['ok'] ?? null) !== true) {
                $candidate = $manifest['error_code'] ?? null;
                $code = is_string($candidate) && array_key_exists($candidate, $this->errors()) ? $candidate : 'processing_failed';
                throw new \RuntimeException;
            }
            if (($manifest['format'] ?? null) !== 'png' || ($manifest['model_sha256'] ?? null) !== $job->model_sha256
                || ! is_int($manifest['width'] ?? null) || ! is_int($manifest['height'] ?? null)
                || ! is_file($output) || is_link($output) || filesize($output) < 1 || filesize($output) > (int) config('studio.images.max_bytes', 30 * 1024 * 1024)) {
                throw new \RuntimeException;
            }
            $dimensions = @getimagesize($output);
            $orientation = $manifest['source_orientation'] ?? ImageDownscaler::exifOrientation((string) file_get_contents($input, false, null, 0, 262144));
            if (! is_int($orientation) || $orientation < 1 || $orientation > 8) {
                throw new \RuntimeException;
            }
            $swapped = ImageDownscaler::swapsAxes($orientation);
            $expectedWidth = $swapped ? $source->height : $source->width;
            $expectedHeight = $swapped ? $source->width : $source->height;
            if (! $dimensions || $dimensions[2] !== IMAGETYPE_PNG || $dimensions[0] !== $manifest['width'] || $dimensions[1] !== $manifest['height']
                || $dimensions[0] !== $expectedWidth || $dimensions[1] !== $expectedHeight || (int) config('studio.images.max_pixels', 16000000) < $dimensions[0] * $dimensions[1]) {
                throw new \RuntimeException;
            }
            DB::transaction(function () use ($job, $user, $brand, $source, $output, $manifest, &$media, &$code): void {
                Brand::withoutUserScope()->where('user_id', $user->id)->lockForUpdate()->findOrFail($brand->id);
                $locked = StudioImageJob::withoutUserScope()->where('user_id', $user->id)->where('brand_id', $brand->id)->where('status', 'processing')->lockForUpdate()->findOrFail($job->id);
                $current = MediaAsset::withoutUserScope()->where('user_id', $user->id)->lockForUpdate()->findOrFail($source->id);
                $this->assets->owned($user, $brand, $source->id);
                $code = 'source_changed';
                if (! hash_equals($job->input_sha256, $this->inputHash($current))) {
                    throw new \RuntimeException;
                }
                $code = 'invalid_output';
                $media = new MediaAsset(['disk' => config('filesystems.media', 'local'), 'path' => 'media/'.$user->id.'/'.Str::lower((string) Str::ulid()).'.png',
                    'original_name' => 'sin-fondo.png', 'mime' => 'image/png', 'kind' => 'image', 'declared_size' => filesize($output), 'status' => MediaAsset::STATUS_PENDING]);
                $media->user_id = $user->id;
                $media->save();
                $stream = fopen($output, 'rb');
                try {
                    if (! is_resource($stream) || ! Storage::disk($media->disk)->put($media->path, $stream)) {
                        throw new \RuntimeException;
                    }
                } finally {
                    if (is_resource($stream)) {
                        fclose($stream);
                    }
                }
                $media = app(MediaUploadService::class)->finalize($media);
                if (! $media->isReady() || $media->mime !== 'image/png' || $media->width !== $manifest['width'] || $media->height !== $manifest['height']) {
                    throw new \RuntimeException;
                }
                $this->assets->attach($user, $brand, $media->id);
                $locked->forceFill(['status' => 'ready', 'output_media_id' => $media->id, 'error_code' => null, 'error' => null])->save();
            });
        } catch (\Throwable) {
            StudioImageJob::withoutUserScope()->whereKey($id)->where('status', 'processing')->update(['status' => 'failed', 'error_code' => $code, 'error' => $this->errors()[$code]]);
            if ($media) {
                try {
                    app(MediaUploadService::class)->delete($media);
                } catch (\Throwable) { // Preserve media concurrently attached to a real publication.
                }
            }
        } finally {
            $disk->deleteDirectory($directory);
        }
    }

    private function errors(): array
    {
        return [
            'processing_failed' => 'No se pudo procesar la imagen. Vuelve a intentarlo.',
            'runtime_unavailable' => 'El runtime de imágenes no está disponible. Comprueba su instalación.',
            'model_unavailable' => 'El modelo no está instalado. Comprueba la configuración del estudio.',
            'model_checksum' => 'El modelo no coincide con la versión verificada. Vuelve a instalarlo.',
            'model_incompatible' => 'El modelo no es compatible con el runtime instalado.',
            'invalid_output' => 'La imagen generada no superó la validación de formato y dimensiones.',
            'invalid_image' => 'La imagen original no es compatible o supera los límites permitidos.',
            'output_too_large' => 'La imagen generada supera el tamaño permitido.',
            'source_unavailable' => 'La imagen original ya no está disponible en esta marca.',
            'source_changed' => 'La imagen original ha cambiado. Inicia un nuevo procesamiento.',
        ];
    }

    private function inputHash(MediaAsset $source): string
    {
        $disk = Storage::disk($source->disk);
        $stream = $disk->readStream($source->path);
        if (! is_resource($stream)) {
            throw ValidationException::withMessages(['media_id' => ['No se pudo leer la imagen original.']]);
        }
        try {
            $hash = hash_init('sha256');
            $bytes = 0;
            while (! feof($stream)) {
                $chunk = fread($stream, 1024 * 1024);
                if ($chunk === false) {
                    throw ValidationException::withMessages(['media_id' => ['No se pudo leer la imagen original.']]);
                }
                $bytes += strlen($chunk);
                if ($bytes > (int) config('studio.images.max_bytes', 30 * 1024 * 1024)) {
                    throw ValidationException::withMessages(['media_id' => ['La imagen supera el máximo de 30 MiB.']]);
                }
                hash_update($hash, $chunk);
            }
            if ($bytes < 1) {
                throw ValidationException::withMessages(['media_id' => ['La imagen está vacía.']]);
            }

            return hash_final($hash);
        } finally {
            fclose($stream);
        }
    }
}
