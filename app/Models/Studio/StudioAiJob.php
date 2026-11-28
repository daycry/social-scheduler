<?php

namespace App\Models\Studio;

use App\Models\Concerns\BelongsToUser;
use App\Models\Concerns\PrunesByAge;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/** Trabajo de IA creativa (T-14): generar o editar imagenes con un proveedor configurado. */
class StudioAiJob extends Model
{
    use BelongsToUser;
    use PrunesByAge;

    protected static function retentionKey(): string
    {
        return 'studio_ai_jobs_days';
    }

    protected static function pruneScope(Builder $query): Builder
    {
        return $query->whereIn('status', ['ready', 'failed']);
    }

    protected $guarded = ['id'];

    protected $hidden = ['fingerprint', 'idempotency_key'];

    protected function casts(): array
    {
        return ['params' => 'array', 'output_media_ids' => 'array', 'count' => 'integer', 'attempts' => 'integer'];
    }
}
