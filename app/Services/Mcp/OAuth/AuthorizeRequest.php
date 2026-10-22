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
    ) {}

    /** @return array<string, string> parametros a reenviar en el POST (el servidor los revalida) */
    public function toParams(): array
    {
        return array_filter([
            'response_type' => 'code',
            'client_id' => $this->client->id,
            'redirect_uri' => $this->redirectUri,
            'code_challenge' => $this->codeChallenge,
            'code_challenge_method' => 'S256',
            'state' => $this->state,
            'resource' => $this->resource,
        ], fn ($v) => $v !== null);
    }
}
