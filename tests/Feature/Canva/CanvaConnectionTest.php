<?php

use App\Models\Brand;
use App\Models\Canva\CanvaConnection;
use App\Models\User;
use App\Services\Canva\CanvaCapabilities;
use App\Services\Canva\CanvaClient;
use App\Services\Canva\CanvaConnections;
use App\Services\Canva\CanvaDesigns;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

require_once __DIR__.'/CanvaTestSupport.php';

// T-16 · Conexion de Canva por marca: OAuth con PKCE, state de un solo uso, tokens cifrados, refresh, capacidades por plan, disenos y retorno.
// Todo con Http::fake: ninguna peticion real a Canva y nada que acredite la autorizacion real (smoke del usuario).

beforeEach(function () {
    canvaEnvironment();
    [$this->user, $this->brand] = canvaUserAndBrand();
});

function canvaStart($test, $user = null, $brand = null): array
{
    $user ??= $test->user;
    $brand ??= $test->brand;
    $response = $test->actingAs($user)->post(route('canva.connect', $brand));
    $url = $response->headers->get('Location');
    parse_str((string) parse_url($url, PHP_URL_QUERY), $q);

    return [$url, $q];
}

function canvaTokenFake(array $over = []): void
{
    Http::fake([
        'api.canva.com/rest/v1/oauth/token' => Http::response($over + ['access_token' => 'at-new', 'refresh_token' => 'rt-new', 'token_type' => 'Bearer', 'expires_in' => 14400, 'scope' => implode(' ', config('services.canva.scopes'))]),
        'api.canva.com/rest/v1/users/me/profile' => Http::response(['profile' => ['display_name' => 'Ana en Canva']]),
        'api.canva.com/rest/v1/users/me/capabilities' => Http::response(['capabilities' => ['export_png_transparency', 'brand_template']]),
        'api.canva.com/rest/v1/oauth/revoke' => Http::response('', 200),
    ]);
}

test('sin credenciales la funcion aparece como no configurada y conectar no hace nada', function () {
    config(['services.canva.client_id' => null, 'services.canva.client_secret' => '']);

    $this->actingAs($this->user)->getJson(route('canva.status', $this->brand))->assertOk()->assertJsonPath('configured', false)->assertJsonPath('status', 'not_configured');
    $this->actingAs($this->user)->post(route('canva.connect', $this->brand))->assertRedirect(route('canva.index', $this->brand));
    $this->actingAs($this->user)->getJson(route('canva.designs.index', $this->brand))->assertStatus(404)->assertJsonPath('code', 'not_configured');
    Http::assertNothingSent();
});

test('conectar redirige a Canva con PKCE S256, state propio, los permisos pedidos y la redirect URL', function () {
    [$url, $q] = canvaStart($this);

    expect($url)->toStartWith('https://www.canva.com/api/oauth/authorize?')
        ->and($q['response_type'])->toBe('code')->and($q['client_id'])->toBe('OC-test-client')
        ->and($q['code_challenge_method'])->toBe('s256')->and(strlen($q['code_challenge']))->toBe(43)
        ->and($q['redirect_uri'])->toBe(route('canva.callback'))->and(strlen($q['state']))->toBeGreaterThanOrEqual(32)
        ->and(explode(' ', $q['scope']))->toContain('design:meta:read', 'design:content:read', 'asset:write', 'profile:read');
    Http::assertNothingSent();
});

test('el callback canjea el codigo con el verificador PKCE, guarda los tokens CIFRADOS y consulta perfil y plan', function () {
    canvaTokenFake();
    [, $q] = canvaStart($this);

    $this->actingAs($this->user)->get(route('canva.callback', ['code' => 'the-auth-code', 'state' => $q['state']]))
        ->assertRedirect(route('canva.index', $this->brand))->assertSessionHas('success');

    Http::assertSent(function (Request $r) use ($q) {
        if (! str_ends_with($r->url(), '/oauth/token')) {
            return false;
        }
        $verifier = $r['code_verifier'];
        $challenge = rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '=');

        return $r['grant_type'] === 'authorization_code' && $r['code'] === 'the-auth-code' && $challenge === $q['code_challenge']
            && $r->hasHeader('Authorization') && str_starts_with($r->header('Authorization')[0], 'Basic ');
    });
    $connection = CanvaConnection::withoutUserScope()->where('brand_id', $this->brand->id)->firstOrFail();
    expect($connection->access_token)->toBe('at-new')->and($connection->display_name)->toBe('Ana en Canva')->and($connection->capabilities)->toBe(['export_png_transparency', 'brand_template']);
    $raw = DB::table('canva_connections')->where('id', $connection->id)->first();
    expect($raw->access_token)->not->toContain('at-new')->and($raw->refresh_token)->not->toContain('rt-new');
    expect(json_encode($connection->toArray()))->not->toContain('at-new');
});

