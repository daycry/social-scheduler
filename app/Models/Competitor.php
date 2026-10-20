<?php

namespace App\Models;

use App\Models\Concerns\BelongsToUser;
use App\Scopes\UserScope;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Cuenta de Instagram de un tercero (Business/Creator) seguida con Business Discovery (PA-08). */
class Competitor extends Model
{
    use BelongsToUser;

    protected $fillable = ['username', 'name', 'profile_picture_url', 'social_account_id', 'last_synced_at', 'last_error'];

    protected function casts(): array
    {
        return ['last_synced_at' => 'datetime'];
    }

    public function brand(): BelongsTo
    {
        return $this->belongsTo(Brand::class)->withoutGlobalScope(UserScope::class);
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(SocialAccount::class, 'social_account_id')->withoutGlobalScope(UserScope::class);
    }

    public function snapshots(): HasMany
    {
        return $this->hasMany(CompetitorSnapshot::class)->withoutGlobalScope(UserScope::class)->orderBy('date');
    }
}
