<?php

use App\Models\OAuthAuthCode;
use App\Models\OAuthClient;
use App\Models\User;
use App\Services\Mcp\OAuth\AuthorizationServer;

// Ayudantes compartidos por los tests del servidor OAuth del MCP (cargados con require_once).

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

const TOKEN_VERIFIER = 'dBjftJeZ4CVP-mB92K27uhbUJU1p1r_wW1gFWFOEjXk';

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

/**
 * Recorre el flujo de un cliente real: 401 -> metadatos -> DCR -> authorize (sesion) -> token -> tool del MCP.
 *
 * @return array{token: string, client_id: string, code: string}
 */
function runOAuthFlow(User $user, array $abilities, string $verifier = 'v-erifier-de-prueba-0123456789-0123456789-abcdef'): array
{
    $t = test();
    $unauth = $t->postJson('/mcp', ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'initialize'])->assertUnauthorized();
    preg_match('/resource_metadata="([^"]+)"/', $unauth->headers->get('WWW-Authenticate'), $m);
    $prm = $t->getJson(parse_url($m[1], PHP_URL_PATH))->assertOk()->json();
    $asm = $t->getJson('/.well-known/oauth-authorization-server')->assertOk()->json();
    expect($prm['authorization_servers'])->toBe([$asm['issuer']]);

    $client = $t->postJson(parse_url($asm['registration_endpoint'], PHP_URL_PATH), [
        'client_name' => 'Cliente de flujo', 'redirect_uris' => ['http://127.0.0.1:53111/callback'],
    ])->assertCreated()->json();

    $q = [
        'response_type' => 'code', 'client_id' => $client['client_id'], 'redirect_uri' => 'http://127.0.0.1:53111/callback',
        'code_challenge' => AuthorizationServer::pkceChallenge($verifier), 'code_challenge_method' => 'S256',
        'state' => 'st', 'resource' => $prm['resource'],
    ];
    $t->actingAs($user)->get(parse_url($asm['authorization_endpoint'], PHP_URL_PATH).'?'.http_build_query($q))->assertOk();
    $approve = $t->post(parse_url($asm['authorization_endpoint'], PHP_URL_PATH), $q + ['decision' => 'approve', 'abilities' => $abilities]);
    $code = locationQuery($approve)['code'];

    $token = $t->post(parse_url($asm['token_endpoint'], PHP_URL_PATH), [
        'grant_type' => 'authorization_code', 'code' => $code, 'redirect_uri' => $q['redirect_uri'],
        'client_id' => $client['client_id'], 'code_verifier' => $verifier, 'resource' => $prm['resource'],
    ])->assertOk()->json('access_token');

    return ['token' => $token, 'client_id' => $client['client_id'], 'code' => $code];
}
