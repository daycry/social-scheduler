<?php

use App\Connectors\Data\PublishMedia;
use App\Connectors\Data\PublishRequest;
use App\Connectors\Exceptions\PermanentPublishException;
use App\Connectors\Exceptions\TransientPublishException;
use App\Connectors\Meta\Facebook\FacebookPublisher;
use App\Connectors\Network;
use App\Enums\ContentType;
use App\Models\Brand;
use App\Models\SocialAccount;
use App\Models\User;
use App\Services\MediaValidator;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Tests\Support\FakeGraph;
use Tests\Support\McpClient;

uses(McpClient::class);

// T-31 · Facebook: varias fotos (`attached_media`, cada foto con published=false) e historias (`photo_stories`, `video_stories`).
// Contrato: Graph API reference Page > photos (publicacion con varias fotos) VERIFICADO; `photo_stories`/`video_stories` verificados solo en parametros
// (SDK oficial de Meta); respuesta de la historia, `/stories` y limites de media SIN VERIFICAR. Todo con Http::fake, sin red.

const MP_PAGE = '1065269806670394';

beforeEach(function () {
    Carbon::setTestNow('2026-11-01 12:00:00');
    config(['social.meta.graph_version' => 'v23.0']);
    Http::preventStrayRequests();
    $this->user = User::factory()->create();
    $this->brand = Brand::factory()->for($this->user)->create();
    $this->account = SocialAccount::factory()->for($this->user)->create(['brand_id' => $this->brand->id, 'network' => Network::Facebook, 'external_id' => MP_PAGE, 'access_token' => 'page-token-xyz']);
    $this->graph = new FakeGraph;
    $this->fb = new FacebookPublisher;
});

function mpImage(int $id, ?string $alt = null): PublishMedia
{
    return new PublishMedia($id, 'image', 'image/jpeg', "https://cdn.test/$id.jpg", 1080, 1080, alt: $alt);
}

function mpVideo(): PublishMedia
{
    return new PublishMedia(9, 'video', 'video/mp4', 'https://cdn.test/v.mp4', 1080, 1920, 30.0, 30.0);
}

function mpRequest(ContentType $type, array $media, ?string $text = 'Album', ?string $reference = null, array $progress = [], ?Closure $checkpoint = null): PublishRequest
{
    return new PublishRequest(77, $type, $text, $media, [], $reference, now()->subMinutes(5), $progress, $checkpoint);
}

test('varias fotos: sube cada una con published=false y las publica juntas con attached_media y el texto', function () {
    $this->graph->on('POST', '#^'.MP_PAGE.'/photos$#', FakeGraph::seq(['id' => 'ph1'], ['id' => 'ph2'], ['id' => 'ph3']));
    $this->graph->on('POST', '#^'.MP_PAGE.'/feed$#', ['id' => MP_PAGE.'_900']);

    $r = $this->fb->publish($this->account, mpRequest(ContentType::FacebookPhotos, [mpImage(1, 'Una'), mpImage(2), mpImage(3)]));

    expect($r->isPublished())->toBeTrue()->and($r->externalId)->toBe(MP_PAGE.'_900')->and($r->url)->toBe('https://www.facebook.com/'.MP_PAGE.'/posts/900');
    $uploads = $this->graph->callsTo('POST', '#/photos$#');
    expect($uploads)->toHaveCount(3)->and($uploads[0]['params'])->toMatchArray(['url' => 'https://cdn.test/1.jpg', 'published' => 'false', 'alt_text_custom' => 'Una'])
        ->and($uploads[1]['params'])->not->toHaveKey('alt_text_custom')->and($uploads[0]['params'])->not->toHaveKey('caption');
    $feed = $this->graph->callsTo('POST', '#/feed$#')[0]['params'];
    expect($feed['message'])->toBe('Album')
        ->and(json_decode($feed['attached_media[0]'], true))->toBe(['media_fbid' => 'ph1'])
        ->and(json_decode($feed['attached_media[2]'], true))->toBe(['media_fbid' => 'ph3']);
    // El orden de las llamadas: todas las fotos antes de publicar.
    $paths = array_column($this->graph->calls, 'path');
    expect(array_search(MP_PAGE.'/feed', $paths, true))->toBe(3);
});

