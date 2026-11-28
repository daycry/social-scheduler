<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * El recurso no es del usuario (o no existe para el): excepcion de dominio sin HTTP.
 * bootstrap/app.php la traduce a 404, igual que un recurso inexistente, para no revelar su existencia.
 */
class OwnershipException extends RuntimeException
{
    public function __construct(string $message = 'Recurso no encontrado.')
    {
        parent::__construct($message);
    }
}
