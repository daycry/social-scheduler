<?php

namespace App\Console\Commands;

use App\Support\HealthCheck;
use Illuminate\Console\Command;

class AppHealth extends Command
{
    protected $signature = 'app:health {--json : Salida JSON} {--skip-scheduler : No exigir el latido del cron (p. ej. durante el deploy)}';

    protected $description = 'Comprueba BD, cola, almacenamiento y latido del scheduler (exit 1 si algo falla)';

    public function handle(HealthCheck $health): int
    {
        $result = $health->run();

        if ($this->option('skip-scheduler')) {
            unset($result['checks']['scheduler']);
            $result['ok'] = collect($result['checks'])->every(fn ($c) => $c['ok']);
        }

        if ($this->option('json')) {
            $this->line(json_encode($result, JSON_UNESCAPED_SLASHES));
        } else {
            foreach ($result['checks'] as $name => $check) {
                $this->line(sprintf('%-10s %s  %s', $name, $check['ok'] ? 'OK  ' : 'FAIL', $check['detail']));
            }
        }

        return $result['ok'] ? self::SUCCESS : self::FAILURE;
    }
}
