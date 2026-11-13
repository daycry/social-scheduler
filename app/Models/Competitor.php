<?php

namespace App\Models;

use App\Connectors\Network;
use App\Models\Concerns\BelongsToUser;
use App\Scopes\UserScope;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Cuenta de un tercero seguida con datos PUBLICOS: Instagram (Business/Creator, Business Discovery, PA-08), Bluesky y YouTube (T-25).
 * `username` es el usuario/handle y `external_id` el identificador estable de la red (DID, id de canal) cuando la red lo da.
 */
class Competitor extends Model
{
    use BelongsToUser;

    /** Sin red indicada es Instagram (la unica que habia antes de T-25; coincide con el valor por defecto de la columna). */
    protected $attributes = ['network' => 'instagram'];

    protected $fillable = ['network', 'external_id', 'username', 'name', 'profile_picture_url', 'social_account_id', 'last_synced_at', 'last_error'];

    protected function casts(): array
    {
        return ['last_synced_at' => 'datetime', 'network' => Network::class];
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
