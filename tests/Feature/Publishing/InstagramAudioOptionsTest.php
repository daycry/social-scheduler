<?php

use App\Connectors\Network;
use App\Exceptions\PostValidationException;
use App\Models\Brand;
use App\Models\MediaAsset;
use App\Models\Post;
use App\Models\SocialAccount;
use App\Models\User;
use App\Services\PostService;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    Http::preventStrayRequests();
    $this->user = User::factory()->create();
    $this->brand = Brand::factory()->for($this->user)->create();
    $this->account = SocialAccount::factory()->for($this->user)->create(['brand_id' => $this->brand->id, 'network' => Network::Instagram]);
    $this->video = MediaAsset::factory()->video(duration: 30)->for($this->user)->create();
    $this->selection = ['audio_id' => '587784541076604', 'audio_volume' => 80, 'video_volume' => 50];
    $this->payload = ['text' => 'Reel', 'schedule' => false, 'media' => [$this->video->id], 'targets' => [[
        'account_id' => $this->account->id, 'content_type' => 'instagram_reel', 'options' => ['instagram_audio' => $this->selection],
    ]]];
});

test('InstagramAudioOptions: other formats cannot silently ignore selected audio', function () {
    $this->payload['targets'][0]['content_type'] = 'instagram_story';
    expect(fn () => app(PostService::class)->create($this->user, $this->brand, $this->payload))->toThrow(PostValidationException::class);
});

test('InstagramAudioOptions: malformed selection and ineligible accounts are rejected by the shared service', function (array $selection) {
    $this->payload['targets'][0]['options']['instagram_audio'] = $selection;
    expect(fn () => app(PostService::class)->create($this->user, $this->brand, $this->payload))->toThrow(PostValidationException::class);
})->with([
    [['audio_id' => '../me']], [['audio_id' => 123]], [['audio_id' => '11', 'audio_volume' => 0]],
    [['audio_id' => '11', 'video_volume' => 101]], [['audio_id' => '11', 'video_volume' => false]],
    [['audio_id' => '11', 'preview_url' => 'https://evil.example']], [[]],
]);

test('InstagramAudioOptions: web saves reopens and removes the selection without storing preview URLs', function () {
    $this->actingAs($this->user)->post("/brands/{$this->brand->id}/posts", $this->payload)->assertSessionHasNoErrors();
    $post = Post::where('brand_id', $this->brand->id)->firstOrFail();
    expect($post->targets()->first()->options)->toBe(['instagram_audio' => $this->selection]);
    $this->get('/posts/'.$post->id.'/edit')->assertOk()->assertSee('587784541076604');
    $this->payload['targets'][0]['options']['instagram_audio'] = null;
    $this->put('/posts/'.$post->id, $this->payload)->assertSessionHasNoErrors();
    expect($post->targets()->first()->options['instagram_audio'] ?? null)->toBeNull();
});

test('InstagramAudioOptions: selecting audio requires the User token but videos with embedded audio do not', function () {
    $this->account->forceFill(['refresh_token' => null])->save();
    expect(fn () => app(PostService::class)->create($this->user, $this->brand, $this->payload))->toThrow(PostValidationException::class);
    $this->payload['targets'][0]['options'] = [];
    expect(app(PostService::class)->create($this->user, $this->brand, $this->payload)->id)->toBeInt();
});
