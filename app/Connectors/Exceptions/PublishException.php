<?php

namespace App\Connectors\Exceptions;

/**
 * Error al publicar o sondear. Las subclases fijan como reacciona la cola:
 * transitorio (se reintenta), permanente (falla con motivo) o cuota (se aplaza).
 * El mensaje es apto para el usuario (sin secretos).
 */
abstract class PublishException extends ConnectorException
{
    public function __construct(string $message, public readonly ?string $errorCode = null, ?\Throwable $previous = null)
    {
        parent::__construct($message, 0, $previous);
    }
}
