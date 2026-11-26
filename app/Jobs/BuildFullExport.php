<?php

namespace App\Jobs;

use App\Models\DataExport;
use App\Services\Export\FullExport;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\InteractsWithQueue;
use Throwable;

/**
 * Genera el ZIP de la exportación completa de un usuario (T-84). Sin reintentos: un fallo deja la exportación en error (y avisa) y el usuario
 * la vuelve a pedir. `$timeout` configurable (`exports.timeout`): manda sobre el `--timeout` del worker, que debe ser igual o mayor, y `retry_after` de
 * su conexión propia `exports` es siempre mayor que ambos (config/queue.php).
 */
class BuildFullExport implements ShouldQueue
{
    use InteractsWithQueue, Queueable;

    public int $tries = 1;

    public int $timeout;

    public function __construct(public readonly int $exportId)
    {
        $this->timeout = max(1, (int) config('exports.timeout'));
        $this->onConnection((string) config('exports.connection', 'exports'));
        $this->onQueue((string) config('exports.queue', 'exports'));
    }

    public function handle(FullExport $exports): void
    {
        // Job sin usuario autenticado: sale explícitamente del UserScope.
        $export = DataExport::withoutUserScope()->find($this->exportId);
        if ($export !== null) {
            $exports->run($export);
        }
    }

    /** Un timeout o un kill del worker no pasa por el `catch` de `run()`. */
    public function failed(Throwable $e): void
    {
        $export = DataExport::withoutUserScope()->find($this->exportId);
        if ($export !== null) {
            app(FullExport::class)->fail($export, FullExport::GENERIC_ERROR);
        }
    }
}
