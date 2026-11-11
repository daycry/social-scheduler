<?php

use App\Connectors\Data\PublishMedia;
use App\Connectors\Data\PublishRequest;
use App\Connectors\Exceptions\PermanentPublishException;
use App\Connectors\Meta\Facebook\FacebookPublisher;
use App\Connectors\Meta\Instagram\InstagramPublisher;
use App\Connectors\Network;
use App\Enums\ContentType;
use App\Models\SocialAccount;
use Tests\Support\FakeGraph;

beforeEach(function () {
    $this->account = SocialAccount::factory()->create(['network' => Network::Instagram, 'external_id' => '17841400001']);
    $this->graph = new FakeGraph;
    $this->graph->on('GET', '#/content_publishing_limit$#', ['data' => []])
        ->on('POST', '#/media$#', FakeGraph::seq(['id' => '9001'], ['id' => '9002'], ['id' => '9003']))
        ->on('GET', '#^900[123]$#', ['status_code' => 'FINISHED'])
        ->on('POST', '#/media_publish$#', ['id' => '18001'])
        ->on('GET', '#^18001$#', ['permalink' => 'https://www.instagram.com/p/fixture/']);
});

test('InstagramPresetsPublish: collaborators and AI reach only the final media container', function (ContentType $type) {
    $image = new PublishMedia(1, 'image', 'image/jpeg', 'https://cdn.test/image.jpg', 1080, 1080);
    $video = new PublishMedia(2, 'video', 'video/mp4', 'https://cdn.test/video.mp4', 1080, 1920, 30);
    $media = $type === ContentType::InstagramReel ? [$video] : ($type === ContentType::InstagramCarousel ? [$image, $image] : [$image]);
    $request = new PublishRequest(1, $type, 'Post', $media, ['collaborators' => ['artist.one', 'second_artist'], 'is_ai_generated' => true]);
    $result = (new InstagramPublisher)->publish($this->account, $request);
    $calls = $this->graph->callsTo('POST', '#/media$#');
    $parent = $calls[array_key_last($calls)]['params'];
    expect(json_decode($parent['collaborators'] ?? 'null', true))->toBe(['artist.one', 'second_artist'])
        ->and($parent['is_ai_generated'] ?? null)->toBe('true')->and($result->isPublished())->toBeTrue();
    if ($type === ContentType::InstagramCarousel) {
        expect($calls)->toHaveCount(3);
        foreach (array_slice($calls, 0, 2) as $child) {
            expect($child['params'])->not->toHaveKeys(['collaborators', 'is_ai_generated']);
        }
    }
})->with([ContentType::InstagramImage, ContentType::InstagramReel, ContentType::InstagramCarousel]);

test('InstagramPresetsPublish: invalid worker options stop before any provider request', function () {
    $request = new PublishRequest(1, ContentType::InstagramReel, 'Post', [new PublishMedia(1, 'video', 'video/mp4', 'https://cdn.test/video.mp4')], ['collaborators' => ['one', 'two', 'three', 'four']]);
    expect(fn () => (new InstagramPublisher)->publish($this->account, $request))->toThrow(PermanentPublishException::class);
    expect($this->graph->calls)->toBeEmpty();
});

test('InstagramPresetsPublish: Facebook Reel title reaches finish rather than upload session start', function () {
    $this->graph->on('POST', '#/video_reels$#', FakeGraph::seq(['video_id' => '2001', 'upload_url' => 'https://rupload.facebook.com/file'], ['success' => true]))
        ->on('POST', '#^file$#', []);
    $request = new PublishRequest(1, ContentType::FacebookReel, 'Description', [new PublishMedia(1, 'video', 'video/mp4', 'https://cdn.test/video.mp4')], ['facebook_title' => 'Title']);
    (new FacebookPublisher)->publish($this->account, $request);
    $calls = $this->graph->callsTo('POST', '#/video_reels$#');
    expect($calls[0]['params'])->not->toHaveKey('title');
    expect($calls[1]['params']['title'] ?? null)->toBe('Title');
});

test('T-18 Instagram: location_id llega al contenedor final y user_tags solo al de una imagen, con sus coordenadas', function (ContentType $type) {
    $image = new PublishMedia(1, 'image', 'image/jpeg', 'https://cdn.test/image.jpg', 1080, 1080);
    $video = new PublishMedia(2, 'video', 'video/mp4', 'https://cdn.test/video.mp4', 1080, 1920, 30);
    $media = $type === ContentType::InstagramReel ? [$video] : ($type === ContentType::InstagramCarousel ? [$image, $image] : [$image]);
    $options = ['location_id' => '213385402'] + ($type === ContentType::InstagramImage ? ['user_tags' => [['username' => 'amigo', 'x' => 0.25, 'y' => 0.75]]] : []);

    (new InstagramPublisher)->publish($this->account, new PublishRequest(1, $type, 'Post', $media, $options));

    $calls = $this->graph->callsTo('POST', '#/media$#');
    $final = $calls[array_key_last($calls)]['params'];
    expect($final['location_id'] ?? null)->toBe('213385402');
    if ($type === ContentType::InstagramImage) {
        expect(json_decode($final['user_tags'], true))->toBe([['username' => 'amigo', 'x' => 0.25, 'y' => 0.75]]);
    } else {
        expect($final)->not->toHaveKey('user_tags');
    }
    if ($type === ContentType::InstagramCarousel) {
        foreach (array_slice($calls, 0, 2) as $child) {
            expect($child['params'])->not->toHaveKey('location_id');
        }
    }
})->with([ContentType::InstagramImage, ContentType::InstagramReel, ContentType::InstagramCarousel]);

test('T-18 Instagram: etiquetas fuera de una imagen o ubicacion no numerica paran el worker antes de llamar al proveedor', function (array $options, ContentType $type) {
    $media = $type === ContentType::InstagramReel ? [new PublishMedia(2, 'video', 'video/mp4', 'https://cdn.test/video.mp4')] : [new PublishMedia(1, 'image', 'image/jpeg', 'https://cdn.test/i.jpg')];

    expect(fn () => (new InstagramPublisher)->publish($this->account, new PublishRequest(1, $type, 'Post', $media, $options)))->toThrow(PermanentPublishException::class);
    expect($this->graph->calls)->toBeEmpty();
})->with([
    'etiquetas en reel' => [['user_tags' => [['username' => 'amigo', 'x' => 0.1, 'y' => 0.1]]], ContentType::InstagramReel],
    'coordenadas fuera de rango' => [['user_tags' => [['username' => 'amigo', 'x' => 2, 'y' => 0.1]]], ContentType::InstagramImage],
    'ubicacion no numerica' => [['location_id' => 'Madrid'], ContentType::InstagramImage],
]);
