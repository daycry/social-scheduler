<?php

namespace App\Services\Studio;

use App\Models\Brand;
use App\Models\MediaAsset;
use App\Models\Studio\StudioAsset;
use App\Models\User;
use Illuminate\Filesystem\AwsS3V3Adapter;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\StreamedResponse;

class StudioAssets
{
    public function attach(User $user, Brand $brand, int $mediaId): MediaAsset
    {
        $this->assertBrand($user, $brand);
        $asset = MediaAsset::withoutUserScope()->where('user_id', $user->id)->findOrFail($mediaId);
        if (! $asset->isReady() || ! in_array($asset->kind, [MediaAsset::KIND_IMAGE, MediaAsset::KIND_VIDEO, MediaAsset::KIND_AUDIO], true)
            || ($asset->kind === MediaAsset::KIND_AUDIO && (! filled($asset->audio_codec) || ! is_finite((float) $asset->duration) || $asset->duration <= 0))) {
            throw ValidationException::withMessages(['media_id' => ['El archivo debe ser una imagen, vídeo o audio propio validado y listo.']]);
        }
        StudioAsset::withoutUserScope()->firstOrCreate(['brand_id' => $brand->id, 'media_asset_id' => $asset->id], ['user_id' => $user->id]);

        return $asset;
    }

    public function list(User $user, Brand $brand): Collection
    {
        $this->assertBrand($user, $brand);

        return StudioAsset::withoutUserScope()->where('user_id', $user->id)->where('brand_id', $brand->id)->with('media')->latest('id')->get()
            ->map(fn (StudioAsset $link) => $link->media)->filter(fn (?MediaAsset $m) => $m !== null && (int) $m->user_id === (int) $user->id && $m->isReady())->values();
    }

    public function owned(User $user, Brand $brand, int $mediaId): MediaAsset
    {
        $this->assertBrand($user, $brand);
        $link = StudioAsset::withoutUserScope()->where('user_id', $user->id)->where('brand_id', $brand->id)->where('media_asset_id', $mediaId)->with('media')->firstOrFail();
        abort_unless($link->media && (int) $link->media->user_id === (int) $user->id && $link->media->isReady(), 404);

        return $link->media;
    }

    /** Authorize exactly the requested resource IDs with two queries, regardless of scene size. */
    public function many(User $user, Brand $brand, array $ids): Collection
    {
        $this->assertBrand($user, $brand);
        $ids = array_values(array_unique(array_map('intval', $ids)));
        if ($ids === []) {
            return collect();
        }
        $links = StudioAsset::withoutUserScope()->where('user_id', $user->id)->where('brand_id', $brand->id)
            ->whereIn('media_asset_id', $ids)->with('media')->get();
        $media = $links->map(fn (StudioAsset $link) => $link->media)
            ->filter(fn (?MediaAsset $asset) => $asset !== null && (int) $asset->user_id === (int) $user->id && $asset->isReady())->keyBy('id');
        abort_unless($media->count() === count($ids), 404);

        return $media;
    }

    public function present(Brand $brand, MediaAsset $asset): array
    {
        return array_replace($asset->toPublicArray(), ['preview_url' => route('studio.assets.file', ['brand' => $brand->id, 'media' => $asset->id], absolute: false)])
            + ($asset->kind === MediaAsset::KIND_AUDIO ? ['audio_codec' => $asset->audio_codec] : []);
    }

    /** Same-origin private proxy works for local and R2 assets without exposing bucket credentials or relying on CORS. */
    public function file(User $user, Brand $brand, int $mediaId, ?string $range = null): StreamedResponse
    {
        $asset = $this->owned($user, $brand, $mediaId);
        $disk = Storage::disk($asset->disk);
        abort_unless($asset->path !== null && $disk->exists($asset->path), 404);
        $size = (int) $disk->size($asset->path);
        abort_unless($size > 0, 404);
        $start = 0;
        $end = $size - 1;
        $headers = ['Content-Type' => $asset->mime, 'X-Content-Type-Options' => 'nosniff', 'Cache-Control' => 'private, no-store', 'Accept-Ranges' => 'bytes'];
        if ($range !== null) {
            if (preg_match('/^bytes=(\d*)-(\d*)$/D', trim($range), $matches) !== 1 || ($matches[1] === '' && $matches[2] === '')) {
                return response()->stream(static function (): void {}, 416, $headers + ['Content-Range' => 'bytes */'.$size]);
            }
            if ($matches[1] === '') {
                $length = (int) $matches[2];
                $start = max(0, $size - $length);
                if ($length <= 0) {
                    $start = $size;
                }
            } else {
                $start = (int) $matches[1];
                $end = $matches[2] === '' ? $end : min($end, (int) $matches[2]);
            }
            if ($start >= $size || $start > $end) {
                return response()->stream(static function (): void {}, 416, $headers + ['Content-Range' => 'bytes */'.$size]);
            }
            $headers['Content-Range'] = "bytes $start-$end/$size";
        }
        if ($disk instanceof AwsS3V3Adapter && $range !== null) {
            $result = $disk->getClient()->getObject(['Bucket' => config('filesystems.disks.'.$asset->disk.'.bucket'), 'Key' => $asset->path, 'Range' => "bytes=$start-$end"]);
            $stream = $result['Body']->detach();
        } else {
            $stream = $disk->readStream($asset->path);
            if (is_resource($stream) && $start > 0 && fseek($stream, $start) !== 0) {
                fclose($stream);
                abort(416, 'El almacenamiento no admite ese rango.');
            }
        }
        abort_unless(is_resource($stream), 404);
        $length = $end - $start + 1;
        $headers['Content-Length'] = (string) $length;

        return response()->stream(function () use ($stream, $length): void {
            try {
                $remaining = $length;
                while ($remaining > 0 && ! feof($stream)) {
                    $bytes = fread($stream, min(65536, $remaining));
                    if ($bytes === false || $bytes === '') {
                        break;
                    }
                    echo $bytes;
                    $remaining -= strlen($bytes);
                }
            } finally {
                fclose($stream);
            }
        }, $range === null ? 200 : 206, $headers);
    }

    public function assertBrand(User $user, Brand $brand): void
    {
        abort_unless((int) $brand->user_id === (int) $user->id, 404);
    }
}
