<?php

namespace App\Jobs;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Cache;

/**
 * Job de prueba: demuestra que un worker procesa cada cola. Deja una marca en cache.
 */
class QueueProbeJob implements ShouldQueue
{
    use Queueable;

    public function __construct(public readonly string $probeId) {}

    public static function cacheKey(string $probeId): string
    {
        return "queue-probe:$probeId";
    }

    public static function latestKey(string $queue): string
    {
        return 'queue-probe:last:'.hash('sha256', $queue);
    }

    public function handle(): void
    {
        $observed = [
            'queue' => $this->queue,
            'processed_at' => now()->toIso8601String(),
        ];
        Cache::put(self::cacheKey($this->probeId), $observed, 600);
        Cache::put(self::latestKey($this->queue ?? 'default'), $observed, 86400);
    }
}
