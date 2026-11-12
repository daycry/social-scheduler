<?php

namespace App\Services\Studio;

use App\Jobs\Studio\RunStudioAiJob;
use App\Models\Brand;
use App\Models\MediaAsset;
use App\Models\Studio\StudioAiJob;
use App\Models\Studio\StudioAsset;
use App\Models\User;
use App\Services\JobFailureNotifier;
use App\Services\MediaUploadService;
use App\Services\Studio\Ai\AiImageRequest;
use App\Services\Studio\Ai\AiProviderException;
use App\Services\Studio\Ai\AiProviders;
use App\Services\Studio\Ai\ImageCanvas;
use App\Services\Studio\Ai\ImageProvider;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Sleep;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * IA creativa (T-14): generar y editar imagenes con un proveedor configurable (OpenAI Images o Google), como trabajos asincronos
 * con estados queued → processing → ready | failed. El resultado se guarda como recurso de la marca (con proveedor, modelo y prompt).
 * Un unico servicio para web, API y MCP.
 */
class StudioAi
{
    public const KINDS = ['generate', 'edit', 'outpaint', 'variation'];

    public const ASPECTS = ['1:1', '3:2', '2:3', '16:9', '9:16'];

    public const STYLES = [
        'natural' => '', 'photo' => 'Fotografía realista, iluminación natural.', 'illustration' => 'Ilustración digital con trazos limpios.',
        '3d' => 'Render 3D con materiales suaves.', 'minimal' => 'Estilo minimalista, composición limpia y mucho espacio libre.',
        'watercolor' => 'Acuarela con texturas de papel.',
    ];

    public function __construct(private readonly StudioAssets $assets, private readonly AiProviders $providers) {}

    /** Estado de la funcion para la UI y la API: nunca incluye claves. */
    public function status(User $user): array
    {
        $providers = [];
        foreach ($this->providers->all() as $id => $provider) {
            $limit = $this->providers->limit($user, $id);
            $used = $this->providers->used($user, $id);
            $providers[] = ['id' => $id, 'label' => $provider->label(), 'model' => $provider->model(), 'configured' => $provider->configured(),
                'capabilities' => $provider->configured() ? $provider->capabilities() : [], 'daily_limit' => $limit, 'used' => $used, 'remaining' => max(0, $limit - $used)];
        }

        return ['configured' => $this->providers->anyConfigured(), 'providers' => $providers, 'aspects' => self::ASPECTS, 'styles' => array_keys(self::STYLES),
            'max_variants' => (int) config('studio.ai.max_variants', 4), 'max_prompt' => (int) config('studio.ai.max_prompt', 1000)];
    }