test('el state es de un solo uso: repetir el callback no vuelve a conectar', function () {
    canvaTokenFake();
    [, $q] = canvaStart($this);
    $url = route('canva.callback', ['code' => 'c1', 'state' => $q['state']]);

    $this->actingAs($this->user)->get($url)->assertSessionHas('success');
    $this->actingAs($this->user)->get($url)->assertRedirect(route('brands.index'))->assertSessionHas('error');

    Http::assertSentCount(3); // token + perfil + capacidades del primero; el repetido no pide nada
});

test('un state de otro usuario, inventado, caducado o sin state no conecta nada', function () {
    canvaTokenFake();
    [, $q] = canvaStart($this);
    $other = User::factory()->create();

    $this->actingAs($other)->get(route('canva.callback', ['code' => 'c', 'state' => $q['state']]))->assertRedirect(route('brands.index'))->assertSessionHas('error');
    $this->actingAs($this->user)->get(route('canva.callback', ['code' => 'c', 'state' => 'inventado']))->assertSessionHas('error');
    $this->actingAs($this->user)->get(route('canva.callback', ['code' => 'c']))->assertSessionHas('error');
    $this->travel(20)->minutes();
    $this->actingAs($this->user)->get(route('canva.callback', ['code' => 'c', 'state' => $q['state']]))->assertSessionHas('error');

    expect(CanvaConnection::withoutUserScope()->count())->toBe(0);
    Http::assertNothingSent();
});

test('el state de una marca no sirve para conectar otra y la marca ajena es 404', function () {
    $stranger = User::factory()->create();
    $strangerBrand = Brand::factory()->for($stranger)->create();

    $this->actingAs($this->user)->post(route('canva.connect', $strangerBrand))->assertNotFound();
    $this->actingAs($this->user)->getJson(route('canva.status', $strangerBrand))->assertNotFound();
    $this->actingAs($this->user)->deleteJson(route('canva.disconnect', $strangerBrand))->assertNotFound();
});

test('si el usuario cancela en Canva no se guarda nada y se explica', function () {
    [, $q] = canvaStart($this);

    $this->actingAs($this->user)->get(route('canva.callback', ['error' => 'access_denied', 'state' => $q['state']]))
        ->assertRedirect(route('canva.index', $this->brand))->assertSessionHas('error');

    expect(CanvaConnection::withoutUserScope()->count())->toBe(0);
    Http::assertNothingSent();
});

test('reconectar una marca sustituye la conexion y cada marca tiene la suya', function () {
    canvaTokenFake();
    $second = Brand::factory()->for($this->user)->create();
    foreach ([$this->brand, $second, $this->brand] as $brand) {
        [, $q] = canvaStart($this, brand: $brand);
        $this->actingAs($this->user)->get(route('canva.callback', ['code' => 'c', 'state' => $q['state']]))->assertSessionHas('success');
    }

    expect(CanvaConnection::withoutUserScope()->count())->toBe(2);
});

test('desconectar borra los tokens locales y revoca el de Canva', function () {
    canvaTokenFake();
    canvaConnect($this->user, $this->brand);

    $this->actingAs($this->user)->deleteJson(route('canva.disconnect', $this->brand))->assertOk()->assertJsonPath('connected', false);

    expect(CanvaConnection::withoutUserScope()->count())->toBe(0);
    Http::assertSent(fn (Request $r) => str_ends_with($r->url(), '/oauth/revoke') && $r['token'] === 'rt-live');
});

test('un token a punto de caducar se refresca una vez con el refresh token y se guarda el nuevo', function () {
    canvaTokenFake(['access_token' => 'at-fresh', 'refresh_token' => 'rt-fresh']);
    $connection = canvaConnect($this->user, $this->brand, expires: now()->addSeconds(20));

    expect(app(CanvaConnections::class)->accessToken($connection))->toBe('at-fresh');
    expect(app(CanvaConnections::class)->accessToken($connection->fresh()))->toBe('at-fresh');

    $connection->refresh();
    expect($connection->refresh_token)->toBe('rt-fresh')->and($connection->expires_at->gt(now()->addHour()))->toBeTrue();
    Http::assertSent(fn (Request $r) => $r['grant_type'] === 'refresh_token' && $r['refresh_token'] === 'rt-live');
    Http::assertSentCount(1);
});

