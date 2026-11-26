<?php

namespace App\Jobs;

use App\Models\User;
use App\Services\WeeklyDigest;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\InteractsWithQueue;

/**
 * Resumen semanal (T-86), parte programada: cada hora reparte un `SendUserWeeklyDigest` por usuario al que ya le toca (lunes desde las 08:00
 * en la zona de su marca principal, con el resumen activado y el de esta semana aun sin reclamar). No genera ni envia nada: asi un usuario
 * lento (la IA puede tardar 30 s) no retiene a los demas ni agota el `timeout` de un job que los recorre en serie. Cola `reports`.
 */
class SendWeeklyDigest implements ShouldQueue
{
    use InteractsWithQueue, Queueable;

    public int $timeout = 100;

    public int $tries = 1;

    public function __construct()
    {
        $this->onQueue((string) config('reports.queue', 'reports'));
    }

    public function handle(WeeklyDigest $digest): void
    {
        $now = now()->toImmutable();

        User::query()->where('notify_weekly_digest', true)->whereNotNull('email_verified_at')->eachById(function (User $user) use ($digest, $now) {
            $zone = $digest->zoneFor($user);
            $week = $zone === null ? null : $digest->dueWeek($zone, $now);

            if ($week !== null && ($user->weekly_digest_sent_for === null || $user->weekly_digest_sent_for->format('Y-m-d') < $week)) {
                SendUserWeeklyDigest::dispatch($user->id, $week);
            }
        });
    }
}
