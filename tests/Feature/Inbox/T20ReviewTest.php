<?php

use App\Connectors\Google\YouTubeQuota;
use App\Connectors\Network;
use App\Enums\PostStatus;
use App\Exceptions\PostValidationException;
use App\Jobs\SyncAccountComments;
use App\Jobs\SyncInboxComments;
use App\Models\Brand;
use App\Models\Competitor;
use App\Models\InboxComment;
use App\Models\Post;
use App\Models\PostTarget;
use App\Models\SmartLink;
use App\Models\SmartLinkSubscriber;
use App\Models\SocialAccount;
use App\Models\User;
use App\Services\CompetitorService;
use App\Services\InboxService;
use App\Services\SmartLinkService;
use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\Support\FakeGraph;

/** T-20 · revision intento 1: cupo de YouTube, papelera, formulario de email, fechas, topes de competidores y respuesta a respuestas. */
beforeEach(function () {
    Carbon::setTestNow('2026-11-10 12:00:00');
    Http::preventStrayRequests();
    Cache::flush();
    $this->user = User::factory()->create();
    $this->brand = Brand::factory()->for($this->user)->create(['timezone' => 'Europe/Madrid']);
});

function t20rYoutube(object $t, int $videos): SocialAccount
{
    $a = SocialAccount::factory()->for($t->user)->create([
        'brand_id' => $t->brand->id, 'network' => Network::YouTube, 'external_id' => 'UC-canal', 'access_token' => 'YT',
        'scopes' => ['https://www.googleapis.com/auth/youtube', 'https://www.googleapis.com/auth/youtube.force-ssl'],
    ]);
    for ($i = 1; $i <= $videos; $i++) {
        $post = Post::factory()->create(['brand_id' => $t->brand->id, 'user_id' => $t->user->id]);
        PostTarget::factory()->create(['post_id' => $post->id, 'social_account_id' => $a->id, 'network' => Network::YouTube, 'status' => PostStatus::Published,
            'external_id' => 'VID'.str_pad((string) $i, 8, '0', STR_PAD_LEFT), 'external_url' => 'https://youtu.be/x', 'published_at' => now()->subHours($i)]);
    }

    return $a;
}

test('1. YouTube: el inbox solo lee los 10 videos mas recientes', function () {
    $a = t20rYoutube($this, 12);
    Http::fake(['www.googleapis.com/youtube/v3/commentThreads*' => Http::response(['items' => []])]);

    app(InboxService::class)->sync($a);

    Http::assertSentCount(10);
});

test('1. YouTube: el presupuesto diario del inbox corta el sync y deja el cupo restante para subir', function () {
    config(['social.inbox.youtube_daily_units' => 3]);
    $a = t20rYoutube($this, 8);
    Http::fake(['www.googleapis.com/youtube/v3/commentThreads*' => Http::response(['items' => []])]);

    app(InboxService::class)->sync($a);
    Http::assertSentCount(3);
    expect((new YouTubeQuota)->used())->toBe(3);

    app(InboxService::class)->sync($a); // mismo dia: nada mas
    Http::assertSentCount(3);

    // El presupuesto es por dia del Pacifico: al dia siguiente vuelve.
    Carbon::setTestNow('2026-11-11 12:00:00');
    app(InboxService::class)->sync($a);
    Http::assertSentCount(6);
});

test('1. YouTube: responder tambien respeta el presupuesto y no toca el cupo si no cabe', function () {
    config(['social.inbox.youtube_daily_units' => 10]);
    $a = t20rYoutube($this, 1);
    $c = InboxComment::factory()->create(['user_id' => $this->user->id, 'brand_id' => $this->brand->id, 'social_account_id' => $a->id, 'network' => Network::YouTube, 'external_id' => 'yc1']);

    expect(fn () => app(InboxService::class)->reply($this->user, $c, 'hola'))->toThrow(PostValidationException::class);
    expect((new YouTubeQuota)->used())->toBe(0);
    Http::assertNothingSent();
});

test('1. YouTube: el despachador sincroniza cada cuenta de YouTube como mucho una vez por hora', function () {
    $a = t20rYoutube($this, 1);
    Queue::fake();

    (new SyncInboxComments)->handle();
    (new SyncInboxComments)->handle();
    Queue::assertPushed(SyncAccountComments::class, 1);

    Carbon::setTestNow(now()->addMinutes(61));
    (new SyncInboxComments)->handle();
    Queue::assertPushed(SyncAccountComments::class, 2);
});

