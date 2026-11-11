<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Fila de la media propia de un destino (`post_target_media`, T-06): archivo, portada opcional, alt y posicion. FK restrict a `media_assets`. */
class PostTargetMedia extends Model
{
    public $timestamps = false;

    protected $table = 'post_target_media';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['position' => 'integer'];
    }

    public function target(): BelongsTo
    {
        return $this->belongsTo(PostTarget::class, 'post_target_id');
    }
}
