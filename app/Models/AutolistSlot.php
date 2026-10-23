<?php

namespace App\Models;

use App\Models\Concerns\BelongsToUser;
use Illuminate\Database\Eloquent\Model;

/** Franja ya generada de una autolista (idempotencia del job diario): enlaza con la publicacion creada o con el motivo de omision. */
class AutolistSlot extends Model
{
    use BelongsToUser;

    protected $fillable = ['slot_at', 'local_date', 'local_time', 'post_id', 'skipped_reason'];

    protected function casts(): array
    {
        return ['slot_at' => 'datetime'];
    }
}
