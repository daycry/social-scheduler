<?php

namespace App\Support;

use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
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
    public function run(bool $skipScheduler = false): array
    {
        $checks = [
            'database' => $this->database(),
            'queue' => $this->queue(),
            'storage' => $this->storage(),
        ];

        if (! $skipScheduler) {
            $checks['scheduler'] = $this->scheduler();
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

            return $failedRecent > 0
                ? ['ok' => true, 'warn' => true, 'detail' => "AVISO: $detail"]
                : ['ok' => true, 'detail' => $detail];
        } catch (Throwable $e) {
            return $this->failure('queue', $e);
        }
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
