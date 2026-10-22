<?php

use App\Mcp\Abilities;
use App\Models\AuditLog;
use App\Models\OAuthAuthCode;
use App\Models\OAuthClient;
use App\Models\PersonalAccessToken;
use App\Models\User;
use Illuminate\Support\Facades\RateLimiter;

require_once __DIR__.'/Helpers.php';

const TOKEN_VERIFIER = 'dBjftJeZ4CVP-mB92K27uhbUJU1p1r_wW1gFWFOEjXk';

beforeEach(fn () => RateLimiter::clear('mcp-oauth-token:127.0.0.1'));

/** Crea un codigo valido directamente en BD y devuelve [codigo en claro, fila, usuario, cliente]. */
function mintCode(array $abilities = ['posts:read'], array $over = [], ?User $user = null, ?OAuthClient $client = null): array
{
    $user ??= User::factory()->create();
    $client ??= oauthClient();
    $code = 'codigo-'.bin2hex(random_bytes(8));
    $row = OAuthAuthCode::create($over + [
        'code_hash' => hash('sha256', $code),
        'client_id' => $client->id,
        'user_id' => $user->id,
        'redirect_uri' => $client->redirect_uris[0],
        'code_challenge' => pkceChallenge(TOKEN_VERIFIER),
        'abilities' => $abilities,
        'resource' => rtrim(config('app.url'), '/').'/mcp',
        'expires_at' => now()->addMinutes(5),
    ]);

    return [$code, $row, $user, $client];
}

function redeem(string $code, OAuthClient $client, array $over = [])
{
    return test()->post('/oauth/token', array_filter($over + [
        'grant_type' => 'authorization_code',
        'code' => $code,
        'redirect_uri' => $client->redirect_uris[0],
        'client_id' => $client->id,
        'code_verifier' => TOKEN_VERIFIER,
    ], fn ($v) => $v !== null));
}

test('OAuthToken CA-20: un codigo valido da un token msk_ con las abilities exactas y oauth_client_id', function () {
    [$code, $row, $user, $client] = mintCode(['posts:read', 'brands:read']);

    $r = redeem($code, $client)->assertOk();

    $r->assertHeader('Cache-Control', 'no-store, private')->assertJsonPath('token_type', 'Bearer')
        ->assertJsonPath('expires_in', 30 * 86400)->assertJsonPath('scope', 'mcp:use');
    expect($r->json('access_token'))->toContain('|msk_');

    $token = PersonalAccessToken::findOrFail((int) explode('|', $r->json('access_token'))[0]);
    expect($token->abilities)->toBe(['posts:read', 'brands:read'])
        ->and($token->oauth_client_id)->toBe($client->id)
        ->and($token->tokenable_id)->toBe($user->id)
        ->and($token->expires_at->between(now()->addDays(30)->subMinute(), now()->addDays(30)->addMinute()))->toBeTrue()
        ->and($row->fresh()->token_id)->toBe($token->id)
        ->and($row->fresh()->used_at)->not->toBeNull();
});

test('OAuthToken: el token emitido entra en /mcp', function () {
    [$code, , , $client] = mintCode(['brands:read']);
    $plain = redeem($code, $client)->assertOk()->json('access_token');

    app('auth')->forgetGuards();
    $this->postJson('/mcp', ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/list', 'params' => (object) []], [
        'Authorization' => 'Bearer '.$plain, 'Accept' => 'application/json, text/event-stream',
    ])->assertOk();
});

test('OAuthToken: token_days de la config manda en expires_in', function () {
    config(['services.mcp.oauth.token_days' => 7]);
    [$code, , , $client] = mintCode();

    redeem($code, $client)->assertOk()->assertJsonPath('expires_in', 7 * 86400);
});

test('OAuthToken CA-21: cada parametro erroneo da 400 invalid_grant y no emite token', function (string $case) {
    [$code, , , $client] = mintCode();
    $other = oauthClient(['http://127.0.0.1:1/otro']);
    $over = match ($case) {
        'verifier erroneo' => ['code_verifier' => str_repeat('x', 43)],
        'otro redirect_uri' => ['redirect_uri' => 'http://127.0.0.1:53111/otra'],
        'sin redirect_uri' => ['redirect_uri' => null],
        'otro client_id' => ['client_id' => $other->id],
        'client_id desconocido' => ['client_id' => '00000000-0000-4000-8000-000000000000'],
        'codigo inexistente' => ['code' => 'no-existe'],
    };
    redeem($code, $client, $over)->assertStatus(400)->assertJsonPath('error', 'invalid_grant');
    expect(PersonalAccessToken::count())->toBe(0);
})->with(['verifier erroneo', 'otro redirect_uri', 'sin redirect_uri', 'otro client_id', 'client_id desconocido', 'codigo inexistente']);

test('OAuthToken CA-21: un codigo caducado da invalid_grant', function () {
    [$code, $row, , $client] = mintCode();
    $row->update(['expires_at' => now()->subSecond()]);

    redeem($code, $client)->assertStatus(400)->assertJsonPath('error', 'invalid_grant');
    expect(PersonalAccessToken::count())->toBe(0);
});

