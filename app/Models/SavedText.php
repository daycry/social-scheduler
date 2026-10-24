<?php

namespace App\Models;

use App\Models\Concerns\BelongsToUser;
use App\Scopes\UserScope;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Texto guardado (C-01a): fragmento o grupo de hashtags por usuario; `brand_id` nulo = «Todas las marcas». */
class SavedText extends Model
{
    use BelongsToUser, HasFactory;

    public const KIND_FRAGMENT = 'fragmento';

    public const KIND_HASHTAGS = 'hashtags';

    public const KINDS = [self::KIND_FRAGMENT, self::KIND_HASHTAGS];

    protected $fillable = ['brand_id', 'kind', 'title', 'body'];

    public function brand(): BelongsTo
    {
        return $this->belongsTo(Brand::class)->withoutGlobalScope(UserScope::class);
    }
}
