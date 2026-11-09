<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Clave de idempotencia de una peticion (T-19, lotes). `response` nulo = en curso. */
class IdempotencyKey extends Model
{
    protected $guarded = ['id'];
}
