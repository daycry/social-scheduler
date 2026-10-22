<?php

namespace App\Services\Mcp\OAuth;

use App\Models\OAuthClient;

/** Peticion de /oauth/authorize ya validada. */
final class AuthorizeRequest
{
    public function __construct(
        public readonly OAuthClient $client,
        public readonly string $redirectUri,
        public readonly string $codeChallenge,
        public readonly ?string $state,
        public readonly string $resource,
        public readonly bool $redirectUriSent = true,
    ) {}

    /** @return array<string, string> parametros a reenviar en el POST (el servidor los revalida) */
    public function toParams(): array
    {
        return array_filter([
            'response_type' => 'code',
            'client_id' => $this->client->id,
            // Si el cliente la omitio no se reenvia: el POST la vuelve a inferir y el codigo recuerda que no se envio.
            'redirect_uri' => $this->redirectUriSent ? $this->redirectUri : null,
            'code_challenge' => $this->codeChallenge,
            'code_challenge_method' => 'S256',
            'state' => $this->state,
            'resource' => $this->resource,
        ], fn ($v) => $v !== null);
    }
}
