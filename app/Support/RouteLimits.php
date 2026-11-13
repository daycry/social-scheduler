<?php

namespace App\Support;

use Illuminate\Support\Facades\RateLimiter;

/** Utilidades de los limites de ritmo por ruta declarados en `config/rate_limits.php` (un contador por usuario y nombre). */
final class RouteLimits
{
    /** Vacia los contadores de TODAS las rutas de un usuario (el E2E aislado reutiliza el mismo usuario entre specs). */
    public static function forget(int|string $userId): void
    {
        foreach (array_keys((array) config('rate_limits.limits')) as $name) {
            // La clave que usa el middleware `throttle` con un limitador con nombre: md5(nombre + clave del limite).
            RateLimiter::clear(md5($name.$name.':'.$userId));
        }
    }
}
