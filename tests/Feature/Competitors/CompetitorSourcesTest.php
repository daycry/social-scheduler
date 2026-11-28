<?php

use App\Connectors\CompetitorSources;
use App\Connectors\Contracts\CompetitorSource;
use App\Connectors\Exceptions\QuotaExceededException;
use App\Connectors\Google\YouTubeCompetitorBudget;
use App\Connectors\Google\YouTubeQuota;
use App\Connectors\Network;
use App\Jobs\CollectCompetitorSnapshot;
use App\Jobs\CollectCompetitorSnapshots;
use App\Models\Brand;
use App\Models\Competitor;
use App\Models\CompetitorPost;
use App\Models\CompetitorSnapshot;
use App\Models\SocialAccount;
use App\Models\User;
use App\Services\CompetitorService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\Support\BlueskyFake;
use Tests\Support\McpClient;

uses(McpClient::class);

/**
 * T-25 · fuentes de competidores por red detras de `CompetitorSource`: Bluesky (AppView publico, sin cuenta) y YouTube (Data API con
 * cuota). Todo con Http::fake: ninguna llamada real.
 */
const BS_PROFILE = '*/xrpc/app.bsky.actor.getProfile*';

beforeEach(function () {
    Carbon::setTestNow('2026-11-01 12:00:00');
    Cache::flush();
    Http::preventStrayRequests();
    BlueskyFake::dns();
    config(['social.bluesky.enabled' => true, 'social.competitors.youtube_daily_units' => 300, 'social.youtube.daily_quota' => 10000]);
    $this->user = User::factory()->create();
    $this->brand = Brand::factory()->for($this->user)->create();
});

function ytAccount(): SocialAccount
{
    return SocialAccount::factory()->for(test()->user)->create([
        'brand_id' => test()->brand->id, 'network' => Network::YouTube, 'external_id' => 'UCmiocanal0000000000000', 'username' => 'micanal', 'access_token' => 'TOKEN-YT',
    ]);
}

function bsProfile(array $over = []): array
{
    return $over + ['did' => 'did:plc:rival1234567890abcdefgh', 'handle' => 'rival.bsky.social', 'displayName' => 'El Rival', 'avatar' => 'https://cdn.bsky.app/img/avatar/x.jpg', 'followersCount' => 1500, 'followsCount' => 20, 'postsCount' => 320];
}

function ytChannel(array $over = []): array
{
    return ['items' => [array_replace_recursive([
        'id' => 'UCrival00000000000000000',
        'snippet' => ['title' => 'Canal Rival', 'customUrl' => '@CanalRival', 'thumbnails' => ['default' => ['url' => 'https://yt3.ggpht.com/a.jpg']]],
        'statistics' => ['subscriberCount' => '2500', 'videoCount' => '88', 'viewCount' => '99999', 'hiddenSubscriberCount' => false],
    ], $over)]];
}

// ------------------------------------------------------------------ contrato

test('hay una fuente por red detras del contrato CompetitorSource y solo se ofrecen las disponibles', function () {
    $sources = app(CompetitorSources::class);

    expect($sources->for(Network::Bluesky))->toBeInstanceOf(CompetitorSource::class)
        ->and($sources->for(Network::YouTube))->toBeInstanceOf(CompetitorSource::class)
        ->and($sources->for(Network::Instagram))->toBeInstanceOf(CompetitorSource::class)
        ->and($sources->networks())->toBe([Network::Instagram, Network::YouTube, Network::Bluesky])
        ->and($sources->for(Network::Bluesky)->needsAccount())->toBeFalse()
        ->and($sources->for(Network::YouTube)->needsAccount())->toBeTrue();

    config(['social.bluesky.enabled' => false]);
    expect($sources->networks())->toBe([Network::Instagram, Network::YouTube]);
});

// ------------------------------------------------------------------ Bluesky

test('Bluesky: anadir un competidor lee getProfile publico, sin cuenta ni token, y guarda el snapshot', function () {
    Http::fake([BS_PROFILE => Http::response(bsProfile())]);

    $this->actingAs($this->user)->post(route('competitors.store'), ['brand_id' => $this->brand->id, 'network' => 'bluesky', 'username' => '@Rival.Bsky.Social'])
        ->assertRedirect(route('competitors.index', ['brand_id' => $this->brand->id, 'network' => 'bluesky']))->assertSessionHasNoErrors();

    Http::assertSent(fn ($r) => str_contains($r->url(), 'getProfile') && str_contains($r->url(), 'actor=rival.bsky.social') && ! $r->hasHeader('Authorization'));
    $c = Competitor::query()->firstOrFail();
    $snap = CompetitorSnapshot::query()->firstOrFail();
    expect($c->network)->toBe(Network::Bluesky)->and($c->username)->toBe('rival.bsky.social')->and($c->external_id)->toBe('did:plc:rival1234567890abcdefgh')
        ->and($c->social_account_id)->toBeNull()->and($c->name)->toBe('El Rival')
        ->and($snap->followers_count)->toBe(1500)->and($snap->media_count)->toBe(320);
});

