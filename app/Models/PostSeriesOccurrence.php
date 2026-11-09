<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Reserva de (serie, fecha local): su clave unica evita duplicar una ocurrencia aunque el generador corra dos veces. */
class PostSeriesOccurrence extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['occurrence_date' => 'date:Y-m-d'];
    }
}
