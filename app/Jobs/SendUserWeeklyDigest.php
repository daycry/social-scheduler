<?php

namespace App\Jobs;

use App\Mail\WeeklyDigest as WeeklyDigestMail;
use App\Models\User;
use App\Notifications\WeeklyDigestReady;
use App\Services\NotificationPreferences;
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
 * UPDATE condicionado. Un error al preparar el resumen libera la semana. Si la entrega incluye la app, el aviso guardado confirma la
 * entrega. La politica de canales se relee al terminar de preparar el resumen. Cola `reports`, sin reintentos.
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

        if ($user === null || $zone === null || ! NotificationPreferences::anyChannel($user, 'weekly_digest') || ! $digest->claim($user, $this->week)) {
            return;
        }

        try {
            $data = $digest->build($user, $zone, now()->toImmutable());
            $user->refresh();
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

        if (! NotificationPreferences::allows($user, 'weekly_digest', 'mail')) {
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

    /** Si el resumen iba a la app y aun no hay aviso, se puede volver a intentar la semana. */
    public function failed(Throwable $e): void
    {
        $user = User::query()->find($this->userId);
        $digest = app(WeeklyDigest::class);

        if ($user !== null && NotificationPreferences::allows($user, 'weekly_digest', 'database') && ! $digest->delivered($user, $this->week)) {
            $digest->release($user, $this->week);
        }
        Log::error('weekly_digest.job_failed', ['user_id' => $this->userId, 'exception' => $e::class]);
    }
}
