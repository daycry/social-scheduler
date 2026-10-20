<?php

use App\Connectors\Network;
use App\Jobs\CollectCompetitorSnapshot;
use App\Jobs\CollectCompetitorSnapshots;
use App\Models\Brand;
use App\Models\Competitor;
use App\Models\CompetitorSnapshot;
use App\Models\SocialAccount;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\Support\FakeGraph;

beforeEach(function () {
    Carbon::setTestNow('2026-11-01 12:00:00');
    Http::preventStrayRequests();
    $this->user = User::factory()->create();
    $this->brand = Brand::factory()->for($this->user)->create();
    $this->ig = SocialAccount::factory()->for($this->user)->create([
        'brand_id' => $this->brand->id, 'network' => Network::Instagram, 'external_id' => '17841400000000001', 'username' => 'mi_cuenta', 'access_token' => 'TOKEN-IG',
    ]);
    $this->graph = new FakeGraph;
});

function discovery(int $followers = 1000, int $media = 50, string $username = 'rival'): array
{
    return ['business_discovery' => ['username' => $username, 'name' => 'El Rival', 'profile_picture_url' => 'https://cdn.example/p.jpg', 'followers_count' => $followers, 'media_count' => $media], 'id' => '17841400000000001'];
}

test('Competitors: anadir consulta Business Discovery con Bearer y guarda el primer snapshot', function () {
    $this->graph->on('GET', '#^17841400000000001$#', discovery());

    $this->actingAs($this->user)->post(route('competitors.store'), ['brand_id' => $this->brand->id, 'username' => '@Rival'])
        ->assertRedirect(route('competitors.index', ['brand_id' => $this->brand->id]))->assertSessionHasNoErrors();

    $call = $this->graph->calls[0];
    expect($call['authorization'])->toBe('Bearer TOKEN-IG')
        ->and($call['params']['fields'])->toBe('business_discovery.username(rival){username,name,profile_picture_url,followers_count,media_count}')
        ->and($call['url'])->not->toContain('access_token');

    $c = Competitor::query()->firstOrFail();
    $snap = CompetitorSnapshot::query()->firstOrFail();
    expect($c->username)->toBe('rival')->and($c->name)->toBe('El Rival')->and($snap->followers_count)->toBe(1000)->and($snap->media_count)->toBe(50);
});

test('Competitors: usuario inexistente o no profesional -> error legible y no se crea nada', function () {
    $this->graph->on('GET', '#^17841400000000001$#', FakeGraph::error(110, 'Invalid user id', 2207013));

    $this->actingAs($this->user)->post(route('competitors.store'), ['brand_id' => $this->brand->id, 'username' => 'personal'])
        ->assertSessionHasErrors(['username' => 'No encontramos esa cuenta o no es una cuenta profesional (Business/Creator) de Instagram.']);

    expect(Competitor::query()->count())->toBe(0);
});

test('Competitors: validacion del usuario (inyeccion en fields), duplicado, propia cuenta, sin Instagram y tope', function () {
    foreach (['bad)name', 'a b', 'x{followers_count}', str_repeat('a', 31), 'ñandú'] as $bad) {
        $this->actingAs($this->user)->post(route('competitors.store'), ['brand_id' => $this->brand->id, 'username' => $bad])->assertSessionHasErrors('username');
    }
    expect($this->graph->calls)->toBe([]);

    $this->actingAs($this->user)->post(route('competitors.store'), ['brand_id' => $this->brand->id, 'username' => '@Mi_Cuenta'])->assertSessionHasErrors(['username' => 'Esa es tu propia cuenta.']);

    $this->graph->on('GET', '#^17841400000000001$#', discovery());
    $this->actingAs($this->user)->post(route('competitors.store'), ['brand_id' => $this->brand->id, 'username' => 'rival'])->assertSessionHasNoErrors();
    $this->actingAs($this->user)->post(route('competitors.store'), ['brand_id' => $this->brand->id, 'username' => 'RIVAL'])->assertSessionHasErrors(['username' => 'Ya sigues a esa cuenta.']);

    config(['social.competitors.max_per_brand' => 1]);
    $this->actingAs($this->user)->post(route('competitors.store'), ['brand_id' => $this->brand->id, 'username' => 'otro'])->assertSessionHasErrors('username');

    $empty = Brand::factory()->for($this->user)->create();
    $this->actingAs($this->user)->post(route('competitors.store'), ['brand_id' => $empty->id, 'username' => 'otro'])
        ->assertSessionHasErrors(['username' => 'Conecta una cuenta de Instagram activa en esta marca para comparar.']);
});

test('Competitors: errores de Meta (credenciales, limite, permisos) se traducen sin romper ni crear', function () {
    $this->graph->on('GET', '#^17841400000000001$#', FakeGraph::seq(
        FakeGraph::error(190, 'Token caducado', status: 401),
        FakeGraph::error(4, 'Limite', status: 400),
        FakeGraph::error(10, 'Permiso denegado', status: 403),
    ));
    $expected = ['Tu cuenta de Instagram necesita reconectarse.', 'Instagram no responde ahora mismo. Intentalo de nuevo en unos minutos.', 'Instagram rechazo la consulta. Revisa los permisos de la cuenta conectada.'];
    foreach (['a1', 'a2', 'a3'] as $i => $u) {
        SocialAccount::withoutUserScope()->whereKey($this->ig->id)->update(['status' => SocialAccount::STATUS_ACTIVE]); // el primer error la marca para reconectar
        $this->actingAs($this->user)->post(route('competitors.store'), ['brand_id' => $this->brand->id, 'username' => $u])->assertSessionHasErrors(['username' => $expected[$i]]);
    }
    expect(Competitor::query()->count())->toBe(0);
});

