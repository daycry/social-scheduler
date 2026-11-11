<?php

use App\Connectors\Bluesky\BlueskyPublisher;
use App\Connectors\Meta\Facebook\FacebookPublisher;
use App\Connectors\Meta\Instagram\InstagramPublisher;
use App\Connectors\Network;
use App\Connectors\Threads\ThreadsPublisher;
use App\Enums\PostStatus;
use App\Exceptions\PostValidationException;
use App\Models\Brand;
use App\Models\MediaAsset;
use App\Models\Post;
use App\Models\PostTarget;
use App\Models\SocialAccount;
use App\Models\User;
use App\Services\PostService;
use App\Services\PublishRequestFactory;
use App\Support\TargetMedia;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\Support\BlueskyFake;
use Tests\Support\FakeGraph;
use Tests\Support\ThreadsFake;

// T-17: media propia por destino. null = hereda la comun; lista = ids, orden, alt y portada propios. Lo que se valida es lo que se publica.

beforeEach(function () {
    Carbon::setTestNow('2026-11-01 12:00:00');
    Queue::fake();
    Storage::fake('local');
    config(['filesystems.media' => 'local']);
    $this->user = User::factory()->create();
    $this->brand = Brand::factory()->for($this->user)->create();
    $acc = fn (Network $n, array $over = []) => SocialAccount::factory()->for($this->user)->create($over + ['brand_id' => $this->brand->id, 'network' => $n]);
    $this->ig = $acc(Network::Instagram, ['external_id' => '17841400001', 'name' => 'ana_ig']);
    $this->fb = $acc(Network::Facebook, ['external_id' => '1065269806670394', 'name' => 'Pagina Ana']);
    $this->service = app(PostService::class);
    $this->a = tmAsset($this->user, 'A');
    $this->b = tmAsset($this->user, 'B');
    $this->c = tmAsset($this->user, 'C');
});

/** Imagen JPEG real (GD) en el disco de media; sirve a Bluesky, que lee los bytes. */
function tmAsset(User $user, string $name, int $w = 400, int $h = 400): MediaAsset
{
    $asset = MediaAsset::factory()->for($user)->create(['original_name' => "$name.jpg", 'width' => $w, 'height' => $h]);
    $im = imagecreatetruecolor($w, $h);
    imagefill($im, 0, 0, imagecolorallocate($im, ord($name) % 255, 80, 120));
    ob_start();
    imagejpeg($im);
    $bytes = (string) ob_get_clean();
    Storage::disk('local')->put($asset->path, $bytes);
    $asset->forceFill(['size' => strlen($bytes)])->save();

    return $asset;
}

function tmData(array $targets, array $media, array $extra = []): array
{
    return $extra + ['text' => 'Hola', 'scheduled_at' => now()->addDay(), 'schedule' => true, 'media' => $media, 'targets' => $targets];
}

function tmErrors(callable $fn): array
{
    try {
        $fn();
    } catch (PostValidationException $e) {
        return $e->errors();
    }

    return [];
}

test('TargetMedia: sin media propia el destino hereda la comun y no se guarda nada en el destino', function () {
    $post = $this->service->create($this->user, $this->brand, tmData([['account_id' => $this->ig->id, 'content_type' => 'instagram_carousel']], [$this->a->id, $this->b->id]));

    $target = $post->targets->first();
    expect($target->media)->toBeNull()
        ->and(TargetMedia::effective($target, $post->media->pluck('id')->all()))->toBe([
            ['id' => $this->a->id, 'alt' => null, 'cover_id' => null], ['id' => $this->b->id, 'alt' => null, 'cover_id' => null],
        ]);
});

test('TargetMedia: un destino guarda su seleccion, orden y alt sin tocar la media comun ni los demas destinos', function () {
    $post = $this->service->create($this->user, $this->brand, tmData([
        ['account_id' => $this->ig->id, 'content_type' => 'instagram_carousel', 'media' => [['id' => $this->c->id, 'alt' => '  Tres  '], ['id' => $this->a->id, 'alt' => 'Uno']]],
        ['account_id' => $this->fb->id, 'content_type' => 'facebook_photo', 'media' => null],
    ], [$this->a->id]));

    $ig = $post->targets->firstWhere('social_account_id', $this->ig->id);
    $fb = $post->targets->firstWhere('social_account_id', $this->fb->id);
    expect($post->media->pluck('id')->all())->toBe([$this->a->id])
        ->and($ig->media)->toBe([['id' => $this->c->id, 'alt' => 'Tres', 'cover_id' => null], ['id' => $this->a->id, 'alt' => 'Uno', 'cover_id' => null]])
        ->and($fb->media)->toBeNull();
});

