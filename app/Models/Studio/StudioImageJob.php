<?php

namespace App\Models\Studio;

use App\Models\Concerns\BelongsToUser;
use App\Models\Concerns\PrunesByAge;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class StudioImageJob extends Model
{
    use BelongsToUser;
    use PrunesByAge;

    protected static function retentionKey(): string
    {
        return 'studio_image_jobs_days';
    }

    protected static function pruneScope(Builder $query): Builder
    {
        return $query->whereIn('status', ['ready', 'failed']);
    }

    protected $guarded = ['id'];

    protected $hidden = ['model_path'];
}
