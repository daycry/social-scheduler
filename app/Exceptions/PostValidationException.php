<?php

namespace App\Exceptions;

use InvalidArgumentException;

/** Datos de publicacion invalidos. `errors()` agrupa los motivos por campo del formulario. */
class PostValidationException extends InvalidArgumentException
{
    /** @param array<string, list<string>> $errors */
    public function __construct(private readonly array $errors)
    {
        parent::__construct(collect($errors)->flatten()->implode(' '));
    }

    /** @return array<string, list<string>> */
    public function errors(): array
    {
        return $this->errors;
    }
}
