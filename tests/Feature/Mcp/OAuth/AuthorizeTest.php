<?php

use App\Mcp\Abilities;
use App\Models\AuditLog;
use App\Models\OAuthAuthCode;
use App\Models\OAuthClient;
use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;

function oauthClient(array $uris = ['http://127.0.0.1:53111/callback'], string $name = 'Claude Code'): OAuthClient
{
    return OAuthClient::create(['name' => $name, 'redirect_uris' => $uris]);
}

function pkceChallenge(string $verifier = 'dBjftJeZ4CVP-mB92K27uhbUJU1p1r_wW1gFWFOEjXk'): string
{
    return rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '=');
}

function authorizeParams(OAuthClient $c, array $over = []): array
{
    return array_filter($over + [
        'response_type' => 'code',
        'client_id' => $c->id,
        'redirect_uri' => $c->redirect_uris[0],
        'code_challenge' => pkceChallenge(),
        'code_challenge_method' => 'S256',
        'state' => 'estado-xyz',
        'resource' => rtrim(config('app.url'), '/').'/mcp',
    ], fn ($v) => $v !== null);
}

function authorizeUrl(array $params): string
{
    return '/oauth/authorize?'.http_build_query($params);
}

function locationQuery($response): array
{
    parse_str((string) parse_url($response->headers->get('Location'), PHP_URL_QUERY), $q);

    return $q;
}

test('OAuthAuthorize CA-16: sin sesion redirige a /login y el intended conserva los parametros', function () {
    $params = authorizeParams(oauthClient());

    $this->get(authorizeUrl($params))->assertRedirect('/login');

    expect(session('url.intended'))->toContain('/oauth/authorize?')->toContain('code_challenge='.$params['code_challenge']);
});

test('OAuthAuthorize CA-16: tras entrar con contrasena vuelve al consentimiento con los mismos parametros', function () {
    $user = User::factory()->create();
    $params = authorizeParams(oauthClient());

    $this->get(authorizeUrl($params));
    $r = $this->post('/login', ['email' => $user->email, 'password' => 'password']);

    expect(parse_url($r->headers->get('Location'), PHP_URL_PATH))->toBe('/oauth/authorize')
        ->and(locationQuery($r))->toEqualCanonicalizing($params);
});

test('OAuthAuthorize CA-18: muestra el consentimiento con cabecera anti-framing y read por defecto', function () {
    $user = User::factory()->create();
    $client = oauthClient(name: 'Mi agente');

    $r = $this->actingAs($user)->get(authorizeUrl(authorizeParams($client)))->assertOk();

    $r->assertHeader('Content-Security-Policy', "frame-ancestors 'none'");
    $r->assertInertia(fn (Assert $page) => $page->component('OAuth/Authorize')
        ->where('client.name', 'Mi agente')
        ->where('redirectHost', '127.0.0.1:53111')
        ->where('defaultPreset', 'read')
        ->has('presets.read')->has('presets.publish')->has('presets.all')
        ->where('presets.read.abilities', Abilities::presets()['read']['abilities'])
        ->where('params.client_id', $client->id)
        ->where('error', null));
    expect(OAuthAuthCode::count())->toBe(0);
});

test('OAuthAuthorize CA-17: requisitos PKCE y response_type invalidos redirigen con invalid_request y state', function (array $over) {
    $user = User::factory()->create();
    $client = oauthClient();

    $r = $this->actingAs($user)->get(authorizeUrl(authorizeParams($client, $over)));

    $r->assertRedirectContains('http://127.0.0.1:53111/callback?');
    $q = locationQuery($r);
    expect($q['error'])->toBe('invalid_request')->and($q['state'])->toBe('estado-xyz');
})->with([
    'sin challenge' => [['code_challenge' => null]],
    'challenge corto' => [['code_challenge' => 'abc']],
    'plain' => [['code_challenge_method' => 'plain']],
    'sin metodo' => [['code_challenge_method' => null]],
    'token' => [['response_type' => 'token']],
]);

test('OAuthAuthorize CA-17: client_id o redirect_uri no registrados dan pagina de error SIN redirigir', function (string $case) {
    $user = User::factory()->create();
    $client = oauthClient();
    $over = match ($case) {
        'cliente inexistente' => ['client_id' => '00000000-0000-4000-8000-000000000000'],
        'cliente no uuid' => ['client_id' => 'x'],
        'redirect ajeno' => ['redirect_uri' => 'http://127.0.0.1:53111/otra'],
        'redirect externo' => ['redirect_uri' => 'https://evil.example/cb'],
    };

    $r = $this->actingAs($user)->get(authorizeUrl(authorizeParams($client, $over)));

    $r->assertStatus(400)->assertHeader('Content-Security-Policy', "frame-ancestors 'none'");
    expect($r->headers->has('Location'))->toBeFalse();
    $r->assertInertia(fn (Assert $page) => $page->component('OAuth/Authorize')->where('error.code', 'invalid_request')->missing('params'));
})->with(['cliente inexistente', 'cliente no uuid', 'redirect ajeno', 'redirect externo']);

