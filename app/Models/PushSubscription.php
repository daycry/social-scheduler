<?php

namespace App\Models;

use App\Models\Concerns\BelongsToUser;
use Illuminate\Database\Eloquent\Model;

class PushSubscription extends Model
{
    use BelongsToUser;

    protected $hidden = ['endpoint', 'keys', 'endpoint_hash'];

    protected function casts(): array
    {
        return ['endpoint' => 'encrypted', 'keys' => 'encrypted:array', 'last_seen_at' => 'datetime', 'last_sent_at' => 'datetime', 'last_attempted_at' => 'datetime'];
    }
}
