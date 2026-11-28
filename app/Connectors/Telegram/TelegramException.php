<?php

namespace App\Connectors\Telegram;

use App\Connectors\Exceptions\ConnectorException;

/**
 * Error de la Bot API de Telegram o fallo de red. `status` es el HTTP (0 = sin respuesta); `description` es la que devuelve Telegram (limpia y acotada);
 * `retryAfter` son los segundos de espera de un 429 (`parameters.retry_after`). `ambiguous`: la peticion pudo llegar antes de fallar (timeout tras enviar, respuesta cortada) y no se sabe si el mensaje salio. El mensaje y la descripcion nunca incluyen el token ni la URL, que lo lleva en la ruta.
 */
class TelegramException extends ConnectorException
{
    public function __construct(string $message, public readonly int $status = 0, public readonly string $description = '', public readonly ?int $retryAfter = null, public readonly bool $ambiguous = false)
    {
        parent::__construct($message);
    }

    /** ¿Fallo de red o del servidor (reintentable)? */
    public function isTransient(): bool
    {
        return ($this->status === 0 && ! $this->ambiguous) || $this->status === 408 || $this->status >= 500;
    }
}