test('TargetMedia: se valida la media efectiva del destino con los validadores de la red y el error nombra el destino', function () {
    $errors = tmErrors(fn () => $this->service->create($this->user, $this->brand, tmData([
        ['account_id' => $this->fb->id, 'content_type' => 'facebook_photo', 'media' => [['id' => $this->a->id], ['id' => $this->b->id]]],
    ], [$this->a->id])));

    expect($errors['targets.0'][0])->toContain('Media propia de Pagina Ana (Facebook)')->toContain('admite como maximo 1 archivo');
    // La misma regla sobre la media comun: sin prefijo (el comportamiento de siempre).
    $common = tmErrors(fn () => $this->service->create($this->user, $this->brand, tmData([['account_id' => $this->fb->id, 'content_type' => 'facebook_photo']], [$this->a->id, $this->b->id])));
    expect($common['targets.0'][0])->not->toContain('Media propia');
});

test('TargetMedia: un destino con media propia valida la propia aunque la comun fuera invalida para esa red', function () {
    // Facebook foto admite 1 archivo: la comun tiene 2, la propia 1.
    $post = $this->service->create($this->user, $this->brand, tmData([
        ['account_id' => $this->fb->id, 'content_type' => 'facebook_photo', 'media' => [['id' => $this->b->id]]],
    ], [$this->a->id, $this->b->id]));

    expect($post->targets->first()->media)->toBe([['id' => $this->b->id, 'alt' => null, 'cover_id' => null]]);
});

test('TargetMedia: alt solo donde la red lo publica, solo en imagenes y con tope; la portada solo en videos y donde se publica', function () {
    $video = MediaAsset::factory()->video()->for($this->user)->create();
    Storage::disk('local')->put($video->path, 'v');

    $tiktok = tmErrors(fn () => $this->service->create($this->user, $this->brand, tmData([
        ['account_id' => $this->ig->id, 'content_type' => 'instagram_story', 'media' => [['id' => $this->a->id, 'alt' => 'x']]],
    ], [])));
    expect(implode(' ', $tiktok['targets.0']))->toContain('Media propia de ana_ig (Instagram)')->toContain('no admite texto alternativo');

    $long = tmErrors(fn () => $this->service->create($this->user, $this->brand, tmData([
        ['account_id' => $this->ig->id, 'content_type' => 'instagram_image', 'media' => [['id' => $this->a->id, 'alt' => str_repeat('a', 1001)]]],
    ], [])));
    expect(implode(' ', $long['targets.0']))->toContain('admite como maximo 1000 caracteres');

    $cover = tmErrors(fn () => $this->service->create($this->user, $this->brand, tmData([
        ['account_id' => $this->ig->id, 'content_type' => 'instagram_image', 'media' => [['id' => $this->a->id, 'cover_id' => $this->b->id]]],
    ], [])));
    expect(implode(' ', $cover['targets.0']))->toContain('Instagram no admite portada en');

    $coverOnImage = tmErrors(fn () => $this->service->create($this->user, $this->brand, tmData([
        ['account_id' => $this->ig->id, 'content_type' => 'instagram_reel', 'media' => [['id' => $this->a->id, 'cover_id' => $this->b->id]]],
    ], [])));
    expect(implode(' ', $coverOnImage['targets.0']))->toContain('la portada solo se aplica a videos');

    $reel = tmErrors(fn () => $this->service->create($this->user, $this->brand, tmData([
        ['account_id' => $this->ig->id, 'content_type' => 'instagram_reel', 'media' => [['id' => $video->id, 'cover_id' => $video->id]]],
    ], [])));
    expect(implode(' ', $reel['targets.0']))->toContain('la portada del archivo 1 debe ser una imagen lista');

    $ok = $this->service->create($this->user, $this->brand, tmData([
        ['account_id' => $this->ig->id, 'content_type' => 'instagram_reel', 'media' => [['id' => $video->id, 'cover_id' => $this->a->id]]],
    ], []));
    expect($ok->targets->first()->media)->toBe([['id' => $video->id, 'alt' => null, 'cover_id' => $this->a->id]]);
});

