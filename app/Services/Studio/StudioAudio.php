<?php

namespace App\Services\Studio;

use App\Models\Brand;
use App\Models\MediaAsset;
use App\Models\Studio\StudioAsset;
use App\Models\User;
use App\Services\MediaStorage;
use App\Services\MediaUploadService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
use Throwable;

class StudioAudio
{
    public const MIMES = ['audio/mpeg', 'audio/wav', 'audio/x-wav', 'audio/mp4', 'audio/x-m4a', 'audio/aac', 'audio/ogg'];

    public function __construct(private readonly MediaUploadService $uploads, private readonly StudioAssets $assets, private readonly MediaStorage $storage) {}

    public function start(User $user, Brand $brand, array $input, bool $api = false): array
    {
        $this->assets->assertBrand($user, $brand);
        if (array_diff(array_keys($input), ['name', 'mime', 'size'])) {
            throw ValidationException::withMessages(['audio' => ['Indica solo nombre, MIME y tamaño; el archivo se transfiere como binario.']]);
        }
        $max = min(100 * 1024 * 1024, (int) config('media.max_bytes.audio'));
        $data = Validator::make($input, ['name' => ['required', 'string', 'max:255'], 'mime' => ['required', Rule::in(self::MIMES)],
            'size' => ['required', 'integer', 'min:1', 'max:'.$max]])->validate();
        $mime = match ($data['mime']) {
            'audio/x-wav' => 'audio/wav', 'audio/x-m4a' => 'audio/mp4', default => $data['mime']
        };
        try {
            $result = $this->uploads->initiate($user, $data['name'], $mime, (int) $data['size'], allowAudio: true);
        } catch (InvalidArgumentException $e) {
            throw ValidationException::withMessages(['audio' => [$e->getMessage()]]);
        }
        $asset = $result['asset'];
        try {
            StudioAsset::create(['user_id' => $user->id, 'brand_id' => $brand->id, 'media_asset_id' => $asset->id]);
        } catch (Throwable $e) {
            $this->uploads->delete($asset);
            throw $e;
        }
        $upload = $result['upload'];
        if ($upload['mode'] === 'local') {
            $upload['url'] = route(($api ? 'api.' : '').'studio.audio.upload', ['brand' => $brand->id, 'media' => $asset->id], absolute: false);
            $upload['headers'] = ['Content-Type' => $mime];
        }

        return ['asset' => $this->present($brand, $asset, $api), 'upload' => $upload,
            'complete_url' => route(($api ? 'api.' : '').'studio.audio.complete', ['brand' => $brand->id, 'media' => $asset->id], absolute: false)];
    }

    public function present(Brand $brand, MediaAsset $asset, bool $api = false): array
    {
        $data = $asset->toPublicArray();
        $data['audio_codec'] = $asset->audio_codec;
        if ($data['preview_url'] !== null) {
            $data['preview_url'] = route(($api ? 'api.' : '').'studio.assets.file', ['brand' => $brand->id, 'media' => $asset->id], absolute: false);
        }

        return $data;
    }

    public function listing(User $user, Brand $brand, array $filters = [], bool $api = false): array
    {
        $this->assets->assertBrand($user, $brand);
        $filters = Validator::make($filters, ['query' => ['sometimes', 'nullable', 'string', 'max:120'],
            'page' => ['sometimes', 'integer', 'min:1'], 'per_page' => ['sometimes', 'integer', 'min:1', 'max:100']])->validate();
        $query = MediaAsset::withoutUserScope()->select('media_assets.*')->join('studio_assets', 'studio_assets.media_asset_id', '=', 'media_assets.id')
            ->where('media_assets.user_id', $user->id)->where('studio_assets.user_id', $user->id)->where('studio_assets.brand_id', $brand->id)
            ->where('media_assets.kind', MediaAsset::KIND_AUDIO)->where('media_assets.status', MediaAsset::STATUS_READY);
        if (filled($filters['query'] ?? null)) {
            $term = '%'.str_replace(['!', '%', '_'], ['!!', '!%', '!_'], trim($filters['query'])).'%';
            $query->whereRaw("media_assets.original_name LIKE ? ESCAPE '!'", [$term]);
        }
        $page = $query->orderByDesc('media_assets.id')->paginate($filters['per_page'] ?? 20, ['media_assets.*'], 'page', $filters['page'] ?? 1);

        return ['assets' => $page->getCollection()->map(fn ($asset) => $this->present($brand, $asset, $api))->values()->all(),
            'pagination' => ['current_page' => $page->currentPage(), 'last_page' => $page->lastPage(), 'per_page' => $page->perPage(),
                'total' => $page->total(), 'next_page' => $page->hasMorePages() ? $page->currentPage() + 1 : null]];
    }