test('varias fotos: guarda cada foto subida y, al reintentar, reutiliza las ya subidas (sin duplicar subidas)', function () {
    $saved = [];
    $this->graph->on('POST', '#^'.MP_PAGE.'/photos$#', FakeGraph::seq(['id' => 'ph1'], FakeGraph::error(2, 'Unavailable', null, 503), ['id' => 'ph2']));
    $this->graph->on('POST', '#^'.MP_PAGE.'/feed$#', ['id' => MP_PAGE.'_901']);

    $request = mpRequest(ContentType::FacebookPhotos, [mpImage(1), mpImage(2)], checkpoint: function ($ref, $progress) use (&$saved) {
        $saved = $progress;
    });
    expect(fn () => $this->fb->publish($this->account, $request))->toThrow(Exception::class);
    expect($saved)->toBe(['photos' => ['1' => ['id' => 'ph1', 'at' => now()->timestamp]]])->and($this->graph->callsTo('POST', '#/feed$#'))->toBeEmpty();

    // Segundo intento con el progreso guardado: solo falta la foto 2.
    $r = $this->fb->publish($this->account, mpRequest(ContentType::FacebookPhotos, [mpImage(1), mpImage(2)], progress: $saved));

    expect($r->isPublished())->toBeTrue()->and($this->graph->callsTo('POST', '#/photos$#'))->toHaveCount(3)
        ->and($this->graph->callsTo('POST', '#/photos$#')[2]['params']['url'])->toBe('https://cdn.test/2.jpg')
        ->and(json_decode($this->graph->callsTo('POST', '#/feed$#')[0]['params']['attached_media[0]'], true))->toBe(['media_fbid' => 'ph1']);
});

function mpPhotos(array $ids, int $ageSeconds = 60): array
{
    return ['photos' => array_map(fn ($id) => ['id' => $id, 'at' => now()->timestamp - $ageSeconds], $ids)];
}

test('varias fotos: las fotos subidas hace mas de 23 h caducaron en Meta y se vuelven a subir (nunca se mandan a /feed)', function () {
    $this->graph->on('POST', '#^'.MP_PAGE.'/photos$#', FakeGraph::seq(['id' => 'nueva1'], ['id' => 'nueva2']));
    $this->graph->on('POST', '#^'.MP_PAGE.'/feed$#', ['id' => MP_PAGE.'_902']);
    $saved = null;

    $r = $this->fb->publish($this->account, mpRequest(ContentType::FacebookPhotos, [mpImage(1), mpImage(2)], progress: mpPhotos(['1' => 'vieja1', '2' => 'vieja2'], 24 * 3600), checkpoint: function ($ref, $progress) use (&$saved) {
        $saved = $progress;
    }));

    expect($r->isPublished())->toBeTrue()->and($this->graph->callsTo('POST', '#/photos$#'))->toHaveCount(2);
    $feed = $this->graph->callsTo('POST', '#/feed$#')[0]['params'];
    expect(json_decode($feed['attached_media[0]'], true))->toBe(['media_fbid' => 'nueva1'])->and($feed)->not->toContain(json_encode(['media_fbid' => 'vieja1']))
        ->and($saved['photos']['2']['id'])->toBe('nueva2');
});