test('TargetMedia: archivos ajenos, inexistentes o repetidos se rechazan por destino', function () {
    $other = tmAsset(User::factory()->create(), 'Z');

    $errors = tmErrors(fn () => $this->service->create($this->user, $this->brand, tmData([
        ['account_id' => $this->ig->id, 'content_type' => 'instagram_carousel', 'media' => [['id' => $other->id], ['id' => $this->a->id], ['id' => $this->a->id]]],
    ], [])));

    expect(implode(' ', $errors['targets.0']))->toContain("el archivo {$other->id} no existe")->toContain("el archivo {$this->a->id} esta repetido");
});

test('TargetMedia: editar la media de un destino reinicia solo ese intento; quitar la propia vuelve a heredar', function () {
    $post = $this->service->create($this->user, $this->brand, tmData([
        ['account_id' => $this->ig->id, 'content_type' => 'instagram_carousel', 'media' => [['id' => $this->a->id], ['id' => $this->b->id]]],
        ['account_id' => $this->fb->id, 'content_type' => 'facebook_photo'],
    ], [$this->a->id]));
    $post->targets()->get()->each(fn ($t) => $t->forceFill(['attempts' => 2, 'error_code' => 'transitorio'])->save());

    $same = fn () => $this->service->update($this->user, $post->fresh(), tmData([
        ['account_id' => $this->ig->id, 'content_type' => 'instagram_carousel', 'media' => [['id' => $this->a->id], ['id' => $this->b->id]]],
        ['account_id' => $this->fb->id, 'content_type' => 'facebook_photo'],
    ], [$this->a->id]));
    $same();
    expect($post->targets()->pluck('attempts')->all())->toBe([2, 2]);

    $this->service->update($this->user, $post->fresh(), tmData([
        ['account_id' => $this->ig->id, 'content_type' => 'instagram_carousel', 'media' => [['id' => $this->b->id], ['id' => $this->a->id]]],
        ['account_id' => $this->fb->id, 'content_type' => 'facebook_photo'],
    ], [$this->a->id]));
    $byAccount = $post->targets()->get()->keyBy('social_account_id');
    expect($byAccount[$this->ig->id]->attempts)->toBe(0)->and($byAccount[$this->fb->id]->attempts)->toBe(2);

    $this->service->update($this->user, $post->fresh(), tmData([
        ['account_id' => $this->ig->id, 'content_type' => 'instagram_carousel'],
    ], [$this->a->id, $this->b->id]));
    expect($post->targets()->first()->media)->toBeNull();
});

test('TargetMedia: publicar ahora y programar conservan la media propia de cada destino', function () {
    $post = $this->service->create($this->user, $this->brand, tmData([
        ['account_id' => $this->ig->id, 'content_type' => 'instagram_carousel', 'media' => [['id' => $this->c->id, 'alt' => 'Tres'], ['id' => $this->b->id]]],
    ], [$this->a->id, $this->b->id], ['schedule' => false, 'scheduled_at' => null]));

    $scheduled = $this->service->schedule($this->user, $post, now()->addDay());

    expect($scheduled->status)->toBe(PostStatus::Scheduled)
        ->and($scheduled->targets->first()->media)->toBe([['id' => $this->c->id, 'alt' => 'Tres', 'cover_id' => null], ['id' => $this->b->id, 'alt' => null, 'cover_id' => null]]);
});

// ------------------------------------------------------------------ la publicacion usa exactamente la media efectiva

function tmSent(string $suffix, string $method = 'POST'): array
{
    return Http::recorded()->filter(fn ($p) => $p[0]->method() === $method && str_ends_with(parse_url($p[0]->url(), PHP_URL_PATH), $suffix))->map(fn ($p) => $p[0])->values()->all();
}

function tmTarget(Post $post, SocialAccount $account): PostTarget
{
    return $post->targets()->where('social_account_id', $account->id)->firstOrFail();
}

