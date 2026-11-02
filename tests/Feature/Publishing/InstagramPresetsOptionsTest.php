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
    $this->payload = ['text' => 'Reel', 'schedule' => false, 'media' => [$this->video->id], 'targets' => [[
        'account_id' => $this->account->id, 'content_type' => 'instagram_reel',
        'options' => ['collaborators' => [' @Artist.One ', 'Second_artist'], 'is_ai_generated' => '1'],
    ]]];
});

test('InstagramPresets: web and service normalize persist and clear collaborators and AI', function () {
    $this->actingAs($this->user)->post("/brands/{$this->brand->id}/posts", $this->payload)->assertSessionHasNoErrors();
    $post = Post::where('brand_id', $this->brand->id)->firstOrFail();
    expect($post->targets()->first()->options)->toBe(['collaborators' => ['artist.one', 'second_artist'], 'is_ai_generated' => true]);
    $this->payload['targets'][0]['options'] = ['collaborators' => [], 'is_ai_generated' => false];
    $this->put('/posts/'.$post->id, $this->payload)->assertSessionHasNoErrors();
    expect($post->targets()->first()->options)->toBe(['collaborators' => [], 'is_ai_generated' => false]);
});

test('InstagramPresets: invalid collaborator selections cannot reach a worker', function (mixed $selection) {
    $this->payload['targets'][0]['options']['collaborators'] = $selection;
    expect(fn () => app(PostService::class)->create($this->user, $this->brand, $this->payload))->toThrow(PostValidationException::class);
})->with([
    'too many' => [['one', 'two', 'three', 'four']],
    'duplicate handles' => [['Artist', '@artist']],
    'URL' => [['https://instagram.com/artist']],
    'object' => [['username' => 'artist']],
    'not a list' => ['artist'],
    'empty handle' => [['@']],
]);

test('InstagramPresets: Stories and another network reject requested feed presets', function () {
    $this->payload['targets'][0]['content_type'] = 'instagram_story';
    expect(fn () => app(PostService::class)->create($this->user, $this->brand, $this->payload))->toThrow(PostValidationException::class);
    $this->account->forceFill(['network' => Network::Facebook])->save();
    $this->payload['targets'][0]['content_type'] = 'facebook_reel';
    expect(fn () => app(PostService::class)->create($this->user, $this->brand, $this->payload))->toThrow(PostValidationException::class);
});

test('InstagramPresets: invalid AI values are rejected by both web and service', function () {
    $this->payload['targets'][0]['options']['is_ai_generated'] = 'false';
    $this->actingAs($this->user)->post("/brands/{$this->brand->id}/posts", $this->payload)->assertSessionHasErrors('targets.0.options.is_ai_generated');
    expect(fn () => app(PostService::class)->create($this->user, $this->brand, $this->payload))->toThrow(PostValidationException::class);
});

test('InstagramPresets: Facebook video title is preserved and cannot leak to another network', function () {
    $this->account->forceFill(['network' => Network::Facebook])->save();
    $this->payload['targets'][0]['content_type'] = 'facebook_reel';
    $this->payload['targets'][0]['options'] = ['facebook_title' => 'Título independiente del Reel'];
    $this->actingAs($this->user)->post("/brands/{$this->brand->id}/posts", $this->payload)->assertSessionHasNoErrors();
    expect(Post::where('brand_id', $this->brand->id)->firstOrFail()->targets()->first()->options['facebook_title'] ?? null)->toBe('Título independiente del Reel');
    $this->account->forceFill(['network' => Network::Instagram])->save();
    $this->payload['targets'][0]['content_type'] = 'instagram_reel';
    expect(fn () => app(PostService::class)->create($this->user, $this->brand, $this->payload))->toThrow(PostValidationException::class);
});
