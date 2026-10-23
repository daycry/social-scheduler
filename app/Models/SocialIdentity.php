<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Identidad social (proveedor + id estable del proveedor) de un usuario. El email es solo el del momento de vincular. */
class SocialIdentity extends Model
{
    public const GOOGLE = 'google';

    public const FACEBOOK = 'facebook';

    protected $guarded = [];

    protected function casts(): array
    {
        return ['linked_at' => 'datetime', 'last_login_at' => 'datetime', 'blocked_at' => 'datetime'];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function isBlocked(): bool
    {
        return $this->blocked_at !== null;
    }
}