test('Bluesky: perfil inexistente, handle invalido y propia cuenta se rechazan con un mensaje', function () {
    Http::fake([BS_PROFILE => Http::response(['error' => 'InvalidRequest', 'message' => 'Profile not found'], 400)]);

    $this->actingAs($this->user)->post(route('competitors.store'), ['brand_id' => $this->brand->id, 'network' => 'bluesky', 'username' => 'nadie.bsky.social'])
        ->assertSessionHasErrors(['username' => 'No encontramos esa cuenta de Bluesky o esta suspendida o desactivada.']);
    $this->actingAs($this->user)->post(route('competitors.store'), ['brand_id' => $this->brand->id, 'network' => 'bluesky', 'username' => 'sin punto'])
        ->assertSessionHasErrors('username');

    BlueskyFake::account(['username' => 'yo.bsky.social', 'brand_id' => $this->brand->id], $this->user);
    $this->actingAs($this->user)->post(route('competitors.store'), ['brand_id' => $this->brand->id, 'network' => 'bluesky', 'username' => 'yo.bsky.social'])
        ->assertSessionHasErrors(['username' => 'Esa es tu propia cuenta.']);
    expect(Competitor::query()->count())->toBe(0);
});

test('Bluesky: las publicaciones se leen de getAuthorFeed (sin reposts), con me gusta y respuestas', function () {
    Http::fake([
        BS_PROFILE => Http::response(bsProfile()),
        '*/xrpc/app.bsky.feed.getAuthorFeed*' => Http::response(['feed' => [
            ['post' => ['uri' => 'at://did:plc:rival1234567890abcdefgh/app.bsky.feed.post/3kaaa', 'author' => ['handle' => 'rival.bsky.social'], 'record' => ['text' => 'Hola mundo', 'createdAt' => '2026-10-30T10:00:00.000Z'], 'likeCount' => 12, 'replyCount' => 3, 'embed' => ['$type' => 'app.bsky.embed.images#view']]],
            ['post' => ['uri' => 'at://did:plc:otro/app.bsky.feed.post/3kbbb', 'author' => ['handle' => 'otro.bsky.social'], 'record' => ['text' => 'repost']], 'reason' => ['$type' => 'app.bsky.feed.defs#reasonRepost']],
            ['post' => ['uri' => 'at://did:plc:rival1234567890abcdefgh/app.bsky.feed.post/3kccc', 'author' => ['handle' => 'rival.bsky.social'], 'record' => ['text' => 'Sin metricas', 'createdAt' => '2026-10-29T10:00:00.000Z']]],
        ]]),
    ]);
    $this->actingAs($this->user)->post(route('competitors.store'), ['brand_id' => $this->brand->id, 'network' => 'bluesky', 'username' => 'rival.bsky.social']);
    $c = Competitor::query()->firstOrFail();

    $n = app(CompetitorService::class)->refreshPosts($c);

    expect($n)->toBe(2);
    $a = CompetitorPost::query()->where('external_id', '3kaaa')->firstOrFail();
    expect($a->likes_count)->toBe(12)->and($a->comments_count)->toBe(3)->and($a->media_type)->toBe('IMAGE')->and($a->permalink)->toBe('https://bsky.app/profile/rival.bsky.social/post/3kaaa')
        ->and(CompetitorPost::query()->where('external_id', '3kbbb')->exists())->toBeFalse();
    Http::assertSent(fn ($r) => str_contains($r->url(), 'getAuthorFeed') && str_contains($r->url(), 'filter=posts_no_replies') && str_contains($r->url(), rawurlencode('did:plc:rival1234567890abcdefgh')));
});

test('Bluesky: el limite de ritmo (429) del job de snapshot se reintenta con el mecanismo comun', function () {
    Http::fake([BS_PROFILE => Http::sequence()->push(bsProfile())->push(['error' => 'RateLimitExceeded', 'message' => 'Rate Limit Exceeded'], 429)]);
    $this->actingAs($this->user)->post(route('competitors.store'), ['brand_id' => $this->brand->id, 'network' => 'bluesky', 'username' => 'rival.bsky.social']);
    $c = Competitor::query()->firstOrFail();

    $job = (new CollectCompetitorSnapshot($c->id, '2026-11-02'))->withFakeQueueInteractions();
    app()->call([$job, 'handle']);

    $job->assertReleased(120);
});

