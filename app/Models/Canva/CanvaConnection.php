<?php

namespace App\Models\Canva;

use App\Models\Brand;
use App\Models\Concerns\BelongsToUser;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Conexion OAuth de una marca con Canva (T-16). Los tokens van cifrados y no se serializan. */
class CanvaConnection extends Model
{
    use BelongsToUser;

    protected $guarded = ['id'];

    protected $hidden = ['access_token', 'refresh_token'];

    protected function casts(): array
    {
        return [
            'access_token' => 'encrypted',
            'refresh_token' => 'encrypted',
            'expires_at' => 'datetime',
            'capabilities_at' => 'datetime',
            'scopes' => 'array',
            'capabilities' => 'array',
        ];
    }

    public function brand(): BelongsTo
    {
        return $this->belongsTo(Brand::class);
    }
}