test('OAuthAuthorize: un loopback con otro puerto sigue siendo el mismo cliente (RFC 8252)', function () {
    $user = User::factory()->create();
    $client = oauthClient();

    $this->actingAs($user)->get(authorizeUrl(authorizeParams($client, ['redirect_uri' => 'http://127.0.0.1:60000/callback'])))->assertOk();
});

test('OAuthAuthorize CA-22: un resource distinto de APP_URL/mcp devuelve invalid_target', function () {
    $user = User::factory()->create();
    $client = oauthClient();

    $r = $this->actingAs($user)->get(authorizeUrl(authorizeParams($client, ['resource' => 'https://otro.example/mcp'])));

    expect(locationQuery($r)['error'])->toBe('invalid_target');
});

test('OAuthAuthorize CA-19: aprobar con publish redirige a redirect_uri con code y state y guarda solo el hash', function () {
    $user = User::factory()->create();
    $client = oauthClient();
    $abilities = Abilities::presets()['publish']['abilities'];

    $r = $this->actingAs($user)->post('/oauth/authorize', authorizeParams($client) + ['decision' => 'approve', 'abilities' => $abilities]);

    $r->assertRedirectContains('http://127.0.0.1:53111/callback?');
    $q = locationQuery($r);
    expect($q['state'])->toBe('estado-xyz')->and($q['code'])->toBeString();

    $row = OAuthAuthCode::firstOrFail();
    expect($row->code_hash)->toBe(hash('sha256', $q['code']))
        ->and($row->code_hash)->not->toBe($q['code'])
        ->and($row->abilities)->toEqualCanonicalizing($abilities)
        ->and($row->user_id)->toBe($user->id)
        ->and($row->client_id)->toBe($client->id)
        ->and($row->code_challenge)->toBe(pkceChallenge())
        ->and($row->resource)->toBe(rtrim(config('app.url'), '/').'/mcp')
        ->and($row->used_at)->toBeNull()
        ->and($row->expires_at->between(now()->addSeconds(290), now()->addSeconds(301)))->toBeTrue();

    $audit = AuditLog::where('action', 'oauth.authorized')->firstOrFail();
    expect($audit->detail)->not->toContain($q['code'])->and($audit->user_id)->toBe($user->id);
});

test('OAuthAuthorize CA-19: denegar redirige a error=access_denied con state y audita oauth.denied', function () {
    $user = User::factory()->create();
    $client = oauthClient();

    $r = $this->actingAs($user)->post('/oauth/authorize', authorizeParams($client) + ['decision' => 'deny']);

    expect(locationQuery($r))->toBe(['error' => 'access_denied', 'state' => 'estado-xyz'])
        ->and(OAuthAuthCode::count())->toBe(0)
        ->and(AuditLog::where('action', 'oauth.denied')->count())->toBe(1);
});

test('OAuthAuthorize: las abilities del codigo son las marcadas y nunca mas que all', function () {
    $user = User::factory()->create();
    $client = oauthClient();

    $this->actingAs($user)->post('/oauth/authorize', authorizeParams($client) + ['decision' => 'approve', 'abilities' => ['posts:read']])->assertRedirect();
    expect(OAuthAuthCode::firstOrFail()->abilities)->toBe(['posts:read']);

    $this->actingAs($user)->post('/oauth/authorize', authorizeParams($client) + ['decision' => 'approve', 'abilities' => ['*']])->assertSessionHasErrors('abilities.0');
    $this->actingAs($user)->post('/oauth/authorize', authorizeParams($client) + ['decision' => 'approve', 'abilities' => []])->assertSessionHasErrors('abilities');
    $this->actingAs($user)->post('/oauth/authorize', authorizeParams($client) + ['decision' => 'approve'])->assertSessionHasErrors('abilities');
    expect(OAuthAuthCode::count())->toBe(1);
});

test('OAuthAuthorize: el POST revalida y no redirige con un redirect_uri manipulado', function () {
    $user = User::factory()->create();
    $client = oauthClient();

    $r = $this->actingAs($user)->post('/oauth/authorize', authorizeParams($client, ['redirect_uri' => 'https://evil.example/cb']) + ['decision' => 'approve', 'abilities' => ['posts:read']]);

    $r->assertStatus(400);
    expect($r->headers->has('Location'))->toBeFalse()->and(OAuthAuthCode::count())->toBe(0);
});

test('OAuthAuthorize: el POST sin sesion no emite nada', function () {
    $client = oauthClient();

    $this->post('/oauth/authorize', authorizeParams($client) + ['decision' => 'approve', 'abilities' => ['posts:read']])->assertRedirect('/login');
    expect(OAuthAuthCode::count())->toBe(0);
});

test('OAuthAuthorize: el codigo en claro no queda en BD ni en la auditoria', function () {
    $user = User::factory()->create();
    $client = oauthClient();

    $r = $this->actingAs($user)->post('/oauth/authorize', authorizeParams($client) + ['decision' => 'approve', 'abilities' => ['posts:read']]);
    $code = locationQuery($r)['code'];

    expect(json_encode(OAuthAuthCode::all()->makeVisible('code_hash')))->not->toContain($code)
        ->and(json_encode(AuditLog::all()))->not->toContain($code);
});