    public function create(User $user, Brand $brand, array $data): StudioAiJob
    {
        $this->assets->assertBrand($user, $brand);
        $allowed = ['kind', 'provider', 'prompt', 'aspect', 'style', 'count', 'media_id', 'mask', 'idempotency_key'];
        if (array_diff(array_keys($data), $allowed) !== []) {
            throw ValidationException::withMessages(['request' => ['La petición contiene propiedades no compatibles.']]);
        }
        $maxCount = (int) config('studio.ai.max_variants', 4);
        $data = Validator::make($data, [
            'kind' => ['required', 'in:'.implode(',', self::KINDS)],
            'provider' => ['required', 'in:openai,google'],
            'prompt' => ['nullable', 'string', 'max:'.(int) config('studio.ai.max_prompt', 1000)],
            'aspect' => ['nullable', 'in:'.implode(',', self::ASPECTS)],
            'style' => ['nullable', 'in:'.implode(',', array_keys(self::STYLES))],
            'count' => ['nullable', 'integer', 'min:1', 'max:'.$maxCount],
            'media_id' => ['nullable', 'integer', 'min:1'],
            'mask' => ['nullable', 'array:x,y,width,height'],
            'mask.*' => ['required', 'numeric', 'min:0', 'max:1'],
            'idempotency_key' => ['nullable', 'string', 'regex:/^[A-Za-z0-9_-]{8,64}$/D'],
        ], [], ['prompt' => 'descripción'])->validate();
        $kind = $data['kind'];
        $prompt = trim((string) ($data['prompt'] ?? ''));
        if (in_array($kind, ['generate', 'edit'], true) && mb_strlen($prompt) < 3) {
            throw ValidationException::withMessages(['prompt' => ['Describe lo que quieres en al menos 3 caracteres.']]);
        }
        if ($kind === 'outpaint' && ! isset($data['aspect'])) {
            throw ValidationException::withMessages(['aspect' => ['Elige la proporción a la que ampliar el lienzo.']]);
        }
        if ($kind === 'edit') {
            $mask = $data['mask'] ?? null;
            if (! is_array($mask) || count($mask) !== 4 || $mask['width'] < 0.02 || $mask['height'] < 0.02 || $mask['x'] + $mask['width'] > 1.0001 || $mask['y'] + $mask['height'] > 1.0001) {
                throw ValidationException::withMessages(['mask' => ['Marca una zona válida de la imagen (al menos un 2 % de ancho y alto).']]);
            }
            $data['mask'] = array_map('floatval', $mask);
        } else {
            unset($data['mask']);
        }
        $provider = $this->providers->get($data['provider']);
        if (! $provider?->configured()) {
            throw ValidationException::withMessages(['provider' => ['Este proveedor de IA no está configurado en esta instalación.']]);
        }
        $capability = $kind === 'edit' ? 'edit' : $kind;
        if (! in_array($capability, $provider->capabilities(), true)) {
            throw ValidationException::withMessages(['kind' => [$provider->label().' no admite esta operación.']]);
        }
        $source = null;
        $inputSha = null;
        if ($kind !== 'generate') {
            if (empty($data['media_id'])) {
                throw ValidationException::withMessages(['media_id' => ['Selecciona una imagen de los recursos de esta marca.']]);
            }
            $source = $this->assets->owned($user, $brand, (int) $data['media_id']);
            if ($source->kind !== 'image' || ! in_array($source->mime, ['image/png', 'image/jpeg', 'image/webp'], true)
                || $source->width < 1 || $source->height < 1 || $source->width * $source->height > (int) config('studio.images.max_pixels', 16000000)
                || (int) $source->size > (int) config('studio.ai.max_input_bytes', 20 * 1024 * 1024)) {
                throw ValidationException::withMessages(['media_id' => ['Selecciona una imagen PNG, JPEG o WebP de hasta 16 megapíxeles y 20 MiB.']]);
            }
            $inputSha = $this->hash($source);
            if ($kind === 'outpaint') {
                [$a, $b] = array_map('intval', explode(':', (string) $data['aspect']));
                if (abs($source->width / $source->height - $a / $b) < 0.02) {
                    throw ValidationException::withMessages(['aspect' => ['La imagen ya tiene esa proporción. Elige otra para ampliar el lienzo.']]);
                }
            }
        } else {
            unset($data['media_id']);
        }
        $payload = ['kind' => $kind, 'provider' => $provider->id(), 'model' => $provider->model(), 'prompt' => $prompt, 'aspect' => $data['aspect'] ?? '1:1',
            'style' => $data['style'] ?? 'natural', 'count' => (int) ($data['count'] ?? 1), 'mask' => $data['mask'] ?? null, 'source' => $inputSha];
        $fingerprint = hash('sha256', json_encode($payload, JSON_THROW_ON_ERROR));
        $key = $data['idempotency_key'] ?? null;

        return DB::transaction(function () use ($user, $brand, $provider, $payload, $fingerprint, $key, $source, $inputSha, $kind, $prompt): StudioAiJob {
            // La cuota es por usuario y proveedor: el candado es por usuario (no por marca) para que dos marcas no la superen a la vez.
            User::query()->whereKey($user->id)->lockForUpdate()->firstOrFail();
            if ($key !== null && ($existing = StudioAiJob::withoutUserScope()->where('user_id', $user->id)->where('idempotency_key', $key)->first())) {
                if ($existing->fingerprint !== $fingerprint || (int) $existing->brand_id !== (int) $brand->id) {
                    throw ValidationException::withMessages(['idempotency_key' => ['Esa clave de idempotencia ya se usó con otra petición.']]);
                }

                return $existing;
            }
            $active = StudioAiJob::withoutUserScope()->where('user_id', $user->id)->whereIn('status', ['queued', 'processing']);
            if ($existing = (clone $active)->where('brand_id', $brand->id)->where('fingerprint', $fingerprint)->first()) {
                return $existing;
            }
            if ((clone $active)->count() >= (int) config('studio.ai.max_active_jobs', 5)) {
                throw ValidationException::withMessages(['prompt' => ['Ya hay varias imágenes de IA pendientes. Espera a que terminen antes de pedir otra.']]);
            }
            $limit = $this->providers->limit($user, $provider->id());
            if ($this->providers->used($user, $provider->id()) + $payload['count'] > $limit) {
                throw ValidationException::withMessages(['count' => ['Has alcanzado tu cuota diaria de imágenes con '.$provider->label().' ('.$limit.'). Se renueva mañana.']]);
            }
            $job = new StudioAiJob(['brand_id' => $brand->id, 'source_media_id' => $source?->id, 'kind' => $kind, 'provider' => $provider->id(), 'model' => $provider->model(),
                'prompt' => $prompt, 'params' => ['aspect' => $payload['aspect'], 'style' => $payload['style'], 'mask' => $payload['mask']], 'count' => $payload['count'],
                'status' => 'queued', 'input_sha256' => $inputSha, 'fingerprint' => $fingerprint, 'idempotency_key' => $key]);
            $job->user_id = $user->id;
            $job->save();
            RunStudioAiJob::dispatch($job->id)->afterCommit();

            return $job;
        });
    }

