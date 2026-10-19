<?php

namespace App\Models;

use App\Connectors\Network;
use App\Models\Concerns\BelongsToUser;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Cuenta de una red social conectada a una marca. Los tokens se guardan cifrados (cast
 * `encrypted`) y nunca se serializan (`hidden`).
 *
 * Semantica de las credenciales segun proveedor:
 *  - Meta: access_token = token de Pagina (no caduca); refresh_token = token de usuario de larga
 *    duracion (~60 dias, `token_expires_at`), usado para renovar; data_access_expires_at = 90 dias.
 *  - Google: access_token (1 h) + refresh_token (offline).
 *  - TikTok: access_token (24 h) + refresh_token (365 dias, `refresh_expires_at`).
 */
class SocialAccount extends Model
{
    use BelongsToUser, HasFactory;

    public const STATUS_ACTIVE = 'activa';

    public const STATUS_RECONNECT = 'requiere_reconexion';

    public const STATUS_DISCONNECTED = 'desconectada';

    public const STATUSES = [self::STATUS_ACTIVE, self::STATUS_RECONNECT, self::STATUS_DISCONNECTED];

    protected $fillable = [
        'network', 'external_id', 'name', 'username', 'status', 'access_token', 'refresh_token',
        'token_expires_at', 'refresh_expires_at', 'data_access_expires_at', 'scopes', 'meta',
        'last_error', 'connected_at', 'last_refreshed_at',
    ];

    protected $hidden = ['access_token', 'refresh_token'];

    protected function casts(): array
    {
        return [
            'network' => Network::class,
            'access_token' => 'encrypted',
            'refresh_token' => 'encrypted',
            'token_expires_at' => 'datetime',
            'refresh_expires_at' => 'datetime',
            'data_access_expires_at' => 'datetime',
            'connected_at' => 'datetime',
            'last_refreshed_at' => 'datetime',
            'scopes' => 'array',
            'meta' => 'array',
        ];
    }

    public function brand(): BelongsTo
    {
        return $this->belongsTo(Brand::class);
    }

    public function isActive(): bool
    {
        return $this->status === self::STATUS_ACTIVE;
    }

    public function needsReconnection(): bool
    {
        return $this->status === self::STATUS_RECONNECT;
    }
}
