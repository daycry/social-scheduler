<?php

namespace App\Connectors\Pinterest;

use App\Connectors\Exceptions\PermanentPublishException;

/** La URL prefirmada de subida fue rechazada (400/403/404/410: caducada o invalida): el publisher decide si registra de nuevo. */
class PinterestUploadRejectedException extends PermanentPublishException
{
    public function __construct(public readonly int $status)
    {
        parent::__construct('Pinterest rechazo la subida del video (HTTP '.$status.').', 'subida_rechazada');
    }
}