test('TargetMedia: Instagram carrusel publica los ids, el orden y el alt del destino (no la media comun)', function () {
    $graph = new FakeGraph;
    $graph->on('GET', '#/content_publishing_limit$#', ['data' => []])
        ->on('POST', '#/media$#', FakeGraph::seq(['id' => 'C1'], ['id' => 'C2'], ['id' => 'PARENT']))
        ->on('GET', '#^C1$#', ['status_code' => 'FINISHED'])->on('GET', '#^C2$#', ['status_code' => 'FINISHED'])->on('GET', '#^PARENT$#', ['status_code' => 'FINISHED'])
        ->on('POST', '#/media_publish$#', ['id' => 'IGPOST'])->on('GET', '#^IGPOST$#', ['permalink' => 'https://www.instagram.com/p/x/']);

    $post = $this->service->create($this->user, $this->brand, tmData([
        ['account_id' => $this->ig->id, 'content_type' => 'instagram_carousel', 'media' => [['id' => $this->c->id, 'alt' => 'Tres'], ['id' => $this->a->id, 'alt' => 'Uno']]],
    ], [$this->a->id, $this->b->id]));
    $request = app(PublishRequestFactory::class)->make(tmTarget($post, $this->ig));

    expect(array_map(fn ($m) => $m->id, $request->media))->toBe([$this->c->id, $this->a->id])
        ->and(array_map(fn ($m) => $m->alt, $request->media))->toBe(['Tres', 'Uno']);

    app(InstagramPublisher::class)->publish($this->ig, $request);

    $children = array_slice($graph->callsTo('POST', '#/media$#'), 0, 2);
    expect($children[0]['params'])->toMatchArray(['alt_text' => 'Tres', 'is_carousel_item' => 'true'])
        ->and($children[0]['params']['image_url'])->toContain(basename($this->c->path))
        ->and($children[1]['params'])->toMatchArray(['alt_text' => 'Uno'])
        ->and($children[1]['params']['image_url'])->toContain(basename($this->a->path))
        ->and($graph->callsTo('POST', '#/media$#')[2]['params']['children'])->toBe('C1,C2');
});

test('TargetMedia: Instagram Reel publica la portada propia (cover_url) del destino', function () {
    $video = MediaAsset::factory()->video()->for($this->user)->create();
    Storage::disk('local')->put($video->path, 'v');
    $graph = new FakeGraph;
    $graph->on('GET', '#/content_publishing_limit$#', ['data' => []])->on('POST', '#/media$#', ['id' => 'R1'])
        ->on('GET', '#^R1$#', ['status_code' => 'FINISHED'])->on('POST', '#/media_publish$#', ['id' => 'R2'])->on('GET', '#^R2$#', ['permalink' => 'https://www.instagram.com/reel/x/']);

    $post = $this->service->create($this->user, $this->brand, tmData([
        ['account_id' => $this->ig->id, 'content_type' => 'instagram_reel', 'media' => [['id' => $video->id, 'cover_id' => $this->b->id]]],
    ], [$video->id]));
    app(InstagramPublisher::class)->publish($this->ig, app(PublishRequestFactory::class)->make(tmTarget($post, $this->ig)));

    $params = $graph->callsTo('POST', '#/media$#')[0]['params'];
    expect($params['video_url'])->toContain(basename($video->path))->and($params['cover_url'])->toContain(basename($this->b->path));
});

test('TargetMedia: un destino que hereda no envia alt ni portada y publica la comun en su orden', function () {
    $graph = new FakeGraph;
    $graph->on('POST', '#/photos$#', ['id' => '7001', 'post_id' => 'P_7002']);

    $post = $this->service->create($this->user, $this->brand, tmData([['account_id' => $this->fb->id, 'content_type' => 'facebook_photo']], [$this->b->id]));
    app(FacebookPublisher::class)->publish($this->fb, app(PublishRequestFactory::class)->make(tmTarget($post, $this->fb)));

    $params = $graph->callsTo('POST', '#/photos$#')[0]['params'];
    expect($params['url'])->toContain(basename($this->b->path))->and($params)->not->toHaveKey('alt_text_custom');
});

test('TargetMedia: Facebook foto publica el alt propio (alt_text_custom)', function () {
    $graph = new FakeGraph;
    $graph->on('POST', '#/photos$#', ['id' => '7001', 'post_id' => 'P_7002']);

    $post = $this->service->create($this->user, $this->brand, tmData([
        ['account_id' => $this->fb->id, 'content_type' => 'facebook_photo', 'media' => [['id' => $this->c->id, 'alt' => 'Una playa']]],
    ], [$this->a->id]));
    app(FacebookPublisher::class)->publish($this->fb, app(PublishRequestFactory::class)->make(tmTarget($post, $this->fb)));

    $params = $graph->callsTo('POST', '#/photos$#')[0]['params'];
    expect($params['alt_text_custom'])->toBe('Una playa')->and($params['url'])->toContain(basename($this->c->path));
});

