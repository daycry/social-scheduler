<?php

namespace App\Models\Studio;

use App\Models\Brand;
use App\Models\Concerns\BelongsToUser;
use App\Scopes\UserScope;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class StudioDataset extends Model
{
    use BelongsToUser;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['revision' => 'integer', 'row_count' => 'integer', 'column_count' => 'integer', 'archived_at' => 'datetime'];
    }

    public function brand(): BelongsTo
    {
        return $this->belongsTo(Brand::class);
    }

    public function revisions(): HasMany
    {
        return $this->hasMany(StudioDatasetRevision::class, 'dataset_id')->withoutGlobalScope(UserScope::class);
    }
}
