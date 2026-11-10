<?php

namespace App\Models;

use App\Models\Concerns\BelongsToUser;
use App\Scopes\UserScope;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Serie recurrente de publicaciones (T-19). Las ocurrencias son posts reales generados por `SeriesService`. */
class PostSeries extends Model
{
    use BelongsToUser;

    public const ACTIVE = 'activa';

    public const CANCELLED = 'cancelada';

    protected $table = 'post_series';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['weekdays' => 'array', 'payload' => 'array', 'starts_on' => 'date:Y-m-d', 'ends_on' => 'date:Y-m-d', 'cancelled_at' => 'datetime', 'last_error_at' => 'datetime', 'max_occurrences' => 'integer'];
    }

    public function brand(): BelongsTo
    {
        return $this->belongsTo(Brand::class)->withoutGlobalScope(UserScope::class);
    }

    public function occurrences(): HasMany
    {
        return $this->hasMany(PostSeriesOccurrence::class, 'series_id');
    }
}
