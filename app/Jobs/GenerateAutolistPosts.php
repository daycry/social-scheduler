<?php

namespace App\Jobs;

use App\Models\Autolist;
use App\Services\AutolistService;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Job diario de autolistas (PA-09): genera las publicaciones programadas de los proximos dias de cada lista activa.
 * Idempotente (franjas unicas): re-ejecutarlo no duplica nada. Un fallo en una lista no impide las demas.
 */
class GenerateAutolistPosts implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $timeout = 100;

    public int $tries = 1;

    public int $uniqueFor = 3600;

    /** @param int|null $autolistId solo esa lista; null = todas las activas */
    public function __construct(public readonly ?int $autolistId = null)
    {
        $this->onQueue((string) config('social.autolists.queue', 'poll'));
    }

    public function uniqueId(): string
    {
        return 'autolists:'.($this->autolistId ?? 'all');
    }

    public function handle(AutolistService $service): void
    {
        // Job sin usuario autenticado: opta explicitamente por salir del UserScope.
        Autolist::withoutUserScope()->where('active', true)
            ->when($this->autolistId, fn ($q) => $q->whereKey($this->autolistId))
            ->chunkById(100, function ($lists) use ($service): void {
                foreach ($lists as $list) {
                    try {
                        $service->generate($list);
                    } catch (Throwable $e) {
                        Log::error('autolist.generate_failed', ['autolist_id' => $list->id, 'exception' => $e::class]);
                    }
                }
            });
    }
}
