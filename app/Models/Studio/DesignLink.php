<?php

namespace App\Models\Studio;

use Illuminate\Database\Eloquent\Model;

/** Enlace público de lectura o comentario. Solo se guarda el hash SHA-256 del token. */
class DesignLink extends Model
{
    protected $table = 'studio_design_links';

    protected $guarded = ['id'];

    protected $hidden = ['token_hash'];

    protected function casts(): array
    {
        return ['expires_at' => 'datetime', 'revoked_at' => 'datetime', 'last_used_at' => 'datetime'];
    }

    public function active(): bool
    {
        return $this->revoked_at === null && ($this->expires_at === null || $this->expires_at->isFuture());
    }
}
