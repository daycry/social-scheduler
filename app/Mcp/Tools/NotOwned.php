<?php

namespace App\Mcp\Tools;

use RuntimeException;

/** Recurso inexistente o de otro usuario: mismo mensaje para ambos (no se filtra la existencia). */
class NotOwned extends RuntimeException
{
    public function __construct(string $message, public readonly string $resource, public readonly ?int $id, public readonly bool $crossUser)
    {
        parent::__construct($message);
    }
}
