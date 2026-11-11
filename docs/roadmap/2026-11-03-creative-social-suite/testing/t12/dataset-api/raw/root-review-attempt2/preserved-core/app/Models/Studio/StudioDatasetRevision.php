<?php

namespace App\Models\Studio;

use App\Models\Brand;
use App\Models\Concerns\BelongsToUser;
use App\Scopes\UserScope;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

class StudioDatasetRevision extends Model
{
    use BelongsToUser;

    protected $guarded = ['id'];

    protected $hidden = ['user_id', 'path'];

    protected function casts(): array
    {
        return ['revision' => 'integer', 'row_count' => 'integer', 'column_count' => 'integer', 'json_bytes' => 'integer'];
    }

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('Dataset revisions are immutable.'));
        static::deleting(fn () => throw new LogicException('Dataset revisions are immutable.'));
    }

    public function dataset(): BelongsTo
    {
        return $this->belongsTo(StudioDataset::class, 'dataset_id')->withoutGlobalScope(UserScope::class);
    }

    public function brand(): BelongsTo
    {
        return $this->belongsTo(Brand::class);
    }
}
