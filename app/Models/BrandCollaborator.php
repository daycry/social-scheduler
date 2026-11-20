<?php

namespace App\Models;

use App\Scopes\UserScope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Colaborador invitado de una marca (T-19). Sin UserScope: lo consultan tanto el propietario (que gestiona) como el invitado
 * (que aprueba). Rol unico por ahora: `approver`.
 */
class BrandCollaborator extends Model
{
    public const ROLE_APPROVER = 'approver';

    public const MAX_PER_BRAND = 20;

    protected $guarded = ['id'];

    public function scopeApproverFor(Builder $query, int $userId): Builder
    {
        return $query->where('user_id', $userId)->where('role', self::ROLE_APPROVER);
    }

    public function guest(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function brand(): BelongsTo
    {
        return $this->belongsTo(Brand::class)->withoutGlobalScope(UserScope::class);
    }
}
