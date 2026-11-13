<?php

use App\Jobs\CollectCompetitorPosts;
use App\Jobs\CollectCompetitorSnapshots;
use App\Jobs\CollectSnapshots;
use App\Jobs\DispatchDuePosts;
use App\Jobs\GenerateAutolistPosts;
use App\Jobs\GenerateSeriesOccurrences;
use App\Jobs\RefreshPostMetrics;
use App\Jobs\RefreshTokens;
use App\Jobs\SyncInboxComments;
use App\Support\HealthCheck;
use App\Support\Retention;
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

// Limpia subidas de media abandonadas (pendientes/invalidas) y aborta sus multiparts abiertos (T-17).
Schedule::command('app:media-prune')->hourly()->name('media-prune')->withoutOverlapping();

// Bounded private Studio recovery; native work stays on its dedicated queue.
Schedule::command('studio:recover --limit=25')->everyMinute()->name('studio-av-recovery')->withoutOverlapping(2);

// Renueva tokens de redes sociales antes de caducar; si falla marca la cuenta y avisa (T-16).
Schedule::job(new RefreshTokens)->everyThirtyMinutes()->name('refresh-social-tokens')->withoutOverlapping();

// Publica lo vencido y recupera lo colgado (T-20). En linea (no en cola) para que no espere a un worker ocupado;
// withoutOverlapping evita pasadas solapadas si una tarda mas de un minuto.
Schedule::call(fn () => app(DispatchDuePosts::class)())->everyMinute()->name('dispatch-due-posts')->withoutOverlapping(5);

// Snapshot diario de metricas por cuenta (T-31, CA-09): ayer ya esta cerrado. Cola `metrics`.
Schedule::job(new CollectSnapshots)->dailyAt((string) config('social.analytics.daily_at', '03:30'))->name('collect-snapshots')->withoutOverlapping();

// Metricas de publicaciones recientes cada 6 h durante las primeras 72 h (T-36).
Schedule::call(fn () => RefreshPostMetrics::dispatchDue())->cron('15 */6 * * *')->name('refresh-post-metrics')->withoutOverlapping(30);

// Informes (M-9): expira los atascados y purga ficheros/filas pasada la retencion (reports.retention_days).
Schedule::command('reports:prune')->dailyAt('04:10')->name('reports-prune')->withoutOverlapping();

// Informes programados (T-21): cada hora encola los envios por email (semanal/mensual) cuya fecha ya paso. Cola `reports`.
Schedule::command('reports:send-scheduled')->hourly()->name('reports-send-scheduled')->withoutOverlapping();

// Papelera (PA-06): elimina las publicaciones con mas de 30 dias en la papelera.
Schedule::command('posts:purge-trash')->dailyAt('04:30')->name('posts-purge-trash')->withoutOverlapping();

// Competidores de Instagram (PA-08): snapshot diario de seguidores y publicaciones (Business Discovery). Cola `metrics`.
Schedule::job(new CollectCompetitorSnapshots)->dailyAt((string) config('social.competitors.daily_at', '13:00'))->name('collect-competitor-snapshots')->withoutOverlapping();
Schedule::job(new CollectCompetitorPosts)->dailyAt((string) config('social.competitors.posts_at', '13:30'))->name('collect-competitor-posts')->withoutOverlapping();

// Caducidad de conexiones (T-23): avisa N dias antes (Meta 90 dias, LinkedIn sin renovacion); una vez por umbral y fecha.
Schedule::command('connections:notify-expiry')->dailyAt((string) config('social.expiry_notice_at', '09:00'))->name('notify-connection-expiry')->withoutOverlapping();

// Autolistas (PA-09): genera las publicaciones programadas de los proximos dias (idempotente). Cola `poll`.
Schedule::job(new GenerateAutolistPosts)->dailyAt((string) config('social.autolists.daily_at', '02:00'))->name('generate-autolist-posts')->withoutOverlapping();

// Inbox de comentarios (PA-10): sincroniza los comentarios de las publicaciones propias de IG/FB. Cola `poll`.
Schedule::job(new SyncInboxComments)->cron('*/'.max(5, (int) config('social.inbox.sync_minutes', 15)).' * * * *')->name('sync-inbox-comments')->withoutOverlapping();

// Series recurrentes (T-19): materializa las ocurrencias de los proximos dias (idempotente por serie y fecha) y limpia claves de idempotencia.
Schedule::call(fn () => app(GenerateSeriesOccurrences::class)())->hourly()->name('generate-series-occurrences')->withoutOverlapping(10);

// Servidor OAuth del MCP (CA-31): clientes sin token vivo en 30 dias y codigos caducados.
Schedule::command('mcp:oauth-prune')->dailyAt('04:40')->name('mcp-oauth-prune')->withoutOverlapping();

// Retencion (A-08, config/retention.php): poda por lotes las tablas con MassPrunable (auditoria, intentos, trabajos del estudio y Canva,
// revisiones del estudio) y las notificaciones leidas antiguas.
Schedule::command('model:prune')->dailyAt('04:50')->name('model-prune')->withoutOverlapping();
Schedule::call(fn () => Retention::pruneNotifications())->dailyAt('04:55')->name('notifications-prune')->withoutOverlapping();

// Sonda de colas (A-09): un job de prueba por cola; si ningun worker lo coge, `app:health` ve la cola atrasada.
Schedule::command('app:queue-probe')->everyFifteenMinutes()->name('queue-probe')->withoutOverlapping();
