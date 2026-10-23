<?php

use App\Enums\ContentType;
use App\Models\MediaAsset;
use App\Models\User;
use App\Services\MediaUploadService;
use App\Services\MediaValidator;
use Illuminate\Support\Facades\Storage;

// Ficheros de muestra reales (ffmpeg -> ffprobe -> validador), sin red.
test('MediaValidator con videos de muestra reales: 15 fps se rechaza en TikTok y 25 fps en un short vertical se acepta', function () {
    if (! str_contains((string) shell_exec('ffmpeg -version 2>&1'), 'ffmpeg version')) {
        $this->markTestSkipped('ffmpeg no disponible');
    }

    Storage::fake('local');
    config(['filesystems.media' => 'local']);
    $user = User::factory()->create();

    $make = function (string $size, int $rate, int $seconds) use ($user): MediaAsset {
        $asset = new MediaAsset(['disk' => 'local', 'path' => 'media/'.$user->id.'/'.uniqid().'.mp4', 'original_name' => 'm.mp4', 'mime' => 'video/mp4',
            'kind' => 'video', 'declared_size' => 50 * 1024 * 1024, 'status' => MediaAsset::STATUS_PENDING]);
        $asset->user_id = $user->id;
        $asset->save();
        $full = Storage::disk('local')->path($asset->path);
        @mkdir(dirname($full), 0777, true);
        exec("ffmpeg -loglevel error -y -f lavfi -i testsrc=duration=$seconds:size=$size:rate=$rate -pix_fmt yuv420p ".escapeshellarg($full).' 2>&1', $out, $code);
        expect($code)->toBe(0);

        return app(MediaUploadService::class)->finalize($asset);
    };

    $validator = app(MediaValidator::class);
    $slow = $make('360x640', 15, 4);
    $ok = $make('360x640', 25, 4);

    expect(implode(' ', $validator->validate(ContentType::TikTokVideo, [$slow])))->toContain('15 fps')->toContain('al menos 23 fps');
    expect($validator->validate(ContentType::TikTokVideo, [$ok]))->toBe([])
        ->and($validator->validate(ContentType::YouTubeShort, [$ok]))->toBe([])
        ->and($validator->validate(ContentType::InstagramReel, [$ok]))->toBe([]) // 360 px < 540 recomendados: solo aviso
        ->and(implode(' ', $validator->warnings(ContentType::InstagramReel, [$ok])))->toContain('540');
});
