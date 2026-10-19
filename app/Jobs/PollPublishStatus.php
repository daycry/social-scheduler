<?php

namespace App\Jobs;

use App\Connectors\ConnectorRegistry;
use App\Connectors\Contracts\StatusPoller;
use App\Connectors\Exceptions\PermanentPublishException;
use App\Connectors\Exceptions\ReconnectRequiredException;
use App\Enums\PostStatus;
use App\Models\PostTarget;
use App\Models\SocialAccount;
use App\Services\PublishOutcomes;
use App\Services\PublishRequestFactory;
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
            // No se pierde el contenido ya enviado: se reintenta el sondeo cuando la cuenta se reconecte.
            $outcomes->stillProcessing($target);

            return;
        } catch (Throwable $e) {
            Log::notice('publish.poll_error', ['target_id' => $target->id, 'exception' => $e::class]);
            $outcomes->stillProcessing($target);

            return;
        }

        match (true) {
            $result->isPublished() => $outcomes->published($target, $result),
            $result->isFailed() => $outcomes->failed($target, 'rechazada', $result->message ?? 'La red no pudo procesar el contenido.'),
            default => $outcomes->stillProcessing($target),
        };
    }
}
