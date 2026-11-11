<?php

namespace App\Support;

use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * Comprobaciones de salud compartidas por `php artisan app:health` y por el
 * endpoint externo `/health`. Cada check devuelve [ok, detalle] (y `warn` si solo avisa).
 */
class HealthCheck
{
    public const HEARTBEAT_KEY = 'health:scheduler-heartbeat';

    /** Segundos tras los que se considera caido el cron del scheduler. */
    public const HEARTBEAT_MAX_AGE = 180;

    /** Ventana (horas) en la que un job fallido cuenta como aviso; los mas antiguos no degradan el estado. */
    public const FAILED_WINDOW_HOURS = 24;

    /**
     * `ok` = false solo si algo esta caido. Un job fallido reciente deja `status` en
     * `degraded` (informado en el JSON) pero no tumba el endpoint ni el deploy.
     *
     * @return array{ok: bool, status: string, checks: array<string, array{ok: bool, detail: string, warn?: bool}>}
     */
    public function run(bool $skipScheduler = false, bool $http = false): array
    {
        $checks = [
            'database' => $this->database(),
            'queue' => $this->queue(),
            'storage' => $this->storage(),
        ];

        if (! $skipScheduler) {
            $checks['scheduler'] = $this->scheduler();
            $checks['snapshots'] = $this->snapshots();
        }

        $checks['config'] = $this->debug();

        if (config('health.native', true)) {
            $checks['native'] = $this->nativeCached($http);
        }

        $down = collect($checks)->contains(fn ($c) => ! $c['ok']);
        $degraded = collect($checks)->contains(fn ($c) => ! empty($c['warn']));

        return [
            'ok' => ! $down,
            'status' => $down ? 'down' : ($degraded ? 'degraded' : 'ok'),
            'checks' => $checks,
        ];
    }

    /** @return array{ok: bool, detail: string} */
    public function database(): array
    {
        try {
            $driver = DB::connection()->getDriverName();
            $sql = $driver === 'sqlite' ? 'select sqlite_version() as v' : 'select version() as v';
            $version = DB::selectOne($sql)->v ?? 'desconocida';

            return ['ok' => true, 'detail' => "$driver $version".($this->supportsSkipLocked($driver, (string) $version) ? ' (SKIP LOCKED)' : ' (sin SKIP LOCKED: un solo worker)')];
        } catch (Throwable $e) {
            return $this->failure('database', $e);
        }
    }

    /** MariaDB >= 10.6 o MySQL >= 8.0 admiten FOR UPDATE SKIP LOCKED. */
    public function supportsSkipLocked(string $driver, string $version): bool
    {
        if (! preg_match('/(\d+)\.(\d+)/', $version, $m)) {
            return false;
        }

        $major = (int) $m[1];
        $minor = (int) $m[2];

        if (stripos($version, 'mariadb') !== false) {
            return $major > 10 || ($major === 10 && $minor >= 6);
        }

        return $driver === 'mysql' && $major >= 8;
    }

    /** @return array{ok: bool, detail: string, warn?: bool} */
    public function queue(): array
    {
        try {
            $pending = DB::table(config('queue.connections.database.table', 'jobs'))->count();
            $failedTotal = DB::table(config('queue.failed.table', 'failed_jobs'))->count();
            $failedRecent = DB::table(config('queue.failed.table', 'failed_jobs'))
                ->where('failed_at', '>=', now()->subHours(self::FAILED_WINDOW_HOURS))
                ->count();

            $detail = sprintf(
                'pendientes=%d fallidos_%dh=%d fallidos_total=%d colas=%s',
                $pending, self::FAILED_WINDOW_HOURS, $failedRecent, $failedTotal, implode(',', config('queue.names')),
            );

            $late = $this->lateQueues();
            if ($late !== []) {
                $detail .= ' atrasadas='.implode(',', array_map(fn ($age, $queue) => "$queue:{$age}s", $late, array_keys($late)));
            }

            return $failedRecent > 0 || $late !== []
                ? ['ok' => true, 'warn' => true, 'detail' => "AVISO: $detail"]
                : ['ok' => true, 'detail' => $detail];
        } catch (Throwable $e) {
            return $this->failure('queue', $e);
        }
    }