// ------------------------------------------------------------------ YouTube

test('YouTube: anadir un canal usa channels.list con forHandle y el token propio, cuenta 1 ud en el presupuesto de competidores y en YouTubeQuota y guarda el snapshot', function () {
    ytAccount();
    Http::fake(['*googleapis.com/youtube/v3/channels*' => Http::response(ytChannel())]);

    $this->actingAs($this->user)->post(route('competitors.store'), ['brand_id' => $this->brand->id, 'network' => 'youtube', 'username' => 'https://www.youtube.com/@CanalRival'])
        ->assertSessionHasNoErrors();

    Http::assertSent(fn ($r) => str_contains($r->url(), '/youtube/v3/channels') && $r['forHandle'] === '@canalrival' && $r['part'] === 'snippet,statistics' && $r->header('Authorization')[0] === 'Bearer TOKEN-YT');
    $c = Competitor::query()->firstOrFail();
    $snap = CompetitorSnapshot::query()->firstOrFail();
    expect($c->network)->toBe(Network::YouTube)->and($c->username)->toBe('canalrival')->and($c->external_id)->toBe('UCrival00000000000000000')
        ->and($snap->followers_count)->toBe(2500)->and($snap->media_count)->toBe(88)
        ->and(app(YouTubeCompetitorBudget::class)->used())->toBe(1)->and(app(YouTubeQuota::class)->used())->toBe(1);
});

test('YouTube: sin canal propio conectado se pide conectarlo y un canal con suscriptores ocultos guarda null', function () {
    $this->actingAs($this->user)->post(route('competitors.store'), ['brand_id' => $this->brand->id, 'network' => 'youtube', 'username' => '@canalrival'])
        ->assertSessionHasErrors(['username' => 'Conecta una cuenta de YouTube activa en esta marca para comparar.']);

    ytAccount();
    Http::fake(['*googleapis.com/youtube/v3/channels*' => Http::response(ytChannel(['statistics' => ['hiddenSubscriberCount' => true]]))]);
    $this->actingAs($this->user)->post(route('competitors.store'), ['brand_id' => $this->brand->id, 'network' => 'youtube', 'username' => 'UCrival00000000000000000'])->assertSessionHasNoErrors();

    Http::assertSent(fn ($r) => ($r['id'] ?? null) === 'UCrival00000000000000000');
    expect(CompetitorSnapshot::query()->firstOrFail()->followers_count)->toBeNull();
});

test('YouTube: las publicaciones salen de playlistItems + videos (2 ud) y lo que el video oculta es null', function () {
    ytAccount();
    Http::fake([
        '*youtube/v3/channels*' => Http::response(ytChannel()),
        '*youtube/v3/playlistItems*' => Http::response(['items' => [
            ['snippet' => ['title' => 'Video uno', 'publishedAt' => '2026-10-30T10:00:00Z', 'resourceId' => ['videoId' => 'vid00000001']]],
            ['snippet' => ['title' => 'Video dos', 'publishedAt' => '2026-10-29T10:00:00Z', 'resourceId' => ['videoId' => 'vid00000002']]],
        ]]),
        '*youtube/v3/videos*' => Http::response(['items' => [
            ['id' => 'vid00000001', 'statistics' => ['viewCount' => '900', 'likeCount' => '40', 'commentCount' => '5']],
            ['id' => 'vid00000002', 'statistics' => ['viewCount' => '50']],
        ]]),
    ]);
    $this->actingAs($this->user)->post(route('competitors.store'), ['brand_id' => $this->brand->id, 'network' => 'youtube', 'username' => '@canalrival']);
    $c = Competitor::query()->firstOrFail();

    expect(app(CompetitorService::class)->refreshPosts($c))->toBe(2);

    Http::assertSent(fn ($r) => str_contains($r->url(), 'playlistItems') && $r['playlistId'] === 'UUrival00000000000000000');
    $one = CompetitorPost::query()->where('external_id', 'vid00000001')->firstOrFail();
    $two = CompetitorPost::query()->where('external_id', 'vid00000002')->firstOrFail();
    expect($one->likes_count)->toBe(40)->and($one->comments_count)->toBe(5)->and($one->permalink)->toBe('https://www.youtube.com/watch?v=vid00000001')->and($one->media_type)->toBe('VIDEO')
        ->and($two->likes_count)->toBeNull()->and($two->comments_count)->toBeNull()
        ->and(app(YouTubeCompetitorBudget::class)->used())->toBe(3); // 1 del alta + 2 de las publicaciones
});

