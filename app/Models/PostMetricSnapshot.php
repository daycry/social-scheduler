<?php

namespace App\Models;

use App\Connectors\Network;
use App\Models\Concerns\BelongsToUser;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Metricas de una publicacion (destino) en un dia; `null` = no disponible. */
class PostMetricSnapshot extends Model
{
    use BelongsToUser;

    protected $fillable = ['user_id', 'post_target_id', 'social_account_id', 'network', 'date', 'metrics', 'collected_at'];

    protected function casts(): array
    {
        return [
            'network' => Network::class,
            'metrics' => 'array',
            'collected_at' => 'datetime',
        ];
    }

    public function target(): BelongsTo
    {
        return $this->belongsTo(PostTarget::class, 'post_target_id');
    }
}
