<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Support\Facades\RateLimiter;

/**
 * Limite por usuario compartido por web, API y MCP (T-19): vive en el servicio, no en la ruta, para que ningun canal lo esquive.
 */
class ServiceThrottle
{
    public static function hit(User $user, string $bucket, int $max = 10): void
    {
        $key = 'svc:'.$bucket.':'.$user->id;
        abort_if(RateLimiter::tooManyAttempts($key, $max), 429, 'Demasiadas peticiones. Espera un minuto.');
        RateLimiter::hit($key, 60);
    }
}
