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

    public function handle(): void
    {
        Cache::put(self::cacheKey($this->probeId), [
            'queue' => $this->queue,
            'processed_at' => now()->toIso8601String(),
        ], 600);
    }
}
