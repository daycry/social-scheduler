<?php

namespace App\Models\Concerns;

use App\Models\User;
use App\Scopes\UserScope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Auth;
use LogicException;

/**
 * Aislamiento por usuario: global scope + asignacion automatica de user_id.
 * Toda entidad con columna user_id debe usar este trait (design.md, seccion 6).
 */
trait BelongsToUser
{
    public static function bootBelongsToUser(): void
    {
        static::addGlobalScope(new UserScope);

        static::creating(function ($model): void {
            if ($model->user_id !== null) {
                return;
            }

            $userId = Auth::id();

            if ($userId === null) {
                throw new LogicException(sprintf('No se puede crear %s sin usuario autenticado ni user_id explicito.', $model::class));
            }

            $model->user_id = $userId;
        });
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** Escape explicito para jobs/comandos que operan con un user_id conocido. */
    public static function withoutUserScope(): Builder
    {
        return static::withoutGlobalScope(UserScope::class);
    }
}
