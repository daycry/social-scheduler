<?php

namespace App\Console\Commands;

use App\Services\MediaUploadService;
use Illuminate\Console\Command;

/** Limpia subidas de media abandonadas (pendientes/invalidas antiguas) y aborta sus multiparts abiertos. */
class PruneMedia extends Command
{
    protected $signature = 'app:media-prune {--hours= : Antiguedad minima en horas (por defecto media.pending_ttl_hours)}';

    protected $description = 'Elimina assets de media pendientes o invalidos abandonados y cancela sus subidas multipart';

    public function handle(MediaUploadService $uploads): int
    {
        $hours = (int) ($this->option('hours') ?: config('media.pending_ttl_hours', 24));
        $n = $uploads->pruneAbandoned($hours);

        $this->info("Subidas abandonadas eliminadas: $n");

        return self::SUCCESS;
    }
}
