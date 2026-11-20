<?php

namespace App\Jobs;

use App\Exceptions\OwnershipException;
use App\Models\Goal;
use App\Models\User;
use App\Services\GoalService;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

/** Evaluación local por lotes; no recoge métricas ni consulta a proveedores. */
final class EvaluateGoals implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $backoff = 120;

    public int $uniqueFor = 3600;

    public function __construct()
    {
        $this->onQueue((string) config('goals.queue', 'metrics'));
    }

    public function handle(GoalService $service): void
    {
        $failures = 0;
        Goal::withoutUserScope()->where('enabled', true)->with('user')->chunkById(100, function ($goals) use ($service, &$failures): void {
            foreach ($goals as $goal) {
                if (! $goal->user instanceof User) {
                    continue;
                }
                try {
                    $service->evaluate($goal->user, $goal);
                } catch (OwnershipException) {
                    Log::warning('goals.inconsistent_ownership', ['goal_id' => $goal->id]);
                } catch (Throwable $error) {
                    $failures++;
                    Log::warning('goals.evaluation_failed', ['goal_id' => $goal->id, 'exception' => $error::class]);
                }
            }
        });
        if ($failures > 0) {
            throw new RuntimeException('La evaluación terminó con errores en '.$failures.' objetivo'.($failures === 1 ? '' : 's').'.');
        }
    }
}
