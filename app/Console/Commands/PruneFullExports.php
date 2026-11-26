<?php

namespace App\Console\Commands;

use App\Models\DataExport;
use App\Services\Export\FullExport;
use Illuminate\Console\Command;

class PruneFullExports extends Command
{
    protected $signature = 'exports:prune';

    protected $description = 'Borra los ZIP de exportación caducados, los huérfanos y los temporales viejos, y marca en error las exportaciones atascadas (sin latido) o perdidas en la cola';

    public function handle(FullExport $exports): int
    {
        // En curso: sin latido en `stuck_minutes`. En cola: no late (espera su turno detrás de otras largas), así que solo se da por perdida pasado
        // `pending_minutes` desde que se pidió.
        $stuck = 0;
        $lost = function (DataExport $export) use ($exports, &$stuck): void {
            $exports->fail($export, FullExport::STUCK_ERROR);
            $stuck++;
        };
        DataExport::withoutUserScope()->where('status', DataExport::STATUS_PROCESSING)
            ->whereRaw('coalesce(heartbeat_at, updated_at) < ?', [now()->subMinutes((int) config('exports.stuck_minutes'))])->each($lost);
        DataExport::withoutUserScope()->where('status', DataExport::STATUS_PENDING)
            ->where('created_at', '<', now()->subMinutes((int) config('exports.pending_minutes')))->each($lost);

        $expired = 0;
        DataExport::withoutUserScope()->where('status', DataExport::STATUS_READY)->where('expires_at', '<', now())
            ->each(function (DataExport $export) use (&$expired): void {
                $export->deleteFile();
                $export->forceFill(['status' => DataExport::STATUS_EXPIRED, 'size' => null, 'file_count' => null])->save();
                $expired++;
            });

        $orphans = $this->sweepOrphanZips() + $this->sweepTemporaries();

        $this->info("Exportaciones atascadas marcadas: {$stuck}. Caducadas y borradas: {$expired}. Restos huérfanos borrados: {$orphans}.");

        return self::SUCCESS;
    }

    /** ZIP de `full-exports/` que ninguna fila lista reclama (fila borrada, fallida...). Los de una exportación en curso se respetan. */
    private function sweepOrphanZips(): int
    {
        $disk = DataExport::disk();
        $deleted = 0;
        foreach ($disk->allFiles('full-exports') as $file) {
            $row = preg_match('#^full-exports/(\d+)/(\d+)\.zip$#', $file, $m)
                ? DataExport::withoutUserScope()->where('user_id', (int) $m[1])->find((int) $m[2])
                : null;
            if ($row !== null && ($row->in_progress || ($row->status === DataExport::STATUS_READY && $row->path === $file))) {
                continue;
            }
            $disk->delete($file);
            $deleted++;
        }

        return $deleted;
    }

    /** Temporales de ejecuciones muertas (kill, reinicio): el job los toca constantemente, así que uno sin tocar en `stuck_minutes` es un resto. */
    private function sweepTemporaries(): int
    {
        $deleted = 0;
        foreach (glob(rtrim((string) config('exports.tmp_dir'), '/\\').DIRECTORY_SEPARATOR.'*.zip.part') ?: [] as $file) {
            if (filemtime($file) < now()->subMinutes((int) config('exports.stuck_minutes'))->getTimestamp() && @unlink($file)) {
                $deleted++;
            }
        }

        return $deleted;
    }
}
