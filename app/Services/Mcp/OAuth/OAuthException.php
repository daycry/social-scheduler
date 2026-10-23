<?php

namespace App\Services\Mcp\OAuth;

use RuntimeException;

/**
 * Error del servidor de autorizacion OAuth del MCP. Si lleva `redirectUri` el error se devuelve al cliente por
 * redirect (con `state`); sin el, es FATAL (client_id o redirect_uri no validos) y NUNCA se redirige (CA-17).
 */
class OAuthException extends RuntimeException
{
    public function __construct(
        public readonly string $error,
        public readonly string $description,
        public readonly ?string $redirectUri = null,
        public readonly ?string $state = null,
        public readonly int $status = 400,
    ) {
        parent::__construct($description);
    }

    public function isFatal(): bool
    {
        return $this->redirectUri === null;
    }

    /** URL de retorno al cliente con `error`, `error_description` y `state`. */
    public function redirectUrl(): string
    {
        return AuthorizationServer::appendQuery((string) $this->redirectUri, array_filter([
            'error' => $this->error,
            'error_description' => $this->description,
            'state' => $this->state,
        ], fn ($v) => $v !== null));
    }
}
