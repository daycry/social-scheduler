<?php

namespace App\Support;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * Comprobaciones de salud compartidas por `php artisan app:health` y por el
 * endpoint externo `/health`. Cada check devuelve [ok, detalle].
 */
class HealthCheck
{
    public const HEARTBEAT_KEY = 'health:scheduler-heartbeat';

    /** Segundos tras los que se considera caido el cron del scheduler. */
    public const HEARTBEAT_MAX_AGE = 180;

    /** @return array{ok: bool, checks: array<string, array{ok: bool, detail: string}>} */
    public function run(): array
    {
        $checks = [
            'database' => $this->database(),
            'queue' => $this->queue(),
            'storage' => $this->storage(),
            'scheduler' => $this->scheduler(),
        ];

        return [
            'ok' => collect($checks)->every(fn ($c) => $c['ok']),
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
            return ['ok' => false, 'detail' => $e->getMessage()];
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

    /** @return array{ok: bool, detail: string} */
    public function queue(): array
    {
        try {
            $pending = DB::table(config('queue.connections.database.table', 'jobs'))->count();
            $failed = DB::table(config('queue.failed.table', 'failed_jobs'))->count();

            return ['ok' => $failed === 0, 'detail' => "pendientes=$pending fallidos=$failed colas=".implode(',', config('queue.names'))];
        } catch (Throwable $e) {
            return ['ok' => false, 'detail' => $e->getMessage()];
        }
    }

    /** @return array{ok: bool, detail: string} */
    public function storage(): array
    {
        $disk = config('filesystems.media');
        $path = 'health/'.bin2hex(random_bytes(6)).'.txt';

        try {
            $storage = Storage::disk($disk);
            $storage->put($path, 'ok');
            $read = $storage->get($path);
            $storage->delete($path);

            return ['ok' => $read === 'ok', 'detail' => "disco=$disk"];
        } catch (Throwable $e) {
            return ['ok' => false, 'detail' => "disco=$disk: ".$e->getMessage()];
        }
    }

    /** @return array{ok: bool, detail: string} */
    public function scheduler(): array
    {
        $beat = Cache::get(self::HEARTBEAT_KEY);

        if ($beat === null) {
            return ['ok' => false, 'detail' => 'sin latido: el cron de schedule:run no se ha ejecutado'];
        }

        $age = now()->timestamp - (int) $beat;

        return ['ok' => $age <= self::HEARTBEAT_MAX_AGE, 'detail' => "ultimo latido hace {$age}s"];
    }
}