test('TargetMedia: Threads carrusel publica los hijos con su alt_text en el orden del destino', function () {
    $threads = ThreadsFake::account([], $this->user, $this->brand);
    $ids = ['T1', 'T2', 'TP'];
    Http::fake([
        'graph.threads.com/v1.0/'.ThreadsFake::USER_ID.'/threads_publishing_limit*' => Http::response(['data' => [['quota_usage' => 1, 'config' => ['quota_total' => 250, 'quota_duration' => 86400]]]]),
        'graph.threads.com/v1.0/'.ThreadsFake::USER_ID.'/threads_publish' => Http::response(['id' => 'POST9']),
        'graph.threads.com/v1.0/'.ThreadsFake::USER_ID.'/threads' => function () use (&$ids) {
            return Http::response(['id' => array_shift($ids)]);
        },
        'graph.threads.com/v1.0/POST9*' => Http::response(['id' => 'POST9', 'permalink' => 'https://www.threads.com/@ana/post/x']),
        'graph.threads.com/v1.0/*' => Http::response(['status' => 'FINISHED']),
    ]);

    $post = $this->service->create($this->user, $this->brand, tmData([
        ['account_id' => $threads->id, 'content_type' => 'threads_carousel', 'media' => [['id' => $this->b->id, 'alt' => 'Segunda'], ['id' => $this->c->id]]],
    ], [$this->a->id, $this->b->id]));
    app(ThreadsPublisher::class)->publish($threads, app(PublishRequestFactory::class)->make(tmTarget($post, $threads)));

    $creates = tmSent('/threads');
    expect($creates)->toHaveCount(3)
        ->and($creates[0]->data())->toMatchArray(['alt_text' => 'Segunda', 'media_type' => 'IMAGE'])
        ->and($creates[0]->data()['image_url'])->toContain(basename($this->b->path))
        ->and($creates[1]->data())->not->toHaveKey('alt_text')
        ->and($creates[1]->data()['image_url'])->toContain(basename($this->c->path));
});

test('TargetMedia: Bluesky publica solo las imagenes del destino con su alt y los bytes de cada una', function () {
    config(['social.bluesky.image_max_bytes' => 2_000_000]);
    $bsky = BlueskyFake::account(['brand_id' => $this->brand->id], $this->user);
    Http::fake([
        'pds.example.com/xrpc/com.atproto.repo.uploadBlob' => Http::sequence()
            ->push(['blob' => ['$type' => 'blob', 'ref' => ['$link' => 'bafkB'], 'mimeType' => 'image/jpeg', 'size' => 10]])
            ->push(['blob' => ['$type' => 'blob', 'ref' => ['$link' => 'bafkC'], 'mimeType' => 'image/jpeg', 'size' => 10]]),
        'pds.example.com/xrpc/com.atproto.repo.createRecord' => Http::response(['uri' => 'at://'.BlueskyFake::DID.'/app.bsky.feed.post/3kabcdefghij2', 'cid' => 'bafyreicid']),
    ]);

    $post = $this->service->create($this->user, $this->brand, tmData([
        ['account_id' => $bsky->id, 'content_type' => 'bluesky_post', 'media' => [['id' => $this->b->id, 'alt' => 'La B'], ['id' => $this->c->id, 'alt' => 'La C']]],
    ], [$this->a->id]));
    app(BlueskyPublisher::class)->publish($bsky, app(PublishRequestFactory::class)->make(tmTarget($post, $bsky)));

    $uploads = tmSent('uploadBlob');
    $images = tmSent('createRecord')[0]['record']['embed']['images'];
    expect($uploads)->toHaveCount(2)
        ->and($uploads[0]->body())->toBe(Storage::disk('local')->get($this->b->path))
        ->and($uploads[1]->body())->toBe(Storage::disk('local')->get($this->c->path))
        ->and(array_column($images, 'alt'))->toBe(['La B', 'La C'])
        ->and($images[0]['image']['ref']['$link'])->toBe('bafkB')->and($images[1]['image']['ref']['$link'])->toBe('bafkC');
});