test('varias fotos: una foto subida hace poco se reutiliza y una sin hora guardada se trata como caducada', function () {
    $this->graph->on('POST', '#^'.MP_PAGE.'/photos$#', ['id' => 'nueva']);
    $this->graph->on('POST', '#^'.MP_PAGE.'/feed$#', ['id' => MP_PAGE.'_903']);

    $this->fb->publish($this->account, mpRequest(ContentType::FacebookPhotos, [mpImage(1), mpImage(2)], progress: mpPhotos(['1' => 'reciente'], 22 * 3600)));
    expect($this->graph->callsTo('POST', '#/photos$#'))->toHaveCount(1);

    $this->fb->publish($this->account, mpRequest(ContentType::FacebookPhotos, [mpImage(1), mpImage(2)], progress: ['photos' => ['1' => 'sin_hora', '2' => 'sin_hora2']]));
    expect($this->graph->callsTo('POST', '#/photos$#'))->toHaveCount(3);
});

function mpFeedWith(array $photoIds, string $postId = MP_PAGE.'_77'): array
{
    return ['id' => $postId, 'created_time' => '2026-11-01T12:01:00+0000', 'attachments' => ['data' => [['subattachments' => ['data' => array_map(fn ($id) => ['target' => ['id' => $id]], $photoIds)]]]]];
}

test('album sin texto: se reconoce por las fotos subidas en los adjuntos de /posts; sin coincidencia, null; si la consulta falla, inconcluso', function () {
    $this->graph->on('GET', '#^'.MP_PAGE.'/posts$#', ['data' => [mpFeedWith(['x1', 'x2'], MP_PAGE.'_1'), mpFeedWith(['ph1', 'ph2', 'otra'], MP_PAGE.'_2')]]);

    $found = $this->fb->findPublished($this->account, mpRequest(ContentType::FacebookPhotos, [mpImage(1), mpImage(2)], text: '', progress: mpPhotos(['1' => 'ph1', '2' => 'ph2'])));
    expect($found?->isPublished())->toBeTrue()->and($found->externalId)->toBe(MP_PAGE.'_2');
    expect($this->graph->callsTo('GET', '#/posts$#')[0]['params']['fields'])->toContain('attachments');

    $none = $this->fb->findPublished($this->account, mpRequest(ContentType::FacebookPhotos, [mpImage(1), mpImage(2)], text: '', progress: mpPhotos(['1' => 'zz1', '2' => 'zz2'])));
    expect($none)->toBeNull();
});

test('album sin texto: si la consulta de /posts falla no se devuelve null (se reintentaria y duplicaria): es inconcluso', function () {
    $this->graph->on('GET', '#^'.MP_PAGE.'/posts$#', FakeGraph::error(2, 'Unavailable', null, 503));

    expect(fn () => $this->fb->findPublished($this->account, mpRequest(ContentType::FacebookPhotos, [mpImage(1), mpImage(2)], text: '', progress: mpPhotos(['1' => 'ph1', '2' => 'ph2']))))
        ->toThrow(TransientPublishException::class);
});

test('album sin texto y sin fotos subidas: nada pudo publicarse, null sin consultar', function () {
    expect($this->fb->findPublished($this->account, mpRequest(ContentType::FacebookPhotos, [mpImage(1), mpImage(2)], text: '')))->toBeNull()
        ->and($this->graph->calls)->toBeEmpty();
});

test('album: con fotos caducadas no se reconcilia por ellas (null sin consultar si tampoco hay texto)', function () {
    expect($this->fb->findPublished($this->account, mpRequest(ContentType::FacebookPhotos, [mpImage(1), mpImage(2)], text: '', progress: mpPhotos(['1' => 'ph1', '2' => 'ph2'], 25 * 3600))))->toBeNull()
        ->and($this->graph->calls)->toBeEmpty();
});

