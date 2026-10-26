<?php

namespace App\Services\Media;

use RuntimeException;

/** Interna: la reanudacion no devolvio la parte pedida. `reason` dice por que, cuando se sabe (el remoto ignora `Range` o el fichero cambio). */
final class RangeIgnored extends RuntimeException
{
    public const IGNORED = 'ignored';

    public const CHANGED = 'changed';

    public function __construct(public readonly ?string $reason = null)
    {
        parent::__construct('El servidor remoto no devolvió la parte pedida.');
    }
}
