<?php

namespace App\Jobs;

use App\Connectors\ConnectorRegistry;
use App\Models\SocialAccount;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Despachador (rapido) del refresco de tokens: encola un `RefreshSocialAccount` por cada cuenta activa
 * que ya cae dentro del margen de su proveedor (config/social.php) o cuyo acceso ya caduco. Trabajar
 * por cuenta evita que una red lenta o un token que caduca agote el timeout del worker para el resto, y
 * que matar el proceso a mitad pierda un refresh token rotado de otra cuenta.
 *
 * `ShouldBeUnique` es lo que evita que dos pasadas se solapen en ejecucion (`withoutOverlapping()` del
 * scheduler solo protege el despacho, no el job ya encolado).
 */
class RefreshTokens implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $timeout = 60;

    public int $tries = 1;

    public int $uniqueFor = 1500;

    public function __construct()
    {
        $this->onQueue((string) config('social.refresh_queue', 'poll'));
    }

    public function handle(ConnectorRegistry $connectors): void
    {
        // Job sin usuario autenticado: opta explicitamente por salir del UserScope.
        // chunkById pagina por clave (no por OFFSET): no salta filas aunque el estado cambie.
        SocialAccount::withoutUserScope()
            ->where('status', SocialAccount::STATUS_ACTIVE)
            ->chunkById(200, function ($accounts) use ($connectors): void {
                foreach ($accounts as $account) {
                    if (RefreshSocialAccount::needsWork($account, $connectors)) {
                        RefreshSocialAccount::dispatch($account->id);
                    }
                }
            });
    }
}
