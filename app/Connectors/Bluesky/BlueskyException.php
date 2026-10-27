<?php

namespace App\Connectors\Bluesky;

use App\Connectors\Exceptions\ConnectorException;

/**
 * Error devuelto por XRPC (`{error, message}`) o fallo de red. `errorCode` es el nombre del error de atproto
 * (`ExpiredToken`, `InvalidToken`, `AuthenticationRequired`, `RecordNotFound`...); `status` el HTTP (0 = sin respuesta).
 * El mensaje nunca incluye credenciales.
 */
class BlueskyException extends ConnectorException
{
    public function __construct(string $message, public readonly string $errorCode = '', public readonly int $status = 0)
    {
        parent::__construct($message);
    }

    /** ¿La sesion (access/refresh JWT) esta caducada o es invalida? Se resuelve renovandola o recreandola. */
    public function isSessionError(): bool
    {
        return in_array($this->errorCode, ['ExpiredToken', 'InvalidToken', 'AuthenticationRequired', 'BadJwt'], true);
    }

    /** ¿Fallo del lado de la red o del servidor (reintentable)? */
    public function isTransient(): bool
    {
        return $this->status === 0 || $this->status === 429 || $this->status >= 500;
    }
}
