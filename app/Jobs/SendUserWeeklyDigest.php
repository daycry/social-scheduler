<?php

namespace App\Jobs;

use App\Mail\WeeklyDigest as WeeklyDigestMail;
use App\Models\User;
use App\Notifications\WeeklyDigestReady;
use App\Services\WeeklyDigest;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\URL;
use Throwable;

/**
 * Resumen semanal (T-86) de UN usuario y UNA semana (`$week`, el lunes local). Idempotente: `WeeklyDigest::claim()` reserva la semana con un
 * UPDATE condicionado, asi un job duplicado o simultaneo no repite el envio. La semana reclamada no se pierde si el envio no llega a hacerse:
 * un error al prepararlo la devuelve en `handle()` y un timeout o kill del worker la devuelve en `failed()` (solo si aun no hay aviso de esa
 * semana: si el aviso ya se entrego, no se repite). El aviso en la app se entrega siempre (y por push, lo cuelga `QueuePushNotification`); el
 * correo solo con `notify_by_mail`, y un fallo suyo se registra sin devolver la semana (duplicaria el aviso). Cola `reports`, sin reintentos.
 */
class SendUserWeeklyDigest implements ShouldQueue
{
    use InteractsWithQueue, Queueable;

    public int $timeout = 100;

    public int $tries = 1;

    public function __construct(public readonly int $userId, public readonly string $week)
    {
        $this->onQueue((string) config('reports.queue', 'reports'));
    }

    public function handle(WeeklyDigest $digest): void
    {
        $user = User::query()->find($this->userId);
        $zone = $user === null ? null : $digest->zoneFor($user);

        if ($user === null || $zone === null || ! $digest->claim($user, $this->week)) {
            return;
        }

        try {
            $data = $digest->build($user, $zone, now()->toImmutable());
            $user->notify(new WeeklyDigestReady($data));
        } catch (Throwable $e) {
            // Si nada llego al usuario se devuelve la semana para que la siguiente pasada lo intente de nuevo; si el aviso ya se guardo
            // (p. ej. fallo un listener despues), liberarla lo repetiria.
            if (! $digest->delivered($user, $this->week)) {
                $digest->release($user, $this->week);
            }
            Log::error('weekly_digest.failed', ['user_id' => $user->id, 'exception' => $e::class]);

            return;
        }

        if (! $user->notify_by_mail) {
            return;
        }

        try {
            Mail::to($user)->send(new WeeklyDigestMail(
                $data,
                URL::signedRoute('digest.unsubscribe', ['user' => $user->id]),
                route('profile.notifications'),
                route('dashboard'),
            ));
        } catch (Throwable $e) {
            Log::error('weekly_digest.mail_failed', ['user_id' => $user->id, 'exception' => $e::class]);
        }
    }

    /** Timeout, kill o error inesperado: si el aviso de la semana no llego a entregarse, la semana se libera. */
    public function failed(Throwable $e): void
    {
        $user = User::query()->find($this->userId);
        $digest = app(WeeklyDigest::class);

        if ($user !== null && ! $digest->delivered($user, $this->week)) {
            $digest->release($user, $this->week);
        }
        Log::error('weekly_digest.job_failed', ['user_id' => $this->userId, 'exception' => $e::class]);
    }
}
