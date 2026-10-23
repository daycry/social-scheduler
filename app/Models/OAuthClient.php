<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Cliente OAuth publico registrado por DCR (RFC 7591). El nombre lo declara el propio cliente: no es una identidad verificada. */
class OAuthClient extends Model
{
    use HasUuids;

    protected $table = 'oauth_clients';

    protected $guarded = [];

    protected function casts(): array
    {
        return ['redirect_uris' => 'array', 'last_used_at' => 'datetime'];
    }

    public function tokens(): HasMany
    {
        return $this->hasMany(PersonalAccessToken::class, 'oauth_client_id');
    }

    public function authCodes(): HasMany
    {
        return $this->hasMany(OAuthAuthCode::class, 'client_id');
    }
}
