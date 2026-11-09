<?php

use App\Models\Brand;
use App\Models\MediaAsset;
use App\Models\User;
use App\Services\Studio\StudioAssets;
use App\Services\Studio\StudioImages;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;

test('oriented JPEG validates the normalized dimensions rather than the encoded frame', function (bool $correct) {
    Queue::fake();
    Storage::fake('local');
    $user = User::factory()->create();
    $brand = Brand::factory()->for($user)->create();
    $image = imagecreatetruecolor(400, 300);
    ob_start();
    imagejpeg($image);
    $jpeg = ob_get_clean();
    imagedestroy($image);
    $exif = "Exif\0\0II".pack('v', 42).pack('V', 8).pack('v', 1).pack('v', 0x112).pack('v', 3).pack('V', 1).pack('v', 6)."\0\0".pack('V', 0);
    $bytes = substr($jpeg, 0, 2)."\xFF\xE1".pack('n', strlen($exif) + 2).$exif.substr($jpeg, 2);
    $source = MediaAsset::factory()->for($user)->create(['disk' => 'local', 'path' => 'media/'.$user->id.'/phone.jpg', 'mime' => 'image/jpeg', 'kind' => 'image', 'status' => 'listo', 'size' => strlen($bytes), 'width' => 400, 'height' => 300]);
    Storage::disk('local')->put($source->path, $bytes);
    app(StudioAssets::class)->attach($user, $brand, $source->id);
    $model = storage_path('framework/testing/dummy.onnx');
    File::ensureDirectoryExists(dirname($model));
    file_put_contents($model, 'dummy-model');
    config(['studio.images.model_path' => $model, 'studio.images.model_sha256' => hash_file('sha256', $model)]);
    $job = app(StudioImages::class)->create($user, $brand, ['media_id' => $source->id, 'operation' => 'remove_background']);
    Process::fake(function ($process) use ($correct) {
        $payload = json_decode($process->input, true);
        [$width,$height] = $correct ? [300, 400] : [400, 300];
        $image = imagecreatetruecolor($width, $height);
        imagepng($image, $payload['output']);
        imagedestroy($image);

        return Process::result(output: json_encode(['ok' => true, 'width' => $width, 'height' => $height, 'format' => 'png', 'model_sha256' => $payload['model_sha256']]));
    });
    app(StudioImages::class)->process($job->id);
    expect($job->fresh()->status)->toBe($correct ? 'ready' : 'failed');
    expect(hash('sha256', Storage::disk('local')->get($source->path)))->toBe($job->input_sha256);
})->with([true, false]);
