<?php

namespace App\Services\Studio;

use RuntimeException;

class StudioAvProcessFailed extends RuntimeException
{
    public function __construct(public readonly string $errorCode)
    {
        parent::__construct('No se pudo completar la fase audiovisual.');
    }
}
