<?php

namespace Database\Factories;

use App\Models\MediaAsset;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/** @extends Factory<MediaAsset> */
class MediaAssetFactory extends Factory
{
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'disk' => config('filesystems.media'),
            'path' => 'media/test/'.Str::ulid().'.jpg',
            'original_name' => 'foto.jpg',
            'mime' => 'image/jpeg',
            'kind' => MediaAsset::KIND_IMAGE,
            'declared_size' => 200000,
            'size' => 200000,
            'width' => 1080,
            'height' => 1080,
            'status' => MediaAsset::STATUS_READY,
        ];
    }

    public function video(float $duration = 30, int $width = 1080, int $height = 1920, float $fps = 30): static
    {
        return $this->state([
            'path' => 'media/test/'.Str::ulid().'.mp4',
            'original_name' => 'video.mp4',
            'mime' => 'video/mp4',
            'kind' => MediaAsset::KIND_VIDEO,
            'size' => 5_000_000,
            'declared_size' => 5_000_000,
            'width' => $width,
            'height' => $height,
            'duration' => $duration,
            'fps' => $fps,
            'codec' => 'h264',
            'audio_codec' => 'aac',
        ]);
    }
}
