<?php

use App\Models\Brand;
use App\Models\MediaAsset;
use App\Models\User;
use App\Services\MediaUploadService;
use Illuminate\Support\Facades\Storage;
use Tests\Support\StudioAudioR2;

beforeEach(function () {
    Storage::fake('local');
    $this->user = User::factory()->create();
    $this->brand = Brand::factory()->for($this->user)->create();
    $this->actingAs($this->user);
    $this->remote = new StudioAudioR2;
    config(['media.multipart_threshold' => 1]);
    $this->upload = $this->postJson('/brands/'.$this->brand->id.'/studio/audio', ['name' => 'abandonado.wav', 'mime' => 'audio/wav', 'size' => 200])
        ->assertCreated()->assertJsonPath('upload.mode', 'multipart')->json();
    $this->asset = MediaAsset::withoutUserScope()->findOrFail($this->upload['asset']['id']);
    $this->asset->forceFill(['created_at' => now()->subHours(25)])->save();
});

test('Studio audio abandonment aborts its open remote multipart before removing the ledger', function () {
    expect($this->remote->multipartOpen)->toBeTrue();
    expect(app(MediaUploadService::class)->pruneAbandoned(24))->toBe(1);
    expect($this->remote->multipartOpen)->toBeFalse()->and(MediaAsset::withoutUserScope()->whereKey($this->asset->id)->exists())->toBeFalse();
    $calls = array_values(array_filter(iterator_to_array($this->remote->calls), fn ($call) => $call['name'] === 'AbortMultipartUpload'));
    expect($calls)->toHaveCount(1)->and($calls[0]['args']['@http']['timeout'])->toBeLessThanOrEqual(5)
        ->and($calls[0]['args']['@retries'])->toBe(0);
});

test('Studio audio failed remote abort keeps the session tracked until a later cleanup', function () {
    $this->remote->fail['AbortMultipartUpload'] = 1;
    expect(app(MediaUploadService::class)->pruneAbandoned(24))->toBe(0);
    expect($this->remote->multipartOpen)->toBeTrue()->and($this->asset->fresh()->upload_id)->toBe('fake-upload');
    expect(app(MediaUploadService::class)->pruneAbandoned(24))->toBe(1);
    expect($this->remote->multipartOpen)->toBeFalse()->and(MediaAsset::withoutUserScope()->whereKey($this->asset->id)->exists())->toBeFalse();
});

test('Studio audio abandoned cleanup accepts an already closed remote session', function () {
    $this->remote->multipartOpen = false;
    expect(app(MediaUploadService::class)->pruneAbandoned(24))->toBe(1);
    expect(MediaAsset::withoutUserScope()->whereKey($this->asset->id)->exists())->toBeFalse();
});