test('si Canva rechaza el refresh la conexion queda caducada y la interfaz pide reconectar', function () {
    Http::fake(['api.canva.com/rest/v1/oauth/token' => Http::response(['error' => 'invalid_grant'], 400)]);
    canvaConnect($this->user, $this->brand, expires: now()->subMinute());

    $this->actingAs($this->user)->getJson(route('canva.designs.index', $this->brand))->assertStatus(409)->assertJsonPath('code', 'unauthorized');
    $this->actingAs($this->user)->getJson(route('canva.status', $this->brand))->assertOk()->assertJsonPath('status', 'expired')->assertJsonPath('connected', false);
});

test('las capacidades del plan activan o desactivan funciones y cada una desactivada lleva su motivo', function () {
    $free = collect(CanvaCapabilities::features([], config('services.canva.scopes')))->keyBy('key');
    expect($free['transparent_png']['available'])->toBeFalse()->and($free['transparent_png']['reason'])->toContain('plan de pago')
        ->and($free['brand_templates']['available'])->toBeFalse()->and($free['brand_templates']['reason'])->toContain('Requiere un plan')
        ->and($free['export']['available'])->toBeTrue()->and($free['export']['reason'])->toBeNull();

    $pro = collect(CanvaCapabilities::features(['export_png_transparency', 'brand_template'], config('services.canva.scopes')))->keyBy('key');
    expect($pro['transparent_png']['available'])->toBeTrue()->and($pro['brand_templates']['available'])->toBeFalse()->and($pro['brand_templates']['reason'])->toContain('todavía no lo usa');

    $noAsset = collect(CanvaCapabilities::features([], ['design:meta:read', 'design:content:read']))->keyBy('key');
    expect($noAsset['send']['available'])->toBeFalse()->and($noAsset['send']['reason'])->toContain('asset:write')->and($noAsset['create']['available'])->toBeFalse();
});

test('el estado de la marca expone funciones con motivo, formatos y nunca tokens ni secretos', function () {
    canvaConnect($this->user, $this->brand, capabilities: []);

    $json = $this->actingAs($this->user)->getJson(route('canva.status', $this->brand))->assertOk()->assertJsonPath('connected', true)->assertJsonPath('name', 'Ana en Canva')->json();

    expect(collect($json['features'])->firstWhere('key', 'transparent_png'))->toMatchArray(['available' => false])
        ->and(collect($json['formats'])->pluck('key')->all())->toContain('square', 'story')
        ->and(json_encode($json))->not->toContain('at-live')->not->toContain('rt-live')->not->toContain('test-secret-value');
});

test('listar y buscar disenos: pide a Canva con la busqueda y devuelve solo enlaces https de canva.com con retorno firmado', function () {
    canvaConnect($this->user, $this->brand);
    Http::fake(['api.canva.com/rest/v1/designs*' => Http::response([
        'continuation' => 'tok2',
        'items' => [
            ['id' => 'DAF123abcde', 'title' => 'Rebajas', 'thumbnail' => ['url' => 'https://document-export.canva.com/t.png', 'width' => 400, 'height' => 400], 'updated_at' => 1790000000,
                'urls' => ['edit_url' => 'https://www.canva.com/api/design/tok/edit', 'view_url' => 'https://www.canva.com/api/design/tok/view']],
            ['id' => 'DAFmalicious', 'title' => 'Malo', 'urls' => ['edit_url' => 'https://evil.example.com/edit', 'view_url' => 'javascript:alert(1)']],
            ['id' => '../etc', 'title' => 'Id invalido'],
        ],
    ])]);

    $json = $this->actingAs($this->user)->getJson(route('canva.designs.index', ['brand' => $this->brand, 'query' => 'rebajas']))->assertOk()->json();

    Http::assertSent(fn (Request $r) => str_contains($r->url(), 'query=rebajas') && $r->hasHeader('Authorization', 'Bearer at-live'));
    expect($json['items'])->toHaveCount(2)->and($json['continuation'])->toBe('tok2');
    expect($json['items'][0]['open_url'])->toStartWith('https://www.canva.com/api/design/tok/edit?correlation_state=')
        ->and($json['items'][1]['open_url'])->toBeNull()->and($json['items'][1]['view_url'])->toBeNull();
    parse_str((string) parse_url($json['items'][0]['open_url'], PHP_URL_QUERY), $q);
    expect(strlen($q['correlation_state']))->toBeLessThanOrEqual(50);
});

