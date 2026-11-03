<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Laravel\Sanctum\PersonalAccessToken;
use Symfony\Component\HttpFoundation\Response;

class RequireApiAbility
{
    public function handle(Request $request, Closure $next, string $ability): Response
    {
        abort_unless($request->bearerToken() && $request->user()?->currentAccessToken() instanceof PersonalAccessToken, 401);
        abort_unless($request->user()->tokenCan($ability), 403, 'Falta el permiso '.$ability.'.');

        return $next($request);
    }
}
