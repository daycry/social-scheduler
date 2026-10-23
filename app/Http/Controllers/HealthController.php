<?php

namespace App\Http\Controllers;

use App\Support\HealthCheck;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Endpoint para el healthcheck externo (UptimeRobot, Healthchecks.io, cron remoto).
 * Fuera del grupo `web` (sin sesion) y con rate limit. Requiere el token en la cabecera X-Health-Token; sin HEALTHCHECK_TOKEN configurado esta cerrado.
 */
class HealthController extends Controller
{
    public function __invoke(Request $request, HealthCheck $health): JsonResponse
    {
        $token = (string) config('app.healthcheck_token');

        if ($token === '' || ! hash_equals($token, (string) $request->header('X-Health-Token'))) {
            abort(404);
        }

        $result = $health->run();

        return response()->json($result, $result['ok'] ? 200 : 503);
    }
}
