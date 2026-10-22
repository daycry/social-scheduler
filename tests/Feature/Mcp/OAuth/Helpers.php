<?php

use App\Models\OAuthClient;

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
