<?php

namespace App\Exceptions;

use App\Enums\PostStatus;
use LogicException;

class InvalidPostTransition extends LogicException
{
    public function __construct(public readonly PostStatus $from, public readonly PostStatus $to)
    {
        parent::__construct("Transicion de estado no permitida: {$from->value} -> {$to->value}.");
    }
}