test('OAuthToken CA-21: un intento con verifier erroneo quema el codigo', function () {
    [$code, , , $client] = mintCode();

    redeem($code, $client, ['code_verifier' => str_repeat('x', 43)])->assertStatus(400);
    redeem($code, $client)->assertStatus(400)->assertJsonPath('error', 'invalid_grant');
    expect(PersonalAccessToken::count())->toBe(0);
});

test('OAuthToken CA-21: reutilizar un codigo ya canjeado da invalid_grant y revoca el token emitido', function () {
    [$code, , , $client] = mintCode();
    $first = redeem($code, $client)->assertOk()->json('access_token');
    expect(PersonalAccessToken::count())->toBe(1);

    redeem($code, $client)->assertStatus(400)->assertJsonPath('error', 'invalid_grant');

    expect(PersonalAccessToken::count())->toBe(0)
        ->and(AuditLog::where('action', 'oauth.code.replayed')->count())->toBe(1);

    app('auth')->forgetGuards();
    $this->postJson('/mcp', ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/list'], ['Authorization' => 'Bearer '.$first])->assertUnauthorized();
});

test('OAuthToken: la carrera perdida (codigo consumido por otra peticion) revoca el token del ganador', function () {
    [$code, $row, , $client] = mintCode();
    // Simula al ganador: ya consumio el codigo y tiene su token, pero la peticion actual leyo la fila antes.
    $winner = User::find($row->user_id)->createToken('ganador', ['posts:read'])->accessToken;
    OAuthAuthCode::whereKey($row->id)->update(['used_at' => now(), 'token_id' => $winner->id]);

    redeem($code, $client)->assertStatus(400)->assertJsonPath('error', 'invalid_grant');

    expect(PersonalAccessToken::find($winner->id))->toBeNull();
});

test('OAuthToken: grant_type distinto da unsupported_grant_type', function (mixed $grant) {
    [$code, , , $client] = mintCode();

    redeem($code, $client, ['grant_type' => $grant])->assertStatus(400)->assertJsonPath('error', 'unsupported_grant_type');
})->with(['refresh_token', 'client_credentials', 'password', null]);

test('OAuthToken: faltan parametros -> invalid_request', function (string $missing) {
    [$code, , , $client] = mintCode();

    redeem($code, $client, [$missing => ''])->assertStatus(400)->assertJsonPath('error', 'invalid_request');
})->with(['code', 'client_id', 'code_verifier']);

test('OAuthToken CA-22: un resource distinto da invalid_target', function () {
    [$code, , , $client] = mintCode();

    redeem($code, $client, ['resource' => 'https://otro.example/mcp'])->assertStatus(400)->assertJsonPath('error', 'invalid_target');
    redeem($code, $client, ['resource' => rtrim(config('app.url'), '/').'/mcp'])->assertOk();
});

test('OAuthToken: acepta el cuerpo en JSON ademas de form', function () {
    [$code, , , $client] = mintCode();

    $this->postJson('/oauth/token', [
        'grant_type' => 'authorization_code', 'code' => $code, 'redirect_uri' => $client->redirect_uris[0],
        'client_id' => $client->id, 'code_verifier' => TOKEN_VERIFIER,
    ])->assertOk();
});

test('OAuthToken: los valores no string no provocan 500', function () {
    $this->postJson('/oauth/token', ['grant_type' => 'authorization_code', 'code' => ['x'], 'client_id' => ['y'], 'code_verifier' => ['z']])
        ->assertStatus(400);
});

test('OAuthToken: la peticion 31 en un minuto responde 429', function () {
    foreach (range(1, 30) as $i) {
        $this->post('/oauth/token', ['grant_type' => 'authorization_code'])->assertStatus(400);
    }

    $this->post('/oauth/token', ['grant_type' => 'authorization_code'])->assertStatus(429);
});

test('OAuthToken: solo se guarda el hash del codigo y la auditoria no lleva secretos', function () {
    [$code, , , $client] = mintCode();
    $plain = redeem($code, $client)->assertOk()->json('access_token');

    $audit = json_encode(AuditLog::all());
    $codes = json_encode(OAuthAuthCode::all()->makeVisible('code_hash'));
    expect($codes)->not->toContain($code)
        ->and($audit)->not->toContain($code)->not->toContain(TOKEN_VERIFIER)->not->toContain(explode('|', $plain)[1])
        ->and(AuditLog::where('action', 'oauth.token.issued')->count())->toBe(1);
});

test('OAuthToken: las abilities emitidas son las del codigo y nunca superan a all', function () {
    [$code, , , $client] = mintCode(Abilities::keys());

    $r = redeem($code, $client)->assertOk();
    $token = PersonalAccessToken::findOrFail((int) explode('|', $r->json('access_token'))[0]);

    expect($token->abilities)->toBe(Abilities::keys());
});
