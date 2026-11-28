<?php

namespace App\Connectors\Mastodon;

use App\Connectors\Exceptions\ConnectorException;
use Carbon\CarbonInterface;

/**
 * Error de la API de una instancia de Mastodon o fallo de red. `status` es el HTTP (0 = sin respuesta); `retryAt` solo llega en un 429 (cabeceras
 * `Retry-After` o `X-RateLimit-Reset`); `detail` es el `error` que escribe la instancia, ya limpio y acotado. El mensaje nunca incluye el token.
 */
class MastodonException extends ConnectorException
{
    public function __construct(string $message, public readonly int $status = 0, public readonly ?CarbonInterface $retryAt = null, public readonly string $detail = '')
    {
        parent::__construct($message);
    }

    /** ¿Fallo de red o del servidor (reintentable)? */
    public function isTransient(): bool
    {
        return $this->status === 0 || $this->status === 408 || $this->status >= 500;
    }
}
