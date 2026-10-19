<?php

namespace App\Models;

use App\Connectors\Network;
use App\Models\Concerns\BelongsToUser;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Foto diaria de las metricas de una cuenta (una fila por cuenta y dia; `null` = no disponible). */
class AccountSnapshot extends Model
{
    use BelongsToUser;

    protected $fillable = ['user_id', 'brand_id', 'social_account_id', 'network', 'date', 'metrics', 'collected_at', 'settled_at'];

    protected function casts(): array
    {
        return [
            'network' => Network::class,
            'metrics' => 'array',
            'collected_at' => 'datetime',
            'settled_at' => 'datetime',
        ];
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(SocialAccount::class, 'social_account_id');
    }
}
