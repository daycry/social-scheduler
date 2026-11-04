<?php

namespace App\Models\Studio;

use App\Models\Concerns\BelongsToUser;
use App\Models\MediaAsset;
use App\Scopes\UserScope;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class StudioAsset extends Model
{
    use BelongsToUser;

    protected $guarded = ['id'];

    public function media(): BelongsTo
    {
        return $this->belongsTo(MediaAsset::class, 'media_asset_id')->withoutGlobalScope(UserScope::class);
    }
}
