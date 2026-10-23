<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Laravel\Sanctum\PersonalAccessToken as SanctumPersonalAccessToken;

/** Token de Sanctum con el origen OAuth opcional (`oauth_client_id`); las API keys manuales lo dejan a NULL. */
class PersonalAccessToken extends SanctumPersonalAccessToken
{
    public function oauthClient(): BelongsTo
    {
        return $this->belongsTo(OAuthClient::class, 'oauth_client_id');
    }
}
