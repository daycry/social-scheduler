<?php

use App\Http\Controllers\HealthController;
use App\Http\Middleware\HandleInertiaRequests;
use App\Support\CrossUserAccessLogger;
use App\Support\TrustedProxies;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Middleware\AddLinkHeadersForPreloadedAssets;
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

        // Detras de ngrok o del proxy de Hestia TLS termina fuera de PHP: sin proxies de confianza route()
        // genera http:// y el redirect_uri de OAuth no coincide con el registrado (https). Configurable:
        // TRUSTED_PROXIES='*' o 'ip1,ip2,cidr'. Vacio = no se confia en ninguno (desarrollo local).
        $middleware->trustProxies(at: TrustedProxies::parse(env('TRUSTED_PROXIES')));
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        // Acceso cruzado entre usuarios: el UserScope devuelve 404 en el binding antes
        // que la Policy, asi que se registra aqui sin cambiar la respuesta al cliente.
        $exceptions->render(function (NotFoundHttpException $e): void {
            CrossUserAccessLogger::log($e);
        });

        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );
    })->create();