    public function find(User $user, Brand $brand, int $id): StudioAiJob
    {
        $this->assets->assertBrand($user, $brand);

        return StudioAiJob::withoutUserScope()->where('user_id', $user->id)->where('brand_id', $brand->id)->findOrFail($id);
    }

    public function present(User $user, Brand $brand, StudioAiJob $job, bool $api = false): array
    {
        $this->assets->assertBrand($user, $brand);
        $job = $this->find($user, $brand, $job->id);
        $outputs = [];
        if ($job->status === 'ready') {
            foreach ((array) $job->output_media_ids as $id) {
                try {
                    $media = $this->assets->owned($user, $brand, (int) $id);
                    if (! Storage::disk($media->disk)->exists($media->path)) {
                        continue;
                    }
                    $item = $this->assets->present($brand, $media);
                    if ($api) {
                        $item['preview_url'] = route('api.studio.assets.file', ['brand' => $brand->id, 'media' => $media->id], absolute: false);
                    }
                    $outputs[] = $item;
                } catch (\Throwable) { // Un resultado eliminado de la biblioteca simplemente deja de listarse.
                }
            }
        }

        return array_replace($job->only(['id', 'brand_id', 'kind', 'provider', 'model', 'prompt', 'count', 'status', 'attempts', 'source_media_id', 'error_code', 'error', 'created_at', 'updated_at']),
            ['output_assets' => $outputs]);
    }

