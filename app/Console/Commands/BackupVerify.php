<?php

namespace App\Console\Commands;

use App\Support\BackupStatus;
use Illuminate\Console\Command;

class BackupVerify extends Command
{
    protected $signature = 'app:backup-verify {--dir= : Carpeta de respaldos (por defecto BACKUP_DIR)} {--max-hours= : Antiguedad maxima en horas (por defecto BACKUP_MAX_AGE_HOURS, 26)}';

    protected $description = 'Avisa si el ultimo respaldo de backup.sh falta, esta incompleto o tiene mas de 26 h';

    public function handle(BackupStatus $status): int
    {
        $max = $this->option('max-hours');
        if ($max !== null && (! is_numeric($max) || (int) $max < 1)) {
            $this->error("--max-hours debe ser un entero >= 1 (recibido: '$max').");

            return self::INVALID;
        }

        $dir = $this->option('dir');
        $result = $status->check($dir !== null && $dir !== '' ? (string) $dir : null, $max === null ? null : (int) $max);

        if ($result['ok']) {
            $this->info($result['detail']);

            return self::SUCCESS;
        }

        $this->error('AVISO: '.$result['detail']);

        return self::FAILURE;
    }
}