test('2. SmartLinks: los posts de la papelera no salen en «posts recientes»', function () {
    $account = SocialAccount::factory()->for($this->user)->create(['brand_id' => $this->brand->id, 'network' => Network::Instagram]);
    $mk = function (string $text) use ($account) {
        $post = Post::factory()->create(['brand_id' => $this->brand->id, 'user_id' => $this->user->id, 'text' => $text]);
        PostTarget::factory()->create(['post_id' => $post->id, 'social_account_id' => $account->id, 'network' => Network::Instagram, 'status' => PostStatus::Published,
            'external_id' => 'e'.$post->id, 'external_url' => 'https://instagram.com/p/'.$post->id, 'published_at' => now(), 'text' => null]);

        return $post;
    };
    $mk('Visible');
    $mk('Borrado')->delete();
    $link = new SmartLink(['slug' => 'x-link', 'title' => 'T']);
    $link->user_id = $this->user->id;
    $link->brand_id = $this->brand->id;
    $link->save();

    expect(collect(app(SmartLinkService::class)->recentPosts($link, 5))->pluck('text')->all())->toBe(['Visible']);
});

function t20rEmailLink(object $t): array
{
    $t->actingAs($t->user)->post(route('smartlinks.save'), ['brand_id' => $t->brand->id, 'slug' => 'mi-marca', 'title' => 'M', 'blocks' => [['type' => 'email', 'label' => 'Boletin']]])->assertSessionHasNoErrors();
    $link = SmartLink::query()->firstOrFail();

    return [$link, $link->blocks->first()];
}

test('3. Formulario: al llenarse avisa al propietario una sola vez', function () {
    [$link, $block] = t20rEmailLink($this);
    config(['social.smartlinks.max_subscribers' => 2]);
    $svc = app(SmartLinkService::class);

    foreach (['a@example.com', 'b@example.com', 'c@example.com', 'd@example.com'] as $e) {
        $svc->subscribe($link, $block, $e);
    }

    expect($this->user->notifications()->count())->toBe(1)->and($this->user->notifications()->first()->data['block_id'])->toBe($block->id);
});

test('3. Formulario: el propietario borra suscriptores uno a uno y todos, y nadie mas puede', function () {
    [$link, $block] = t20rEmailLink($this);
    $svc = app(SmartLinkService::class);
    foreach (['a@example.com', 'b@example.com', 'c@example.com'] as $e) {
        $svc->subscribe($link, $block, $e);
    }
    $first = SmartLinkSubscriber::query()->first();

    $stranger = User::factory()->create();
    $this->actingAs($stranger)->delete(route('smartlinks.subscribers.destroy', [$link, $first]))->assertNotFound();
    $this->actingAs($stranger)->delete(route('smartlinks.subscribers.clear', $link))->assertNotFound();
    expect(SmartLinkSubscriber::query()->count())->toBe(3);

    $this->actingAs($this->user)->delete(route('smartlinks.subscribers.destroy', [$link, $first]))->assertRedirect();
    expect(SmartLinkSubscriber::query()->count())->toBe(2);
    $this->actingAs($this->user)->delete(route('smartlinks.subscribers.clear', $link))->assertRedirect();
    expect(SmartLinkSubscriber::query()->count())->toBe(0);
});

test('3. Formulario: quitar el bloque conserva la lista y el consentimiento no promete confirmacion', function () {
    [$link, $block] = t20rEmailLink($this);
    app(SmartLinkService::class)->subscribe($link, $block, 'a@example.com');

    $this->post(route('smartlinks.save'), ['brand_id' => $this->brand->id, 'slug' => 'mi-marca', 'title' => 'M', 'blocks' => [['type' => 'header', 'label' => 'Solo titulo']]])->assertSessionHasNoErrors();

    expect(SmartLinkSubscriber::query()->pluck('email')->all())->toBe(['a@example.com'])
        ->and($block->data['consent'])->toContain('No enviamos correo de confirmación')->and($block->data['consent'])->not->toContain('en cualquier momento');
});