    /** Ejecuta el trabajo (cola, o sincrono en tests y E2E). Idempotente: solo un proceso reclama un trabajo `queued`. */
    public function process(int $id): void
    {
        if (StudioAiJob::withoutUserScope()->whereKey($id)->where('status', 'queued')->update(['status' => 'processing']) !== 1) {
            return;
        }
        $job = StudioAiJob::withoutUserScope()->findOrFail($id);
        $created = [];
        try {
            $user = User::findOrFail($job->user_id);
            $brand = Brand::withoutUserScope()->where('user_id', $user->id)->findOrFail($job->brand_id);
            $provider = $this->providers->get($job->provider);
            if (! $provider?->configured()) {
                throw new AiProviderException('not_configured');
            }
            $deadline = microtime(true) + (int) config('studio.ai.budget', 100);
            $request = $this->buildRequest($user, $brand, $job, $deadline);
            $images = $this->callWithRetries($provider, $request, $job, $deadline);
            foreach ($images as $bytes) {
                $created[] = $this->store($user, $brand, $job, $bytes);
            }
            $done = StudioAiJob::withoutUserScope()->whereKey($id)->where('status', 'processing')
                ->update(['status' => 'ready', 'output_media_ids' => json_encode(array_map(fn (MediaAsset $m) => $m->id, $created)), 'error_code' => null, 'error' => null]);
            if ($done !== 1) {
                // Otro proceso (failed() del worker) ya cerro el trabajo como error: no dejar recursos huerfanos.
                $this->discard($created);
            }
        } catch (\Throwable $e) {
            $this->discard($created);
            $code = $e instanceof AiProviderException ? $e->errorCode : ($e instanceof ValidationException ? 'source_unavailable' : 'processing_failed');
            if (! $e instanceof AiProviderException) {
                Log::warning('studio.ai_job_failed', ['job' => $id, 'exception' => $e::class]);
            }
            $closed = StudioAiJob::withoutUserScope()->whereKey($id)->where('status', 'processing')
                ->update(['status' => 'failed', 'error_code' => $code, 'error' => $e instanceof AiProviderException ? $e->getMessage() : self::errorMessage($code)]);
            if ($closed === 1) {
                app(JobFailureNotifier::class)->ai(StudioAiJob::withoutUserScope()->findOrFail($id));
            }
        }
    }

    /** @param list<MediaAsset> $media */
    private function discard(array $media): void
    {
        foreach ($media as $item) {
            try {
                StudioAsset::withoutUserScope()->where('media_asset_id', $item->id)->delete();
                app(MediaUploadService::class)->delete($item);
            } catch (\Throwable) { // Conserva lo que ya use una publicacion.
            }
        }
    }

    public static function errorMessage(string $code): string
    {
        return match ($code) {
            'source_unavailable' => 'La imagen original ya no está disponible o ha cambiado en esta marca.',
            'processing_interrupted' => 'El trabajo se interrumpió o superó el tiempo permitido. Puedes volver a intentarlo.',
            default => 'No se pudo completar la imagen. Vuelve a intentarlo.',
        };
    }

    private function buildRequest(User $user, Brand $brand, StudioAiJob $job, float $deadline): AiImageRequest
    {
        $params = (array) $job->params;
        $style = self::STYLES[$params['style'] ?? 'natural'] ?? '';
        $prompt = trim($job->prompt.' '.$style);
        $aspect = (string) ($params['aspect'] ?? '1:1');
        if ($job->kind === 'generate') {
            return new AiImageRequest('generate', $prompt, $aspect, $job->count, deadline: $deadline);
        }
        $source = $this->assets->owned($user, $brand, (int) $job->source_media_id);
        if (! hash_equals((string) $job->input_sha256, $this->hash($source))) {
            throw ValidationException::withMessages(['media_id' => ['La imagen original ha cambiado.']]);
        }
        $side = (int) config('studio.ai.max_edit_side', 1536);
        [$png, $w, $h] = ImageCanvas::normalize((string) Storage::disk($source->disk)->get($source->path), $side);
        if ($job->kind === 'edit') {
            $rect = (array) $params['mask'];

            return new AiImageRequest('edit', $prompt, self::nearest($w, $h), $job->count, $png, ImageCanvas::mask($w, $h, $rect), $deadline);
        }
        if ($job->kind === 'outpaint') {
            [$canvas, $mask, $cw, $ch] = ImageCanvas::outpaint($png, $aspect, $side);
            $prompt = trim('Amplía la escena de forma natural rellenando las zonas transparentes, manteniendo el estilo, la iluminación y la parte existente sin cambios. '.$prompt);

            return new AiImageRequest('outpaint', $prompt, $aspect, $job->count, $canvas, $mask, $deadline);
        }

        return new AiImageRequest('variation', trim('Crea una variación de esta imagen conservando su composición y su tema. '.$prompt), self::nearest($w, $h), $job->count, $png, deadline: $deadline);
    }

