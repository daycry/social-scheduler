<?php

namespace App\Exceptions;

/**
 * La operacion no es posible en el estado actual de la publicacion (ya publicada o en vuelo, ya cancelada...). Es un conflicto de estado,
 * no un error de entrada: la API responde 409 y el MCP lo marca como conflicto. Sigue siendo una `PostValidationException` para quien solo
 * muestra el motivo (la web).
 */
class PostStateConflict extends PostValidationException
{
    public function __construct(string $message)
    {
        parent::__construct(['post' => [$message]]);
    }
}
