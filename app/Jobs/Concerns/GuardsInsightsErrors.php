<?php

namespace App\Jobs\Concerns;

use App\Connectors\Exceptions\ConnectorException;
use App\Connectors\Exceptions\ReconnectRequiredException;
use App\Connectors\Exceptions\TransientPublishException;
use App\Models\SocialAccount;
use App\Services\SocialAccountService;
use Closure;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Clasificacion comun de fallos de los jobs de analitica (cola `metrics`):
 *  - credenciales rechazadas -> la cuenta pasa a `requiere_reconexion` (y se avisa una vez);
 *  - transitorios (red, 5xx, limite) -> el job se libera y reintenta tras `$backoff`;
 *  - cualquier otro fallo se registra sin datos sensibles y no tumba la cola (la siguiente pasada reintenta).
 * Una metrica no admitida NO es un fallo: ya llega como `null` ("no disponible").
 */
trait GuardsInsightsErrors
{
    /** @param Closure(): void $work */
    protected function guarded(SocialAccount $account, SocialAccountService $accounts, Closure $work): bool
    {
        try {
            $work();

            return true;
        } catch (ReconnectRequiredException $e) {
            $accounts->flagReconnection($account, $e->getMessage());
        } catch (TransientPublishException $e) {
            Log::warning('analytics.transient', ['account_id' => $account->id, 'network' => $account->network->value, 'code' => $e->errorCode]);

            $this->release($this->backoff);
        } catch (ConnectorException $e) {
            Log::warning('analytics.error', ['account_id' => $account->id, 'exception' => $e::class]);
        } catch (Throwable $e) {
            Log::error('analytics.unexpected', ['account_id' => $account->id, 'exception' => $e::class]);
        }

        return false;
    }
}
