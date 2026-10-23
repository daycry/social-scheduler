<?php

namespace App\Scopes;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;
use Illuminate\Support\Facades\Auth;

/**
 * Restringe toda consulta al usuario autenticado. Sin usuario devuelve cero filas
 * (fallo seguro): los jobs y comandos deben optar de forma explicita por
 * withoutUserScope().
 */
class UserScope implements Scope
{
    public function apply(Builder $builder, Model $model): void
    {
        $userId = Auth::id();

        if ($userId === null) {
            $builder->whereRaw('1 = 0');

            return;
        }

        $builder->where($model->qualifyColumn('user_id'), $userId);
    }
}
