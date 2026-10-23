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
        // El job programado (sin id) solo reparte: un job POR LISTA, para que una lista lenta o rota no consuma el timeout de las demas.
        if ($this->autolistId === null) {
            Autolist::withoutUserScope()->where('active', true)->orderBy('id')
                ->each(fn (Autolist $list) => static::dispatch($list->id));

            return;
        }

        // Job sin usuario autenticado: opta explicitamente por salir del UserScope.
        $list = Autolist::withoutUserScope()->where('active', true)->find($this->autolistId);
        if (! $list) {
            return;
        }

        try {
            $service->generate($list);
        } catch (Throwable $e) {
            Log::error('autolist.generate_failed', ['autolist_id' => $list->id, 'exception' => $e::class]);
        }
    }
}
