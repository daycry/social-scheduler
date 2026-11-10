<?php

namespace App\Services\Canva;

use RuntimeException;

/** Error de la integracion con Canva con un mensaje ya en español y apto para mostrar al usuario. */
class CanvaException extends RuntimeException
{
    public function __construct(string $message, public readonly int $status = 502, public readonly string $reason = 'canva_error')
    {
        parent::__construct($message);
    }
}