test('historia de foto: si /stories falla o no trae los campos esperados es inconcluso, no «no publicada»; una lista bien formada sin la foto, null', function () {
    $progress = mpPhotos(['7' => 'ph7']);
    $this->graph->on('GET', '#^'.MP_PAGE.'/stories$#', FakeGraph::seq(FakeGraph::error(100, 'Unsupported get request', null, 400), ['data' => [['id' => 'raro']]], ['paging' => []], ['data' => [['post_id' => 's1', 'media_id' => 'otra']]]));

    expect(fn () => $this->fb->findPublished($this->account, mpRequest(ContentType::FacebookStory, [mpImage(7)], progress: $progress)))->toThrow(TransientPublishException::class);
    expect(fn () => $this->fb->findPublished($this->account, mpRequest(ContentType::FacebookStory, [mpImage(7)], progress: $progress)))->toThrow(TransientPublishException::class, 'M-04');
    expect(fn () => $this->fb->findPublished($this->account, mpRequest(ContentType::FacebookStory, [mpImage(7)], progress: $progress)))->toThrow(TransientPublishException::class);
    expect($this->fb->findPublished($this->account, mpRequest(ContentType::FacebookStory, [mpImage(7)], progress: $progress)))->toBeNull();
});

test('varias fotos: una sola imagen o un video se rechazan antes de llamar a Meta', function () {
    expect(fn () => $this->fb->publish($this->account, mpRequest(ContentType::FacebookPhotos, [mpImage(1)])))->toThrow(PermanentPublishException::class, 'al menos 2');
    expect(fn () => $this->fb->publish($this->account, mpRequest(ContentType::FacebookPhotos, [mpImage(1), mpVideo()])))->toThrow(PermanentPublishException::class, 'imagen');
    expect($this->graph->calls)->toBeEmpty();
});

test('varias fotos: findPublished reconoce lo ya publicado por texto y hora (sin texto, no)', function () {
    $this->graph->on('GET', '#^'.MP_PAGE.'/posts$#', ['data' => [['id' => MP_PAGE.'_5', 'message' => 'Album', 'permalink_url' => 'https://www.facebook.com/p/5', 'created_time' => '2026-11-01T12:01:00+0000']]]);

    $found = $this->fb->findPublished($this->account, mpRequest(ContentType::FacebookPhotos, [mpImage(1), mpImage(2)]));
    $none = $this->fb->findPublished($this->account, mpRequest(ContentType::FacebookPhotos, [mpImage(1), mpImage(2)], text: ''));

    expect($found?->isPublished())->toBeTrue()->and($found->externalId)->toBe(MP_PAGE.'_5')->and($none)->toBeNull();
});

test('historia de foto: sube la foto sin publicar y la publica con photo_stories (sin texto)', function () {
    $this->graph->on('POST', '#^'.MP_PAGE.'/photos$#', ['id' => 'ph7']);
    $this->graph->on('POST', '#^'.MP_PAGE.'/photo_stories$#', ['success' => true, 'post_id' => 'st_123']);

    $r = $this->fb->publish($this->account, mpRequest(ContentType::FacebookStory, [mpImage(7)], text: 'no se publica'));

    expect($r->isPublished())->toBeTrue()->and($r->externalId)->toBe('st_123');
    expect($this->graph->callsTo('POST', '#/photos$#')[0]['params'])->toMatchArray(['published' => 'false'])
        ->and($this->graph->callsTo('POST', '#/photo_stories$#')[0]['params'])->toBe(['photo_id' => 'ph7']);
});

test('historia de foto: sin post_id usa el id de la foto si Meta responde success; sin nada, falla', function () {
    $this->graph->on('POST', '#^'.MP_PAGE.'/photos$#', ['id' => 'ph7']);
    $this->graph->on('POST', '#^'.MP_PAGE.'/photo_stories$#', FakeGraph::seq(['success' => true], []));

    expect($this->fb->publish($this->account, mpRequest(ContentType::FacebookStory, [mpImage(7)]))->externalId)->toBe('ph7');
    expect(fn () => $this->fb->publish($this->account, mpRequest(ContentType::FacebookStory, [mpImage(7)])))->toThrow(PermanentPublishException::class, 'ID de la historia');
});

