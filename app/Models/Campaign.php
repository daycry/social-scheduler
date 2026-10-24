<?php

namespace App\Models;

use App\Models\Concerns\BelongsToUser;
use App\Scopes\UserScope;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Campaña (C-03): etiqueta por marca con color y fechas que agrupa posts enteros para medirlos juntos. */
class Campaign extends Model
{
    use BelongsToUser, HasFactory;

    protected $fillable = ['brand_id', 'name', 'color', 'starts_on', 'ends_on'];

    protected function casts(): array
    {
        return ['starts_on' => 'date', 'ends_on' => 'date', 'archived_at' => 'datetime'];
    }

    public function brand(): BelongsTo
    {
        return $this->belongsTo(Brand::class)->withoutGlobalScope(UserScope::class);
    }

    public function posts(): HasMany
    {
        return $this->hasMany(Post::class)->withoutGlobalScope(UserScope::class);
    }

    public function isArchived(): bool
    {
        return $this->archived_at !== null;
    }
}
