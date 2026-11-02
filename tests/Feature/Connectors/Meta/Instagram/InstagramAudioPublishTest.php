<?php

use App\Connectors\Data\PublishMedia;
use App\Connectors\Data\PublishRequest;
use App\Connectors\Exceptions\PermanentPublishException;
use App\Connectors\Exceptions\ReconnectRequiredException;
use App\Connectors\Exceptions\TransientPublishException;
use App\Connectors\Meta\Instagram\InstagramPublisher;
use App\Enums\ContentType;
use App\Models\SocialAccount;
use Tests\Support\FakeGraph;

beforeEach(function () {
    $this->account = SocialAccount::factory()->create(['external_id' => '17841400001', 'access_token' => 'page-secret', 'refresh_token' => 'user-secret']);
    $this->graph = new FakeGraph;
    $this->graph->on('GET', '#/content_publishing_limit$#', ['data' => []])
        ->on('POST', '#/media$#', ['id' => '9001'])
        ->on('GET', '#^9001$#', ['status_code' => 'FINISHED'])
        ->on('POST', '#/media_publish$#', ['id' => '18001'])
        ->on('GET', '#^18001$#', ['permalink' => 'https://www.instagram.com/reel/test/']);
    $this->audioOptions = ['instagram_audio' => ['audio_id' => '587784541076604', 'audio_volume' => 80, 'video_volume' => 50]];
    $this->request = new PublishRequest(1, ContentType::InstagramReel, 'Reel', [new PublishMedia(1, 'video', 'video/mp4', 'https://cdn.test/reel.mp4', 1080, 1920, 30)], $this->audioOptions);
});

test('InstagramAudioPublish: creates the container with official audio JSON and preserves the checkpoint', function () {
    $this->graph->on('GET', '#^587784541076604$#', ['audio_id' => '587784541076604', 'title' => 'Track']);
    $checkpoints = [];
    $request = new PublishRequest(1, $this->request->type, 'Reel', $this->request->media, $this->audioOptions, checkpoint: function ($ref) use (&$checkpoints) {
        $checkpoints[] = $ref;
    });
    $result = (new InstagramPublisher)->publish($this->account, $request);
    $params = $this->graph->callsTo('POST', '#/media$#')[0]['params'];
    expect($params)->toHaveKey('audio_configuration');
    expect(json_decode($params['audio_configuration'], true))->toBe(['audio_id' => '587784541076604', 'audio_volume' => 80, 'video_volume' => 50])
        ->and($checkpoints)->toBe(['9001'])->and($result->isPublished())->toBeTrue();
});

test('InstagramAudioPublish: a rejected track stops before container creation and never publishes without audio', function () {
    $g = $this->graph;
    $g->on('GET', '#^587784541076604$#', FakeGraph::error(100, 'Track unavailable'))
        ->on('GET', '#/content_publishing_limit$#', ['data' => []]);
    expect(fn () => (new InstagramPublisher)->publish($this->account, $this->request))->toThrow(PermanentPublishException::class);
    expect($g->callsTo('POST', '#.*#'))->toBeEmpty();
});

test('InstagramAudioPublish: transient catalog failure stays retryable', function () {
    $g = $this->graph;
    $g->on('GET', '#^587784541076604$#', FakeGraph::error(2, 'Temporary', status: 503))
        ->on('GET', '#/content_publishing_limit$#', ['data' => []]);
    expect(fn () => (new InstagramPublisher)->publish($this->account, $this->request))->toThrow(TransientPublishException::class);
    expect($g->callsTo('POST', '#.*#'))->toBeEmpty();
});

test('InstagramAudioPublish: poll reuses the existing container without searching or attaching again', function () {
    $request = new PublishRequest(1, $this->request->type, 'Reel', $this->request->media, $this->audioOptions, reference: '9001');
    expect((new InstagramPublisher)->poll($this->account, $request)->isPublished())->toBeTrue()
        ->and($this->graph->callsTo('GET', '#^587784541076604$#'))->toBeEmpty()
        ->and($this->graph->callsTo('POST', '#/media$#'))->toBeEmpty();
});

test('InstagramAudioPublish: expired User access requires reconnect rather than a permanent content failure', function () {
    $this->account->forceFill(['token_expires_at' => now()->subMinute()]);
    expect(fn () => (new InstagramPublisher)->publish($this->account, $this->request))->toThrow(ReconnectRequiredException::class);
    expect($this->graph->calls)->toBeEmpty();
});

test('InstagramAudioPublish: provider messages cannot persist secrets in publication errors', function () {
    $this->graph->on('GET', '#^587784541076604$#', FakeGraph::error(100, 'Rejected access_token=token-secreto https://audio.fbcdn.net/track?signature=privada'));
    try {
        (new InstagramPublisher)->publish($this->account, $this->request);
        $this->fail('Expected track rejection');
    } catch (PermanentPublishException $e) {
        expect($e->errorCode)->toBe('rechazada');
        expect($e->getMessage())->not->toContain('token-secreto', 'signature=privada');
    }
});
