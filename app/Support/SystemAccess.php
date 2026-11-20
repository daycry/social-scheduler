<?php

namespace App\Support;

use App\Models\User;

/** Operadores de esta instalación: lista de servidor, nunca un campo editable del perfil. */
final class SystemAccess
{
    public static function allows(?User $user): bool
    {
        if ($user === null || ! $user->hasVerifiedEmail()) {
            return false;
        }

        $email = mb_strtolower(trim($user->email));
        foreach ((array) config('health.admin_emails', []) as $allowed) {
            if (is_string($allowed) && filter_var(trim($allowed), FILTER_VALIDATE_EMAIL) !== false
                && mb_strtolower(trim($allowed)) === $email) {
                return true;
            }
        }

        return false;
    }
}
