<?php

namespace App\Models;

use App\Models\Concerns\BelongsToUser;
use Illuminate\Database\Eloquent\Model;

/** Publicacion publica de un competidor (Business Discovery, T-20). Likes y comentarios pueden ser null si la cuenta los oculta. */
class CompetitorPost extends Model
{
    use BelongsToUser;

    protected $fillable = ['competitor_id', 'external_id', 'caption', 'media_type', 'permalink', 'likes_count', 'comments_count', 'posted_at'];

    protected function casts(): array
    {
        return ['likes_count' => 'integer', 'comments_count' => 'integer', 'posted_at' => 'datetime'];
    }

    /** Interacciones visibles (likes + comentarios); null si la API no dio ninguna de las dos. */
    public function interactions(): ?int
    {
        return $this->likes_count === null && $this->comments_count === null ? null : (int) $this->likes_count + (int) $this->comments_count;
    }
}