test('4. Visibilidad: rango de años razonable, antes de 2038 y error propio en español', function () {
    $post = fn (array $b) => $this->actingAs($this->user)->post(route('smartlinks.save'), ['brand_id' => $this->brand->id, 'slug' => 'mi-marca', 'title' => 'M', 'blocks' => [['type' => 'link', 'label' => 'x', 'url' => 'https://example.com'] + $b]]);

    $post(['visible_from' => '2020-01-01T10:00'])->assertSessionHasErrors(['blocks.0.visible_from' => 'La fecha debe estar entre hace un año y diez años desde hoy, y antes de 2038.']);
    $post(['visible_until' => '2036-06-01T10:00'])->assertSessionHasNoErrors();
    $post(['visible_until' => '2038-01-02T10:00'])->assertSessionHasErrors('blocks.0.visible_until');
    $post(['visible_from' => '9999-01-01T00:00'])->assertSessionHasErrors('blocks.0.visible_from');
    $post(['visible_from' => '2026-12-01T10:00'])->assertSessionHasNoErrors();
});

test('4. Un error de base de datos que no es el slug no se disfraza de «enlace en uso»', function () {
    SmartLink::creating(fn () => throw new QueryException('sqlite', 'insert', [], new Exception('disk I/O error')));

    expect(fn () => app(SmartLinkService::class)->save($this->user, $this->brand, ['slug' => 'otro-slug', 'title' => 'T', 'blocks' => []]))->toThrow(QueryException::class);
    SmartLink::flushEventListeners();
});

test('4. El duplicado de slug sigue diciendo «Ese enlace ya está en uso»', function () {
    $other = User::factory()->create();
    $b2 = Brand::factory()->for($other)->create();
    $dup = new SmartLink(['slug' => 'ocupado', 'title' => 'T']);
    $dup->user_id = $other->id;
    $dup->brand_id = $b2->id;
    $dup->save();

    try {
        app(SmartLinkService::class)->save($this->user, $this->brand, ['slug' => 'ocupado', 'title' => 'T', 'blocks' => []]);
        $this->fail('debia fallar');
    } catch (PostValidationException $e) {
        expect($e->errors()['slug'][0])->toBe('Ese enlace ya está en uso.');
    }
});

function t20rCompetitor(object $t, string $name): Competitor
{
    $c = new Competitor(['username' => $name]);
    $c->user_id = $t->user->id;
    $c->brand_id = $t->brand->id;
    $c->save();

    return $c;
}

test('5. Competidores: una actualizacion cada 10 minutos por competidor y un tope por hora por usuario', function () {
    SocialAccount::factory()->for($this->user)->create(['brand_id' => $this->brand->id, 'network' => Network::Instagram, 'external_id' => '1784', 'access_token' => 'T']);
    $graph = new FakeGraph;
    $graph->on('GET', '#^1784$#', ['business_discovery' => ['media' => ['data' => []]]]);
    $svc = app(CompetitorService::class);
    $a = t20rCompetitor($this, 'a');
    $b = t20rCompetitor($this, 'b');
    $c = t20rCompetitor($this, 'c');
    config(['social.competitors.refresh_per_hour' => 3]);

    $svc->refreshPostsFor($this->user, $a);
    expect(fn () => $svc->refreshPostsFor($this->user, $a))->toThrow(PostValidationException::class, 'minutos');
    Carbon::setTestNow(now()->addMinutes(11));
    $svc->refreshPostsFor($this->user, $a);
    $svc->refreshPostsFor($this->user, $b);
    expect(fn () => $svc->refreshPostsFor($this->user, $c))->toThrow(PostValidationException::class, 'hora');

    Carbon::setTestNow(now()->addMinutes(61));
    $svc->refreshPostsFor($this->user, $c);
    expect((require config_path('social.php'))['competitors']['refresh_per_hour'])->toBeLessThan(200);
});

test('6. YouTube: responder a una respuesta usa el comentario de primer nivel como parentId', function () {
    $a = t20rYoutube($this, 1);
    $top = InboxComment::factory()->create(['user_id' => $this->user->id, 'brand_id' => $this->brand->id, 'social_account_id' => $a->id, 'network' => Network::YouTube, 'external_id' => 'top1']);
    $reply = InboxComment::factory()->create(['user_id' => $this->user->id, 'brand_id' => $this->brand->id, 'social_account_id' => $a->id, 'network' => Network::YouTube,
        'external_id' => 'top1.r1', 'parent_external_id' => 'top1']);
    Http::fake(['www.googleapis.com/youtube/v3/comments*' => Http::response(['id' => 'top1.r2'])]);

    app(InboxService::class)->reply($this->user, $reply, 'gracias');

    Http::assertSent(fn ($r) => $r['snippet']['parentId'] === 'top1');
    expect(InboxComment::withoutUserScope()->where('external_id', 'top1.r2')->value('parent_external_id'))->toBe('top1');
    expect($top->id)->not->toBe($reply->id);
});