test('historia de foto: findPublished la reconoce por el id de la foto subida en /stories; sin coincidencia o sin foto, null', function () {
    $this->graph->on('GET', '#^'.MP_PAGE.'/stories$#', ['data' => [['post_id' => 'st_1', 'media_id' => 'otra'], ['post_id' => 'st_2', 'media_id' => 'ph7', 'url' => 'https://www.facebook.com/stories/2']]]);

    $found = $this->fb->findPublished($this->account, mpRequest(ContentType::FacebookStory, [mpImage(7)], progress: mpPhotos(['7' => 'ph7'])));
    $other = $this->fb->findPublished($this->account, mpRequest(ContentType::FacebookStory, [mpImage(7)], progress: mpPhotos(['7' => 'ph8'])));
    $none = $this->fb->findPublished($this->account, mpRequest(ContentType::FacebookStory, [mpImage(7)]));

    expect($found?->externalId)->toBe('st_2')->and($found->url)->toBe('https://www.facebook.com/stories/2')->and($other)->toBeNull()->and($none)->toBeNull();
});

test('historia de video: video_stories en tres pasos y se sondea por el id del video', function () {
    $this->graph->on('POST', '#^'.MP_PAGE.'/video_stories$#', FakeGraph::seq(['video_id' => '4001', 'upload_url' => 'https://rupload.facebook.com/video-upload/v23.0/4001'], ['success' => true]));
    $this->graph->on('POST', '#^video-upload/#', ['success' => true]);
    $this->graph->on('GET', '#^4001$#', ['status' => ['video_status' => 'ready'], 'permalink_url' => '/stories/4001']);

    $r = $this->fb->publish($this->account, mpRequest(ContentType::FacebookStory, [mpVideo()]));

    expect($r->isProcessing())->toBeTrue()->and($r->reference)->toBe('4001');
    $calls = $this->graph->callsTo('POST', '#/video_stories$#');
    expect($calls[0]['params'])->toBe(['upload_phase' => 'start'])
        ->and($calls[1]['params'])->toBe(['upload_phase' => 'finish', 'video_id' => '4001', 'video_state' => 'PUBLISHED']);
    Http::assertSent(fn ($req) => str_contains($req->url(), 'rupload.facebook.com') && $req->hasHeader('file_url', 'https://cdn.test/v.mp4'));

    $done = $this->fb->findPublished($this->account, mpRequest(ContentType::FacebookStory, [mpVideo()], reference: '4001'));
    expect($done->isPublished())->toBeTrue()->and($done->url)->toBe('https://www.facebook.com/stories/4001');
});

test('los tipos nuevos se ofrecen en Facebook con sus limites de media y la historia se resuelve por la clase de media', function () {
    $types = collect(ContentType::forNetwork(Network::Facebook))->map->value;
    expect($types)->toContain('facebook_photos', 'facebook_story')
        ->and(ContentType::FacebookStory->specKey('video'))->toBe('facebook_story_video')
        ->and(ContentType::FacebookStory->specKey('image'))->toBe('facebook_story_photo')
        ->and(ContentType::FacebookPhotos->specKey())->toBe('facebook_photos')
        ->and(ContentType::FacebookPhotos->label())->toBe('Varias fotos')->and(ContentType::FacebookStory->limitations())->not->toBeEmpty();

    $spec = app(MediaValidator::class)->spec(ContentType::FacebookPhotos);
    expect($spec['media'])->toMatchArray(['min' => 2, 'max' => 10, 'kinds' => ['image']])->and($spec)->toHaveKey('first_comment');
    expect(app(MediaValidator::class)->spec(ContentType::FacebookStory, 'video'))->not->toHaveKey('first_comment');
});

test('list_accounts anuncia los tipos nuevos de Facebook', function () {
    $key = $this->mcpKey($this->user);

    [$err, $res] = $this->callTool($key, 'list_accounts', ['brand_id' => $this->brand->id]);

    expect($err)->toBeFalse();
    $fb = collect($res['accounts'])->firstWhere('network', 'facebook');
    expect($fb['content_types'])->toContain('facebook_photos', 'facebook_story');
});
