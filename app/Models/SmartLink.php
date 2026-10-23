<?php

namespace App\Models;

use App\Models\Concerns\BelongsToUser;
use App\Scopes\UserScope;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Microsite «link in bio» de una marca (PA-07). Las relaciones internas quitan el UserScope: la pagina publica las
 * lee sin usuario autenticado, desde un SmartLink ya localizado por su slug.
 */
class SmartLink extends Model
{
    use BelongsToUser;

    public const SLUG_PATTERN = '[a-z0-9](?:[a-z0-9-]{1,38}[a-z0-9])';

    protected $fillable = ['slug', 'title', 'bio', 'avatar_media_id', 'theme_color', 'theme_mode', 'published'];

    protected function casts(): array
    {
        return ['published' => 'boolean'];
    }

    public function brand(): BelongsTo
    {
        return $this->belongsTo(Brand::class)->withoutGlobalScope(UserScope::class);
    }

    public function avatar(): BelongsTo
    {
        return $this->belongsTo(MediaAsset::class, 'avatar_media_id')->withoutGlobalScope(UserScope::class);
    }

    public function blocks(): HasMany
    {
        return $this->hasMany(SmartLinkBlock::class)->orderBy('position')->orderBy('id');
    }

    public function url(): string
    {
        return url('/l/'.$this->slug);
    }
}
