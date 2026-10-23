<?php

namespace App\Connectors\Exceptions;

use Carbon\CarbonInterface;

/** Cuota de publicacion agotada (p. ej. content_publishing_limit de IG). `retryAt` = cuando se libera, si se sabe. */
class QuotaExceededException extends PublishException
{
    public function __construct(string $message, public readonly ?CarbonInterface $retryAt = null, ?string $errorCode = 'cuota', ?\Throwable $previous = null)
    {
        parent::__construct($message, $errorCode, $previous);
    }
}
