<?php

namespace App\Console\Commands;

use App\Support\HealthCheck;
use Illuminate\Console\Command;

class AppHealth extends Command
{
    protected $signature = 'app:health {--json : Salida JSON} {--skip-scheduler : No exigir el latido del cron (p. ej. durante el deploy)} {--strict : Tambien falla (exit 1) con avisos}';

    protected $description = 'Comprueba BD, cola (antiguedad por cola), almacenamiento, scheduler, snapshots, binarios nativos y APP_DEBUG (exit 1 solo si algo esta caido; el resto es aviso, salvo --strict)';

    public function handle(HealthCheck $health): int
    {
        $result = $health->run((bool) $this->option('skip-scheduler'));

        if ($this->option('json')) {
            $this->line(json_encode($result, JSON_UNESCAPED_SLASHES));
        } else {
            foreach ($result['checks'] as $name => $check) {
                $this->line(sprintf('%-10s %s  %s', $name, ! $check['ok'] ? 'FAIL' : (empty($check['warn']) ? 'OK  ' : 'WARN'), $check['detail']));
            }
        }

        $failed = ! $result['ok'] || ($this->option('strict') && $result['status'] !== 'ok');

        return $failed ? self::FAILURE : self::SUCCESS;
    }
}
