<?php

namespace App\Models;

use App\Scopes\UserScope;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Colaborador invitado de una marca (T-19). Sin UserScope: lo consultan tanto el propietario (que gestiona) como el invitado
 * (que aprueba). Rol unico por ahora: `aprobador`.
 */
class BrandCollaborator extends Model
{
    public const ROLE_APPROVER = 'aprobador';

    public const MAX_PER_BRAND = 20;

    protected $guarded = ['id'];

    public function guest(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function brand(): BelongsTo
    {
        return $this->belongsTo(Brand::class)->withoutGlobalScope(UserScope::class);
    }
}