test('YouTube: sin presupuesto diario no se llama a la API y el alta explica la cuota', function () {
    ytAccount();
    config(['social.competitors.youtube_daily_units' => 0]);
    Http::fake();

    $this->actingAs($this->user)->post(route('competitors.store'), ['brand_id' => $this->brand->id, 'network' => 'youtube', 'username' => '@canalrival'])
        ->assertSessionHasErrors(['username' => 'La cuota diaria de YouTube para competidores esta agotada. Se libera pronto; intentalo mas tarde.']);

    Http::assertNothingSent();
    expect(Competitor::query()->count())->toBe(0);
});

test('YouTube: 403 quotaExceeded marca el dia agotado y el job de snapshot se reintenta con espera creciente y tope', function () {
    ytAccount();
    Http::fake(['*youtube/v3/channels*' => Http::sequence()->push(ytChannel())
        ->push(['error' => ['code' => 403, 'message' => 'quota', 'errors' => [['reason' => 'quotaExceeded']]]], 403)]);
    $this->actingAs($this->user)->post(route('competitors.store'), ['brand_id' => $this->brand->id, 'network' => 'youtube', 'username' => '@canalrival']);
    $c = Competitor::query()->firstOrFail();

    $job = (new CollectCompetitorSnapshot($c->id, '2026-11-02'))->withFakeQueueInteractions();
    app()->call([$job, 'handle']);

    // Espera: al menos hasta que se libere el cupo (medianoche de Pacifico) con tope de una hora.
    $job->assertReleased(3600);
    // El dia queda marcado como agotado: la siguiente llamada ni sale a la red.
    expect(app(YouTubeQuota::class)->used())->toBe(10000);
    Http::assertSentCount(2);
});

test('YouTube: un 429 de ritmo respeta Retry-After y se reintenta; un 401 es transitorio (el token se renueva) y NO bloquea el canal', function () {
    $account = ytAccount();
    Http::fake(['*youtube/v3/channels*' => Http::sequence()->push(ytChannel())
        ->push(['error' => ['code' => 429, 'message' => 'slow down']], 429, ['Retry-After' => '45'])
        ->push(['error' => ['code' => 401, 'message' => 'bad token', 'errors' => [['reason' => 'authError']]]], 401)]);
    $this->actingAs($this->user)->post(route('competitors.store'), ['brand_id' => $this->brand->id, 'network' => 'youtube', 'username' => '@canalrival']);
    $c = Competitor::query()->firstOrFail();

    $job = (new CollectCompetitorSnapshot($c->id, '2026-11-02'))->withFakeQueueInteractions();
    app()->call([$job, 'handle']);
    $job->assertReleased(120); // backoff del job (120 s) > Retry-After (45 s)

    $job2 = (new CollectCompetitorSnapshot($c->id, '2026-11-03'))->withFakeQueueInteractions();
    app()->call([$job2, 'handle']);

    // Como en Analytics: el token pudo caducar entre renovaciones; se reintenta y el canal (y sus subidas) siguen activos.
    $job2->assertReleased(120);
    expect($account->fresh()->status)->toBe(SocialAccount::STATUS_ACTIVE);
});

// ------------------------------------------------------------------ UI y comparativa por red

test('la comparativa y la pagina se filtran por red y ofrecen la eleccion de red', function () {
    ytAccount();
    Http::fake([BS_PROFILE => Http::response(bsProfile()), '*youtube/v3/channels*' => Http::response(ytChannel())]);
    $this->actingAs($this->user)->post(route('competitors.store'), ['brand_id' => $this->brand->id, 'network' => 'bluesky', 'username' => 'rival.bsky.social']);
    $this->actingAs($this->user)->post(route('competitors.store'), ['brand_id' => $this->brand->id, 'network' => 'youtube', 'username' => '@canalrival']);

    $this->actingAs($this->user)->get(route('competitors.index', ['brand_id' => $this->brand->id, 'network' => 'bluesky']))->assertInertia(fn ($page) => $page
        ->component('Competitors/Index')->where('network', 'bluesky')->where('has_account', true)
        ->has('comparison.competitors', 1)->where('comparison.competitors.0.username', 'rival.bsky.social')
        ->where('networks.0.value', 'instagram')->where('networks.0.has_account', false)->where('networks.2.value', 'bluesky')->where('networks.2.needs_account', false));

    $this->actingAs($this->user)->get(route('competitors.index', ['brand_id' => $this->brand->id]))->assertInertia(fn ($page) => $page
        ->where('network', 'instagram')->where('has_account', false)->has('comparison.competitors', 0));

    // Una red sin fuente no se admite.
    $this->actingAs($this->user)->get(route('competitors.index', ['brand_id' => $this->brand->id, 'network' => 'tiktok']))->assertSessionHasErrors('network');
});