    private static function nearest(int $w, int $h): string
    {
        $ratio = $w / $h;

        return $ratio > 1.2 ? '3:2' : ($ratio < 0.83 ? '2:3' : '1:1');
    }

    /** @return list<string> */
    private function callWithRetries(ImageProvider $provider, AiImageRequest $request, StudioAiJob $job, float $deadline): array
    {
        $attempts = max(1, (int) config('studio.ai.attempts', 3));
        $backoff = array_values((array) config('studio.ai.backoff', [2, 6]));
        for ($n = 1; ; $n++) {
            StudioAiJob::withoutUserScope()->whereKey($job->id)->update(['attempts' => $n]);
            try {
                $images = $provider->request($request);
                if ($images === []) {
                    throw new AiProviderException('invalid_response');
                }

                return $images;
            } catch (AiProviderException $e) {
                if (! $e->retryable || $n >= $attempts) {
                    throw $e;
                }
                $wait = min(max($e->retryAfter, (int) ($backoff[$n - 1] ?? end($backoff) ?: 1)), (int) config('studio.ai.max_retry_after', 30));
                // El reintento (espera + una llamada minima) debe caber en el presupuesto del job; si no, se falla con el ultimo error.
                if (microtime(true) + $wait + 15 > $deadline) {
                    throw $e;
                }
                Sleep::for($wait)->seconds();
            }
        }
    }

    private function store(User $user, Brand $brand, StudioAiJob $job, string $bytes): MediaAsset
    {
        $mime = Ai\AbstractImageProvider::mime($bytes) ?? 'image/png';
        $ext = ['image/png' => 'png', 'image/jpeg' => 'jpg', 'image/webp' => 'webp'][$mime];
        $media = new MediaAsset(['disk' => config('filesystems.media', 'local'), 'path' => 'media/'.$user->id.'/'.Str::lower((string) Str::ulid()).'.'.$ext,
            'original_name' => 'ia-'.Str::slug(Str::limit($job->prompt, 40, '')).'-'.$job->id.'.'.$ext, 'mime' => $mime, 'kind' => 'image', 'declared_size' => strlen($bytes),
            'status' => MediaAsset::STATUS_PENDING, 'source' => 'ai',
            'source_ref' => json_encode(['provider' => $job->provider, 'model' => $job->model, 'prompt' => Str::limit($job->prompt, 150, ''), 'job' => $job->id], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)]);
        $media->user_id = $user->id;
        $media->save();
        try {
            if (! Storage::disk($media->disk)->put($media->path, $bytes)) {
                throw new \RuntimeException('No se pudo guardar la imagen.');
            }
            $media = app(MediaUploadService::class)->finalize($media);
            if (! $media->isReady()) {
                throw new \RuntimeException('La imagen no superó la validación.');
            }
            $this->assets->attach($user, $brand, $media->id);
        } catch (\Throwable $e) {
            try {
                app(MediaUploadService::class)->delete($media);
            } catch (\Throwable) {
            }

            throw $e;
        }

        return $media;
    }

    private function hash(MediaAsset $source): string
    {
        $stream = Storage::disk($source->disk)->readStream($source->path);
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
                if ($bytes > (int) config('studio.ai.max_input_bytes', 20 * 1024 * 1024)) {
                    throw ValidationException::withMessages(['media_id' => ['La imagen supera el máximo de 20 MiB.']]);
                }
                hash_update($hash, $chunk);
            }

            return hash_final($hash);
        } finally {
            fclose($stream);
        }
    }
}