test('crear en Canva usa el tamano del formato social elegido y devuelve el enlace de edicion', function () {
    canvaConnect($this->user, $this->brand);
    Http::fake(['api.canva.com/rest/v1/designs' => Http::response(['design' => ['id' => 'DAFnew00001', 'title' => 'x', 'urls' => ['edit_url' => 'https://www.canva.com/api/design/n/edit', 'view_url' => 'https://www.canva.com/api/design/n/view']]])]);

    $json = $this->actingAs($this->user)->postJson(route('canva.designs.store', $this->brand), ['format' => 'story', 'title' => 'Mi historia'])->assertCreated()->json();

    Http::assertSent(fn (Request $r) => $r->method() === 'POST' && $r['design_type'] === ['type' => 'custom', 'width' => 1080, 'height' => 1920] && $r['title'] === 'Mi historia');
    expect($json['design']['id'])->toBe('DAFnew00001')->and($json['design']['open_url'])->toContain('correlation_state=');
    $this->actingAs($this->user)->postJson(route('canva.designs.store', $this->brand), ['format' => 'cuadrado-raro'])->assertStatus(422);
});

test('crear sin el permiso de escritura se rechaza con el motivo y sin llamar a Canva', function () {
    canvaConnect($this->user, $this->brand, scopes: ['profile:read', 'design:meta:read', 'design:content:read']);

    $this->actingAs($this->user)->postJson(route('canva.designs.store', $this->brand), ['format' => 'square'])->assertStatus(403)->assertJsonPath('message', fn ($m) => str_contains($m, 'design:content:write'));
    Http::assertNothingSent();
});

test('un fallo de Canva se explica en español sin filtrar el cuerpo ni el token', function (int $status, string $fragment) {
    canvaConnect($this->user, $this->brand);
    Http::fake(['api.canva.com/rest/v1/designs*' => Http::response(['code' => 'x', 'message' => 'secret-internal at-live'], $status)]);

    $res = $this->actingAs($this->user)->getJson(route('canva.designs.index', $this->brand));

    expect($res->json('message'))->toContain($fragment)->not->toContain('at-live')->not->toContain('secret-internal');
})->with([[429, 'limitando'], [500, 'no responde'], [404, 'no encuentra'], [403, 'permisos']]);

test('retorno desde Canva: correlation_state firmado y ligado a usuario y marca lleva al diseno; manipulado, ajeno o caducado no', function () {
    $designs = app(CanvaDesigns::class);
    $pair = canvaKeyPair();
    canvaFakeKeys($pair);
    $jwt = fn (string $state, array $over = []) => canvaSignedJwt($pair, $state, $over);
    $state = $designs->sign($this->user, $this->brand, 'DAF123abcde');

    expect(strlen($state))->toBeLessThanOrEqual(50);
    $this->actingAs($this->user)->get(route('canva.return', ['correlation_jwt' => $jwt($state)]))
        ->assertRedirect(route('canva.index', ['brand' => $this->brand, 'design' => 'DAF123abcde']));

    $invalid = [
        'otro usuario' => [User::factory()->create(), $state, []],
        'firma alterada' => [$this->user, substr($state, 0, -3).'AAA', []],
        'otro diseno' => [$this->user, str_replace('DAF123abcde', 'DAF999zzzzz', $state), []],
        'otra marca' => [$this->user, preg_replace('/^\d+/', '999999', $state), []],
        'audiencia ajena' => [$this->user, $state, ['aud' => 'otra-app']],
        'jwt caducado' => [$this->user, $state, ['exp' => now()->subHour()->timestamp]],
        'tipo distinto' => [$this->user, $state, ['type' => 'otro']],
    ];
    foreach ($invalid as $why => [$who, $s, $over]) {
        $this->actingAs($who)->get(route('canva.return', ['correlation_jwt' => $jwt($s, $over)]))->assertRedirect(route('brands.index'))->assertSessionHas('error');
    }
    $this->actingAs($this->user)->get(route('canva.return', ['correlation_jwt' => 'basura']))->assertRedirect(route('brands.index'));
    $this->travel(4)->days();
    $this->actingAs($this->user)->get(route('canva.return', ['correlation_jwt' => $jwt($state, ['exp' => now()->addDay()->timestamp])]))->assertRedirect(route('brands.index'));
});

test('retorno desde Canva: un JWT con el estado correcto pero firmado con una clave que Canva no publica se rechaza', function () {
    $state = app(CanvaDesigns::class)->sign($this->user, $this->brand, 'DAF123abcde');
    canvaFakeKeys(canvaKeyPair('kid-1'));
    $forged = canvaSignedJwt(canvaKeyPair('kid-1'), $state);

    $this->actingAs($this->user)->get(route('canva.return', ['correlation_jwt' => $forged]))->assertRedirect(route('brands.index'))->assertSessionHas('error');
});

test('el PKCE genera verificadores validos y distintos en cada intento', function () {
    $a = CanvaClient::pkce();
    $b = CanvaClient::pkce();

    expect($a['verifier'])->not->toBe($b['verifier'])->and(strlen($a['verifier']))->toBeBetween(43, 128)->and($a['verifier'])->toMatch('/^[A-Za-z0-9\-._~]+$/');
});
