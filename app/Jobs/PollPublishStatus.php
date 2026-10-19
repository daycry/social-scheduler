<?php

namespace App\Jobs;

use App\Connectors\ConnectorRegistry;
use App\Connectors\Contracts\StatusPoller;
use App\Connectors\Exceptions\PermanentPublishException;
use App\Connectors\Exceptions\QuotaExceededException;
use App\Connectors\Exceptions\ReconnectRequiredException;
use App\Enums\PostStatus;
use App\Models\PostTarget;
use App\Models\SocialAccount;
use App\Services\PublishOutcomes;
use App\Services\PublishRequestFactory;
use App\Services\QuotaPolicy;
use App\Services\SocialAccountService;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Sondea el estado de un destino `procesando` (contenedor de IG, video de FB, publish_id de TikTok) hasta
 * que acabe: publicada, fallida, o `poll_max_minutes` sin terminar. Un error pasajero al sondear NUNCA
 * falla el destino: se vuelve a sondear en la siguiente pasada (el despachador recupera los sondeos vencidos).
 */
class PollPublishStatus implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $timeout = 110;

    public int $tries = 2;

    public int $uniqueFor = 120;

    public function __construct(public readonly int $targetId)
    {
        $this->onQueue((string) config('publishing.poll_queue', 'poll'));
    }

    public function uniqueId(): string
    {
        return (string) $this->targetId;
    }

    public function handle(ConnectorRegistry $connectors, PublishOutcomes $outcomes, PublishRequestFactory $requests): void
    {
        // Solo un sondeo a la vez por destino: relee con lock y sale si ya no esta `procesando`.
        $target = DB::transaction(fn () => PostTarget::withoutUserScope()->lockForUpdate()->find($this->targetId));

        if (! $target || $target->status !== PostStatus::Processing) {
            return;
        }

        $account = $target->social_account_id ? SocialAccount::withoutUserScope()->find($target->social_account_id) : null;
        $poller = $connectors->publisherFor($target->network);

        if (! $account || ! $poller instanceof StatusPoller) {
            $outcomes->failed($target, 'cuenta_no_disponible', 'No se puede consultar el estado: la cuenta ya no esta disponible.');

            return;
        }

        if ($account->status === SocialAccount::STATUS_DISCONNECTED) {
            $outcomes->failed($target, 'cuenta_no_disponible', 'La cuenta se desconecto antes de terminar de publicar.');

            return;
        }

        if (! $account->isActive()) {
            $this->waitForReconnection($outcomes, $target);

            return;
        }

        // Fin de una espera (cuenta reconectada o cupo liberado): el reloj del sondeo empieza de nuevo.
        if (in_array($target->error_code, ['reconexion', 'cuota'], true)) {
            $target = $outcomes->stillProcessing($target, null, ['started_at' => now(), 'error_code' => null, 'error_message' => null]);
        }

        if ($target->started_at && $target->started_at->lt(now()->subMinutes((int) config('publishing.poll_max_minutes', 30)))) {
            $outcomes->failed($target, 'timeout_procesado', 'La red no termino de procesar el contenido a tiempo.');

            return;
        }

        try {
            $result = $poller->poll($account, $requests->make($target));
        } catch (PermanentPublishException $e) {
            $outcomes->failed($target, $e->errorCode ?? 'rechazada', $e->getMessage());

            return;
        } catch (ReconnectRequiredException $e) {
            // No se pierde el contenido ya enviado: se marca la cuenta y el sondeo se retoma al reconectarla.
            app(SocialAccountService::class)->flagReconnection($account, $e->getMessage());
            $this->waitForReconnection($outcomes, $target, $e->getMessage());

            return;
        } catch (QuotaExceededException $e) {
            // `media_publish` sin cupo: el contenedor ya existe, se aplica la politica de cuota sin recrearlo.
            app(QuotaPolicy::class)->applyWhileProcessing($target, $e);

            return;
        } catch (Throwable $e) {
            Log::notice('publish.poll_error', ['target_id' => $target->id, 'exception' => $e::class]);
            $outcomes->stillProcessing($target);

            return;
        }

        match (true) {
            $result->isPublished() => $outcomes->published($target, $result),
            $result->isFailed() => $outcomes->failed($target, 'rechazada', $result->message ?? 'La red no pudo procesar el contenido.'),
            default => $outcomes->stillProcessing($target, $result),
        };
    }

    /** Cuenta pendiente de reconectar: sigue `procesando` con el reloj del sondeo reiniciado (no vence por tiempo). */
    private function waitForReconnection(PublishOutcomes $outcomes, PostTarget $target, string $reason = 'La cuenta necesita reconectarse; la publicacion se completara al reconectar.'): void
    {
        $outcomes->stillProcessing($target, null, [
            'started_at' => now(),
            'error_code' => 'reconexion',
            'error_message' => mb_substr($reason, 0, 500),
        ]);
    }
}