    /**
     * Antigüedad (s) del job pendiente más viejo de cada cola que supera su umbral (`health.queue_max_age`): señal de que
     * no hay worker escuchando esa cola o va por detrás. Solo cuenta jobs ya disponibles y sin reservar.
     *
     * @return array<string, int> cola => segundos de espera
     */
    public function lateQueues(): array
    {
        $now = now()->timestamp;
        $rows = DB::table(config('queue.connections.database.table', 'jobs'))
            ->whereNull('reserved_at')->where('available_at', '<=', $now)
            ->groupBy('queue')->selectRaw('queue, min(available_at) as oldest')->get();

        $limits = (array) config('health.queue_max_age', []);
        $late = [];
        foreach ($rows as $row) {
            $age = $now - (int) $row->oldest;
            $limit = (int) ($limits[$row->queue] ?? $limits['default'] ?? 600);
            if ($limit > 0 && $age > $limit) {
                $late[(string) $row->queue] = $age;
            }
        }
        ksort($late);

        return $late;
    }

    /**
     * `CollectSnapshots` es diario: sin ningún snapshot nuevo en `health.snapshots_max_age_hours` (y con cuentas conectadas)
     * las métricas se están quedando viejas.
     *
     * @return array{ok: bool, detail: string, warn?: bool}
     */
    public function snapshots(): array
    {
        try {
            // Mismas cuentas que recoge `CollectSnapshots` (activas) y con margen para la recien conectada: hasta la proxima
            // pasada diaria (24 h) mas el umbral.
            $limit = (int) config('health.snapshots_max_age_hours', 36);
            $accounts = DB::table('social_accounts')->where('status', 'activa')->where('created_at', '<=', now()->subHours(24 + $limit));
            if (! $accounts->exists()) {
                return ['ok' => true, 'detail' => 'sin cuentas activas con antiguedad suficiente'];
            }
            $last = DB::table('account_snapshots')->max('collected_at');

            if ($last === null) {
                return ['ok' => true, 'warn' => true, 'detail' => 'AVISO: aun no hay snapshots de metricas'];
            }
            $hours = (int) floor(Carbon::parse($last)->diffInHours(now(), true));

            return $limit > 0 && $hours > $limit
                ? ['ok' => true, 'warn' => true, 'detail' => "AVISO: ultimo snapshot hace {$hours}h (limite {$limit}h)"]
                : ['ok' => true, 'detail' => "ultimo snapshot hace {$hours}h"];
        } catch (Throwable $e) {
            return $this->failure('snapshots', $e);
        }
    }

    /**
     * Binarios nativos que usan media y estudio. Faltar uno solo avisa (WARN): la app sigue sirviendo, pero esa función fallará.
     *
     * @return array{ok: bool, detail: string, warn?: bool}
     */
    public function native(?bool $console = null): array
    {
        $console ??= true;
        $missing = [];
        $found = [];
        $binaries = ['ffmpeg' => [config('media.ffmpeg', 'ffmpeg'), '-version'], 'ffprobe' => [config('media.ffprobe', 'ffprobe'), '-version'], 'python' => [config('studio.python', 'python3'), '--version']];
        foreach ($binaries as $name => [$binary, $flag]) {
            if ($this->runs((string) $binary, $flag)) {
                $found[] = $name;
            } else {
                $missing[] = $name;
            }
        }
        $chromium = (string) config('studio.chromium_path', '');
        if ($chromium !== '' ? is_file($chromium) : $this->playwrightChromium()) {
            $found[] = 'chromium';
        } elseif ($chromium === '' && ! $console) {
            // Bajo PHP-FPM (clear_env) no hay HOME ni PLAYWRIGHT_BROWSERS_PATH: no se puede saber donde busca el renderizador
            // (que corre en el worker). Sin STUDIO_CHROMIUM_PATH la comprobacion queda para `app:health` por CLI.
        } else {
            $missing[] = 'chromium';
        }
        if (is_file((string) config('studio.images.model_path'))) {
            $found[] = 'modelo';
        } else {
            $missing[] = 'modelo';
        }

        return $missing === []
            ? ['ok' => true, 'detail' => 'ok: '.implode(',', $found)]
            : ['ok' => true, 'warn' => true, 'detail' => 'AVISO: faltan '.implode(',', $missing)];
    }