// ------------------------------------------------------------------ revision intento 1: reparto del presupuesto de YouTube

test('el presupuesto de YouTube tiene un tope por usuario: uno no deja sin datos a los demas', function () {
    config(['social.competitors.youtube_daily_units' => 100, 'social.competitors.youtube_user_daily_units' => 3]);
    $budget = app(YouTubeCompetitorBudget::class);

    $budget->assertAllows(1, 1);
    $budget->charge(3, 1);

    expect(fn () => $budget->assertAllows(1, 1))->toThrow(QuotaExceededException::class, 'tu parte');
    $budget->assertAllows(1, 2); // otro usuario sigue teniendo cupo
    $budget->charge(1, 2);
    expect($budget->used())->toBe(4)->and($budget->usedBy(1))->toBe(3)->and($budget->usedBy(2))->toBe(1);

    config(['social.competitors.youtube_daily_units' => 4]);
    expect(fn () => $budget->assertAllows(1, 2))->toThrow(QuotaExceededException::class, 'de toda la instalacion');
});

test('el despachador procesa primero a los competidores sin datos y a los que llevan mas tiempo sin sincronizar', function () {
    Queue::fake();
    $mk = fn (string $name, ?string $synced) => tap(new Competitor(['network' => Network::Bluesky, 'username' => $name, 'last_synced_at' => $synced]), function ($c) {
        $c->user_id = test()->user->id;
        $c->brand_id = test()->brand->id;
        $c->save();
    });
    $reciente = $mk('reciente.bsky.social', '2026-11-01 11:00:00');
    $nunca = $mk('nunca.bsky.social', null);
    $antiguo = $mk('antiguo.bsky.social', '2026-10-20 11:00:00');

    (new CollectCompetitorSnapshots)->handle();

    $order = [];
    Queue::assertPushed(CollectCompetitorSnapshot::class, function ($job) use (&$order) {
        $order[] = $job->competitorId;

        return true;
    });
    expect($order)->toBe([$nunca->id, $antiguo->id, $reciente->id]);
});

// ------------------------------------------------------------------ revision intento 1: MCP y redes deshabilitadas

test('MCP compare_competitors valida la red contra las que tienen fuente y devuelve la de Bluesky', function () {
    Http::fake([BS_PROFILE => Http::response(bsProfile())]);
    $this->actingAs($this->user)->post(route('competitors.store'), ['brand_id' => $this->brand->id, 'network' => 'bluesky', 'username' => 'rival.bsky.social']);
    $key = $this->mcpKey($this->user, ['stats:read']);

    [$err, $res] = $this->callTool($key, 'compare_competitors', ['brand_id' => $this->brand->id, 'network' => 'bluesky']);
    expect($err)->toBeFalse()->and($res['network'])->toBe('bluesky')->and($res['competitors'])->toHaveCount(1);

    foreach (['tiktok', 'nope'] as $bad) {
        [$err] = $this->callTool($key, 'compare_competitors', ['brand_id' => $this->brand->id, 'network' => $bad]);
        expect($err)->toBeTrue();
    }

    config(['social.bluesky.enabled' => false]);
    [$err] = $this->callTool($key, 'compare_competitors', ['brand_id' => $this->brand->id, 'network' => 'bluesky']);
    expect($err)->toBeTrue();
});

test('con una red deshabilitada la fuente no se consulta (ni en jobs ni en el servicio)', function () {
    Http::fake([BS_PROFILE => Http::response(bsProfile())]);
    $this->actingAs($this->user)->post(route('competitors.store'), ['brand_id' => $this->brand->id, 'network' => 'bluesky', 'username' => 'rival.bsky.social']);
    $c = Competitor::query()->firstOrFail();
    config(['social.bluesky.enabled' => false]);
    Http::fake();

    expect(fn () => app(CompetitorSources::class)->for(Network::Bluesky))->toThrow(InvalidArgumentException::class);
    app()->call([new CollectCompetitorSnapshot($c->id, '2026-11-02'), 'handle']);
    Http::assertNothingSent();
});
