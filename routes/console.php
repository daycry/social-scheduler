<?php

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