    /** HTTP: el resultado se cachea unos minutos para que cada sondeo de /health no lance procesos; la CLI siempre comprueba y lo refresca. */
    private function nativeCached(bool $http): array
    {
        $ttl = max(0, (int) config('health.native_cache_seconds', 300));
        if (! $http || $ttl === 0) {
            $result = $this->native(! $http);
            if ($ttl > 0) {
                Cache::put('health:native', $result, $ttl);
            }

            return $result;
        }

        return Cache::remember('health:native', $ttl, fn () => $this->native(false));
    }

    private function runs(string $binary, string $flag): bool
    {
        try {
            return Process::timeout(5)->run([$binary, $flag])->successful();
        } catch (Throwable) {
            return false;
        }
    }

    /** Chromium de Playwright en su carpeta de navegadores (PLAYWRIGHT_BROWSERS_PATH o la de por defecto del usuario). */
    private function playwrightChromium(): bool
    {
        $home = (string) (getenv('HOME') ?: getenv('USERPROFILE') ?: '');
        $roots = array_filter([(string) getenv('PLAYWRIGHT_BROWSERS_PATH'), $home !== '' ? $home.'/.cache/ms-playwright' : '', $home !== '' ? $home.'/AppData/Local/ms-playwright' : '']);
        foreach ($roots as $root) {
            if ((glob($root.'/chromium-*') ?: []) !== []) {
                return true;
            }
        }

        return false;
    }

    /** @return array{ok: bool, detail: string, warn?: bool} */
    public function debug(): array
    {
        return config('app.debug') && app()->environment('production')
            ? ['ok' => true, 'warn' => true, 'detail' => 'AVISO: APP_DEBUG=true en produccion (expone trazas y variables)']
            : ['ok' => true, 'detail' => 'debug='.(config('app.debug') ? 'true' : 'false')];
    }

    /**
     * Copia del disco con un limite total corto: el disco real tolera transferencias
     * largas (videos), pero una sonda colgada no debe retener un worker PHP-FPM.
     */
    private function probeDisk(string $disk): Filesystem
    {
        $config = (array) config("filesystems.disks.$disk");

        if (($config['driver'] ?? null) !== 's3') {
            return Storage::disk($disk);
        }

        $config['http'] = array_merge((array) ($config['http'] ?? []), [
            'timeout' => (float) config('filesystems.health_timeout', 5),
        ]);

        return Storage::build($config);
    }

    /** @return array{ok: bool, detail: string} */
    public function storage(): array
    {
        $disk = (string) config('filesystems.media');
        $path = 'health/'.bin2hex(random_bytes(6)).'.txt';

        try {
            $storage = $this->probeDisk($disk);

            if ($storage->put($path, 'ok') === false) {
                return ['ok' => false, 'detail' => "disco=$disk: no se pudo escribir"];
            }

            $read = $storage->get($path);
            $storage->delete($path);

            return $read === 'ok'
                ? ['ok' => true, 'detail' => "disco=$disk"]
                : ['ok' => false, 'detail' => "disco=$disk: lectura inconsistente"];
        } catch (Throwable $e) {
            return $this->failure("storage disco=$disk", $e);
        }
    }

    /** @return array{ok: bool, detail: string} */
    public function scheduler(): array
    {
        try {
            $beat = Cache::get(self::HEARTBEAT_KEY);
        } catch (Throwable $e) {
            return $this->failure('scheduler', $e);
        }

        if ($beat === null) {
            return ['ok' => false, 'detail' => 'sin latido: el cron de schedule:run no se ha ejecutado'];
        }

        $age = now()->timestamp - (int) $beat;

        return ['ok' => $age <= self::HEARTBEAT_MAX_AGE, 'detail' => "ultimo latido hace {$age}s"];
    }

    /**
     * El detalle del fallo va al log interno; al exterior solo un mensaje generico
     * (no filtrar hosts, rutas ni credenciales de la excepcion).
     *
     * @return array{ok: bool, detail: string}
     */
    private function failure(string $check, Throwable $e): array
    {
        Log::error('health.check_failed', ['check' => $check, 'exception' => $e::class, 'message' => $e->getMessage()]);

        return ['ok' => false, 'detail' => 'error (ver logs de la aplicacion)'];
    }
}
