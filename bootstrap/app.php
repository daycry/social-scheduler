<?php

use App\Http\Controllers\HealthController;
use App\Http\Middleware\HandleInertiaRequests;
use App\Http\Middleware\McpGuard;
use App\Http\Middleware\TrustProxiesFromConfig;
use App\Support\CrossUserAccessLogger;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Middleware\AddLinkHeadersForPreloadedAssets;
use Illuminate\Http\Middleware\TrustProxies;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
        then: function (): void {
            // /health fuera del grupo `web` (sin sesion en BD) y con rate limit.
            Route::get('/health', HealthController::class)->middleware('throttle:30,1')->name('health');
        },
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->web(append: [
            HandleInertiaRequests::class,
            AddLinkHeadersForPreloadedAssets::class,
        ]);

        // Detras de un proxy TLS (ngrok) route() generaria http:// y el redirect_uri de OAuth no
        // coincidiria. Los proxies salen de config('app.trusted_proxies') (TRUSTED_PROXIES) en cada
        // peticion; vacio = no se confia en ninguno (lo correcto con Nginx + PHP-FPM directo en Hestia).
        $middleware->replace(TrustProxies::class, TrustProxiesFromConfig::class);
        // Solo se aceptan peticiones dirigidas al host de APP_URL (y subdominios): un Host o
        // X-Forwarded-Host falso no puede envenenar URLs absolutas (p. ej. el enlace de reset).
        $middleware->trustHosts();
        // /mcp: Origin + limite por IP + log de 401, antes de la autenticacion (ver McpGuard).
        $middleware->append(McpGuard::class);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        // Acceso cruzado entre usuarios: el UserScope devuelve 404 en el binding antes
        // que la Policy, asi que se registra aqui sin cambiar la respuesta al cliente.
        $exceptions->render(function (NotFoundHttpException $e): void {
            CrossUserAccessLogger::log($e);
        });

        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*', 'mcp') || $request->expectsJson(),
        );
    })->create();
