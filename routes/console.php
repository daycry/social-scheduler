<?php

use App\Jobs\DispatchDuePosts;
use App\Jobs\RefreshTokens;
use App\Support\HealthCheck;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Latido del scheduler: lo comprueba `app:health` / `/health` para detectar que el cron esta caido.
Schedule::call(fn () => Cache::put(HealthCheck::HEARTBEAT_KEY, now()->timestamp, 600))
    ->everyMinute()
    ->name('health-heartbeat');

// Elimina jobs fallidos antiguos (el historial util vive en publish_attempts, T-19).
Schedule::command('queue:prune-failed --hours=720')->daily();

// Renueva tokens de redes sociales antes de caducar; si falla marca la cuenta y avisa (T-16).
Schedule::job(new RefreshTokens)->everyThirtyMinutes()->name('refresh-social-tokens')->withoutOverlapping();

// Publica lo vencido y recupera lo colgado (T-20). En linea (no en cola) para que no espere a un worker ocupado;
// withoutOverlapping evita pasadas solapadas si una tarda mas de un minuto.
Schedule::call(fn () => app(DispatchDuePosts::class)())->everyMinute()->name('dispatch-due-posts')->withoutOverlapping(5);