test('Competitors: el snapshot diario es un job unico de la cola metrics con timeout menor que el del worker (120 s)', function () {
    $job = new CollectCompetitorSnapshot(1, '2026-11-01');
    $dispatcher = new CollectCompetitorSnapshots;

    expect($job->queue)->toBe('metrics')->and($job->timeout)->toBeLessThan(120)->and($job->tries)->toBe(3)
        ->and($job->uniqueId())->toBe('1:2026-11-01')
        ->and($dispatcher->queue)->toBe('metrics')->and($dispatcher->timeout)->toBeLessThan(120);
});

test('Competitors: el despachador encola un job por competidor y el job guarda el snapshot del dia (idempotente)', function () {
    $c = new Competitor(['username' => 'rival']);
    $c->user_id = $this->user->id;
    $c->brand_id = $this->brand->id;
    $c->social_account_id = $this->ig->id;
    $c->save();
    $other = User::factory()->create();
    $oc = new Competitor(['username' => 'ajeno']);
    $oc->user_id = $other->id;
    $oc->brand_id = Brand::factory()->for($other)->create()->id;
    $oc->save();

    Queue::fake();
    (new CollectCompetitorSnapshots)->handle();
    Queue::assertPushed(CollectCompetitorSnapshot::class, 2);
    Queue::assertPushedOn('metrics', CollectCompetitorSnapshot::class);

    $this->graph->on('GET', '#^17841400000000001$#', discovery(1200, 51));
    foreach ([1, 2] as $_) {
        app()->call([new CollectCompetitorSnapshot($c->id, '2026-11-01'), 'handle']);
    }

    $snaps = CompetitorSnapshot::withoutUserScope()->where('competitor_id', $c->id)->get();
    expect($snaps)->toHaveCount(1)->and($snaps[0]->followers_count)->toBe(1200)->and($snaps[0]->user_id)->toBe($this->user->id)
        ->and($c->fresh()->last_synced_at)->not->toBeNull()->and($c->fresh()->last_error)->toBeNull();
});

test('Competitors: si la cuenta deja de existir como profesional se anota el error y no se reintenta', function () {
    $c = new Competitor(['username' => 'rival']);
    $c->user_id = $this->user->id;
    $c->brand_id = $this->brand->id;
    $c->social_account_id = $this->ig->id;
    $c->save();
    $this->graph->on('GET', '#^17841400000000001$#', FakeGraph::error(110, 'Invalid user id', 2207013));

    app()->call([new CollectCompetitorSnapshot($c->id, '2026-11-01'), 'handle']);

    expect($c->fresh()->last_error)->toContain('No encontramos')->and(CompetitorSnapshot::withoutUserScope()->count())->toBe(0);
});

test('Competitors: comparativa con crecimiento a 7 y 30 dias y la cuenta propia', function () {
    $c = new Competitor(['username' => 'rival']);
    $c->user_id = $this->user->id;
    $c->brand_id = $this->brand->id;
    $c->social_account_id = $this->ig->id;
    $c->save();
    foreach (['2026-10-02' => 900, '2026-10-25' => 950, '2026-11-01' => 1000] as $d => $f) {
        CompetitorSnapshot::withoutUserScope()->create(['user_id' => $this->user->id, 'competitor_id' => $c->id, 'date' => $d, 'followers_count' => $f, 'media_count' => 10]);
    }

    $this->actingAs($this->user)->get(route('competitors.index'))->assertOk()
        ->assertInertia(fn ($p) => $p->component('Competitors/Index')
            ->where('has_instagram', true)
            ->where('comparison.competitors.0.followers', 1000)
            ->where('comparison.competitors.0.growth_7d', 50)
            ->where('comparison.competitors.0.growth_30d', 100)
            ->where('comparison.own.username', 'mi_cuenta'));
});

test('Competitors: aislamiento — marca ajena 404, competidor ajeno 404 y no aparece en la comparativa', function () {
    $other = User::factory()->create();
    $otherBrand = Brand::factory()->for($other)->create();
    $oc = new Competitor(['username' => 'rival_secreto']);
    $oc->user_id = $other->id;
    $oc->brand_id = $otherBrand->id;
    $oc->save();

    $this->actingAs($this->user)->post(route('competitors.store'), ['brand_id' => $otherBrand->id, 'username' => 'rival'])->assertNotFound();
    $this->actingAs($this->user)->delete(route('competitors.destroy', $oc))->assertNotFound();
    $this->actingAs($this->user)->get(route('competitors.index', ['brand_id' => $otherBrand->id]))->assertOk()->assertDontSee('rival_secreto');
    expect(Competitor::withoutUserScope()->whereKey($oc->id)->exists())->toBeTrue();

    $mine = new Competitor(['username' => 'mio']);
    $mine->user_id = $this->user->id;
    $mine->brand_id = $this->brand->id;
    $mine->save();
    $this->actingAs($this->user)->delete(route('competitors.destroy', $mine))->assertRedirect();
    expect(Competitor::query()->count())->toBe(0);
});
