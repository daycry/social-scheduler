<?php

namespace App\Models\Studio;

use App\Models\Concerns\BelongsToUser;
use App\Models\Concerns\PrunesByAge;
use App\Scopes\UserScope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DesignExport extends Model
{
    use BelongsToUser;
    use PrunesByAge;

    protected static function retentionKey(): string
    {
        return 'studio_design_exports_days';
    }

    protected static function pruneScope(Builder $query): Builder
    {
        return $query->whereIn('status', ['failed', 'cancelled'])->whereNull('path');
    }

    protected $table = 'studio_design_exports';

    protected $guarded = ['id'];

    protected $hidden = ['disk', 'path', 'render_state', 'render_progress'];

    protected function casts(): array
    {
        return ['revision' => 'integer', 'pages' => 'array', 'media_ids' => 'array', 'options' => 'array', 'render_state' => 'array', 'render_progress' => 'array'];
    }

    public function design(): BelongsTo
    {
        return $this->belongsTo(Design::class)->withoutGlobalScope(UserScope::class)->withTrashed();
    }
}
