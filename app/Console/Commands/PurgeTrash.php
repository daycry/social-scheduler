<?php

namespace App\Console\Commands;

use App\Services\TrashService;
use Illuminate\Console\Command;

/** Elimina definitivamente las publicaciones que llevan mas de N dias (30 por defecto) en la papelera (PA-06). */
class PurgeTrash extends Command
{
    protected $signature = 'posts:purge-trash {--days=30 : Dias en la papelera antes de eliminar}';

    protected $description = 'Elimina las publicaciones de la papelera con mas de 30 dias';

    public function handle(TrashService $trash): int
    {
        $days = max(1, (int) $this->option('days'));
        $count = $trash->purgeOlderThan($days);

        $this->info("Publicaciones eliminadas de la papelera: $count");

        return self::SUCCESS;
    }
}
