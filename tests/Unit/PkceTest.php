<?php

use App\Services\Mcp\OAuth\AuthorizationServer;

// Vectores del RFC 7636 apendice B.
const PKCE_VERIFIER = 'dBjftJeZ4CVP-mB92K27uhbUJU1p1r_wW1gFWFOEjXk';
const PKCE_CHALLENGE = 'E9Melhoa2OwvFrEMTJguCHaoeK1t8URWbuGJSstw-cM';

test('el challenge S256 del RFC 7636 B se calcula bien', function () {
    expect(AuthorizationServer::pkceChallenge(PKCE_VERIFIER))->toBe(PKCE_CHALLENGE);
});

test('el verifier correcto coincide', function () {
    expect(AuthorizationServer::pkceMatches(PKCE_VERIFIER, PKCE_CHALLENGE))->toBeTrue();
});

test('un verifier distinto, modificado o el propio challenge no coinciden', function (string $verifier) {
    expect(AuthorizationServer::pkceMatches($verifier, PKCE_CHALLENGE))->toBeFalse();
})->with([
    'otro valido' => [str_repeat('a', 43)],
    'un caracter cambiado' => ['dBjftJeZ4CVP-mB92K27uhbUJU1p1r_wW1gFWFOEjXl'],
    'el challenge como verifier' => [PKCE_CHALLENGE],
    'demasiado corto' => ['abc'],
    'demasiado largo' => [str_repeat('a', 129)],
    'caracteres ilegales' => ['dBjftJeZ4CVP-mB92K27uhbUJU1p1r_wW1gFWFOEjX!'],
    'vacio' => [''],
]);

test('plain no es equivalente: challenge == verifier no valida', function () {
    expect(AuthorizationServer::pkceMatches(PKCE_VERIFIER, PKCE_VERIFIER))->toBeFalse();
});
