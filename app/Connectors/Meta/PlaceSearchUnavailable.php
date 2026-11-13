<?php

namespace App\Connectors\Meta;

use App\Connectors\Exceptions\ConnectorException;

/** La busqueda de lugares de Meta no esta disponible (permisos, credenciales o red); el mensaje explica como seguir pegando el id. */
final class PlaceSearchUnavailable extends ConnectorException
{
    public function __construct(string $message, ?\Throwable $previous = null)
    {
        parent::__construct($message, 0, $previous);
    }
}
