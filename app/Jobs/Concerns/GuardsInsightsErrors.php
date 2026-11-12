<?php

namespace App\Jobs\Concerns;

use App\Connectors\Exceptions\ConnectorException;
use App\Connectors\Exceptions\QuotaExceededException;
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
 *  - cuota agotada (429, limites de la red) -> tambien se libera, con backoff exponencial por intento y respetando `Retry-After`/`retryAt`
 *    (tope de una hora): no es un fallo definitivo, asi el backfill y el refresco de publicaciones no se abandonan;
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
        } catch (QuotaExceededException $e) {
            $delay = $this->quotaDelay($e);
            Log::warning('analytics.quota', ['account_id' => $account->id, 'network' => $account->network->value, 'code' => $e->errorCode, 'delay' => $delay]);

            $this->release($delay);
        } catch (ConnectorException $e) {
            Log::warning('analytics.error', ['account_id' => $account->id, 'exception' => $e::class]);
        } catch (Throwable $e) {
            Log::error('analytics.unexpected', ['account_id' => $account->id, 'exception' => $e::class]);
        }

        return false;
    }

    /** Segundos hasta el reintento: `$backoff` x 2^(intento-1), al menos lo que pida la red (`retryAt`), con tope de una hora. */
    private function quotaDelay(QuotaExceededException $e): int
    {
        $attempts = max(1, (int) (method_exists($this, 'attempts') ? $this->attempts() : 1));
        $delay = (int) $this->backoff * (2 ** min($attempts - 1, 4));

        if ($e->retryAt !== null) {
            $delay = max($delay, (int) ceil(max(0, now()->diffInSeconds($e->retryAt, false))));
        }

        return min(3600, max(1, $delay));
    }
}
