<?php

use App\Connectors\Bluesky\BlueskyConnector;
use App\Connectors\ConnectorRegistry;
use App\Models\Brand;
use App\Models\SocialAccount;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\BlueskyFake;

const BSKY_TEST_PASSWORD = 'abcd-efgh-ijkl-mnop';

beforeEach(function () {
    BlueskyFake::dns();
    app(ConnectorRegistry::class)->register(app(BlueskyConnector::class));
    $this->user = User::factory()->create();
    $this->brand = Brand::factory()->for($this->user)->create();
    $this->url = route('brands.connections.credentials', [$this->brand, 'bluesky']);
});

test('con handle y app password validos la cuenta aparece conectada', function () {
    BlueskyFake::connect();

    $this->actingAs($this->user)->post($this->url, ['handle' => 'ana.example.com', 'app_password' => BSKY_TEST_PASSWORD])
        ->assertRedirect(route('brands.connections.index', $this->brand))
        ->assertSessionHas('success');

    $account = SocialAccount::withoutUserScope()->first();
    expect($account->name)->toBe('ana.example.com')->and($account->external_id)->toBe(BlueskyFake::DID)
        ->and($account->brand_id)->toBe($this->brand->id)->and($account->status)->toBe('activa');

    $this->get(route('brands.connections.index', $this->brand))->assertInertia(fn (Assert $p) => $p
        ->where('accounts.0.network', 'bluesky')->where('accounts.0.auth_kind', 'credentials'));
});

test('una contrasena invalida da un error claro y no guarda nada', function () {
    Http::fake([
        'bsky.social/xrpc/com.atproto.identity.resolveHandle*' => Http::response(['did' => BlueskyFake::DID]),
        'plc.directory/*' => Http::response(['id' => BlueskyFake::DID, 'service' => [['id' => '#atproto_pds', 'serviceEndpoint' => BlueskyFake::PDS]]]),
        'pds.example.com/xrpc/com.atproto.server.createSession' => Http::response(['error' => 'AuthenticationRequired', 'message' => 'Invalid identifier or password'], 401),
    ]);

    $response = $this->actingAs($this->user)->post($this->url, ['handle' => 'ana.example.com', 'app_password' => BSKY_TEST_PASSWORD]);

    $response->assertRedirect()->assertSessionHasErrors('credentials');
    expect(session('errors')->first('credentials'))->toContain('app password')->not->toContain(BSKY_TEST_PASSWORD)
        ->and(SocialAccount::withoutUserScope()->count())->toBe(0);
});

test('faltan campos: errores por campo sin reflejar la contrasena', function () {
    $this->actingAs($this->user)->post($this->url, ['handle' => 'ana.example.com'])
        ->assertSessionHasErrors('app_password');

    expect(session()->getOldInput())->not->toHaveKey('app_password');
});

test('el secreto no aparece en props, sesion, base de datos ni logs', function () {
    BlueskyFake::connect();
    $logged = [];
    Log::listen(function ($event) use (&$logged) {
        $logged[] = $event->message.json_encode($event->context);
    });

    $this->actingAs($this->user)->post($this->url, ['handle' => 'ana.example.com', 'app_password' => BSKY_TEST_PASSWORD]);
    $page = $this->get(route('brands.connections.index', $this->brand));

    expect($page->getContent())->not->toContain(BSKY_TEST_PASSWORD)->not->toContain('REFRESH-1')
        ->and(json_encode(session()->all()))->not->toContain(BSKY_TEST_PASSWORD)
        ->and(json_encode(DB::table('social_accounts')->first()))->not->toContain(BSKY_TEST_PASSWORD)
        ->and(implode("\n", $logged))->not->toContain(BSKY_TEST_PASSWORD);
});

test('throttle: la peticion 11 en un minuto recibe 429', function () {
    Http::fake(['*' => Http::response(['error' => 'InvalidRequest', 'message' => 'x'], 400)]);

    for ($i = 0; $i < 10; $i++) {
        $this->actingAs($this->user)->post($this->url, ['handle' => 'ana.example.com', 'app_password' => 'x-x-x-x'])->assertRedirect();
    }

    $this->actingAs($this->user)->post($this->url, ['handle' => 'ana.example.com', 'app_password' => 'x-x-x-x'])->assertStatus(429);
});

test('una marca ajena responde 404 (UserScope) y un proveedor OAuth 404', function () {
    $other = Brand::factory()->for(User::factory()->create())->create();

    $this->actingAs($this->user)->post(route('brands.connections.credentials', [$other, 'bluesky']), ['handle' => 'a.b', 'app_password' => 'x'])->assertNotFound();
    $this->actingAs($this->user)->post(route('brands.connections.credentials', [$this->brand, 'meta']), ['handle' => 'a.b', 'app_password' => 'x'])->assertNotFound();
});

test('exige sesion', function () {
    $this->post($this->url, ['handle' => 'a.b', 'app_password' => 'x'])->assertRedirect(route('login'));
});

test('la pantalla de conexiones ofrece Bluesky con sus campos de credenciales', function () {
    $this->actingAs($this->user)->get(route('brands.connections.index', $this->brand))->assertInertia(fn (Assert $p) => $p
        ->where('providers.4.network', 'bluesky')->where('providers.4.auth_kind', 'credentials')
        ->where('providers.4.fields.0.name', 'handle')->where('providers.4.fields.1.type', 'password'));
});

test('sin conector registrado Bluesky no se ofrece ni se puede conectar', function () {
    app()->instance(ConnectorRegistry::class, new ConnectorRegistry);

    $this->actingAs($this->user)->get(route('brands.connections.index', $this->brand))->assertInertia(fn (Assert $p) => $p
        ->where('providers', fn ($providers) => collect($providers)->pluck('network')->doesntContain('bluesky')));
    $this->actingAs($this->user)->post($this->url, ['handle' => 'a.b', 'app_password' => 'x'])->assertNotFound();
});
