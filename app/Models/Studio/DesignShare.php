<?php

namespace App\Models\Studio;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Acceso de un usuario registrado a un diseño ajeno. Sin UserScope: lo consulta tanto el propietario como el invitado. */
class DesignShare extends Model
{
    protected $table = 'studio_design_shares';

    protected $guarded = ['id'];

    public function guest(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }
}
