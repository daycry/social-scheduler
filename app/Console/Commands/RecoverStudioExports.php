<?php

namespace App\Console\Commands;

use App\Services\Studio\StudioAvRecovery;
use Illuminate\Console\Command;

class RecoverStudioExports extends Command
{
    protected $signature = 'studio:recover {--limit=25}';

    protected $description = 'Recover private audiovisual leases and orphan workspaces.';

    public function handle(StudioAvRecovery $recovery): int
    {
        $limit = (string) $this->option('limit');
        if (! ctype_digit($limit) || (int) $limit < 1 || (int) $limit > 100) {
            $this->error('El límite debe estar entre 1 y 100.');

            return self::INVALID;
        }
        $this->line(json_encode($recovery->run((int) $limit), JSON_THROW_ON_ERROR));

        return self::SUCCESS;
    }
}