    public function owned(User $user, Brand $brand, int $media): MediaAsset
    {
        $this->assets->assertBrand($user, $brand);
        $link = StudioAsset::withoutUserScope()->where('user_id', $user->id)->where('brand_id', $brand->id)
            ->where('media_asset_id', $media)->with('media')->firstOrFail();
        abort_unless($link->media && (int) $link->media->user_id === (int) $user->id && $link->media->kind === MediaAsset::KIND_AUDIO, 404);

        return $link->media;
    }

    /** The incoming binary is streamed to a bounded staging file, never materialized as a PHP string. */
    public function uploadLocal(User $user, Brand $brand, int $media, mixed $stream): MediaAsset
    {
        $asset = $this->owned($user, $brand, $media);
        abort_if($this->storage->isS3() || $asset->disk !== $this->storage->diskName(), 404);
        abort_unless(is_resource($stream), 400, 'Falta el archivo binario.');

        return $this->locked($asset, function () use ($user, $brand, $media, $stream): MediaAsset {
            $asset = $this->owned($user, $brand, $media);
            abort_unless($asset->status === MediaAsset::STATUS_PENDING, 409, 'La subida ya terminó; inicia otra para cambiar el archivo.');
            $disk = Storage::disk($asset->disk);
            $temporary = $asset->path.'.'.Str::uuid().'.part';
            $out = null;
            try {
                File::ensureDirectoryExists(dirname($disk->path($temporary)), 0700);
                $out = fopen($disk->path($temporary), 'xb');
                abort_unless(is_resource($out), 503, 'No se pudo preparar la subida.');
                $limit = min(100 * 1024 * 1024, (int) config('media.max_bytes.audio'), (int) $asset->declared_size);
                $bytes = 0;
                $deadline = microtime(true) + 30;
                while (! feof($stream)) {
                    abort_if(microtime(true) > $deadline, 503, 'La lectura de la subida agotó su tiempo.');
                    $chunk = fread($stream, min(1024 * 1024, $limit - $bytes + 1));
                    abort_if($chunk === false || ($chunk === '' && ! feof($stream)), 503, 'No se pudo leer el archivo completo.');
                    $bytes += strlen($chunk);
                    if ($bytes > $limit) {
                        return $this->uploads->invalidate($asset, 'El audio supera el tamaño declarado o permitido.');
                    }
                    abort_if($chunk !== '' && fwrite($out, $chunk) !== strlen($chunk), 503, 'No se pudo guardar el archivo completo.');
                }
                fclose($out);
                if ($bytes !== (int) $asset->declared_size) {
                    return $this->uploads->invalidate($asset, 'El tamaño real del audio no coincide con el declarado.');
                }
                abort_unless($disk->move($temporary, $asset->path), 503, 'No se pudo confirmar la subida.');

                return $this->uploads->finalize($asset);
            } finally {
                if (is_resource($out)) {
                    fclose($out);
                }
                $disk->delete($temporary);
            }
        });
    }

    public function complete(User $user, Brand $brand, int $media, array $input): MediaAsset
    {
        $asset = $this->owned($user, $brand, $media);
        if (array_diff(array_keys($input), ['parts'])) {
            throw ValidationException::withMessages(['parts' => ['Solo se admite la lista de partes subidas.']]);
        }
        $data = Validator::make($input, ['parts' => ['sometimes', 'array', 'max:10000'], 'parts.*' => ['array:number,etag'],
            'parts.*.number' => ['required', 'integer', 'distinct', 'min:1', 'max:10000'], 'parts.*.etag' => ['required', 'string', 'max:200']])->validate();

        return $this->locked($asset, function () use ($user, $brand, $media, $data): MediaAsset {
            $fresh = $this->owned($user, $brand, $media);
            if ($fresh->isReady()) {
                return $fresh;
            }
            abort_unless($fresh->status === MediaAsset::STATUS_PENDING, 422, 'El audio no es válido; inicia otra subida.');
            abort_unless($fresh->disk === $this->storage->diskName(), 409, 'El almacenamiento de esta subida cambió.');
            try {
                return $this->uploads->complete($fresh, $data['parts'] ?? [], timeout: 20);
            } catch (InvalidArgumentException $e) {
                throw ValidationException::withMessages(['parts' => [$e->getMessage()]]);
            }
        });
    }

    private function locked(MediaAsset $asset, callable $operation): MediaAsset
    {
        $lock = Cache::lock('studio.audio.transfer.'.$asset->id, 180);
        abort_unless($lock->get(), 409, 'La subida está siendo procesada en otra petición.');
        try {
            return $operation();
        } finally {
            $lock->release();
        }
    }
}
