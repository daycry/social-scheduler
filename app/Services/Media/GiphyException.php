<?php

namespace App\Services\Media;

use RuntimeException;

/** Fallo del buscador de GIFs con un mensaje apto para el usuario (nunca lleva la clave ni la URL de Giphy). */
class GiphyException extends RuntimeException
{
    public function __construct(string $message, public readonly int $status = 502)
    {
        parent::__construct($message);
    }
}
