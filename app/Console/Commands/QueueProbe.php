<?php

namespace App\Console\Commands;

use App\Jobs\QueueProbeJob;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

class QueueProbe extends Command
{
    protected $signature = 'app:queue-probe {--wait=0 : Segundos a esperar a que un worker procese las pruebas}';

    protected $description = 'Encola un job de prueba en cada cola (publish, poll, metrics, reports)';

    public function handle(): int
    {
        $wait = $this->option('wait');

        if (! is_numeric($wait) || (int) $wait < 0) {
            $this->error("--wait debe ser un numero de segundos >= 0 (recibido: '$wait').");

            return self::INVALID;
        }

        $names = config('queue.names');

        if ($names === []) {
            $this->error('QUEUE_NAMES no define ninguna cola.');

            return self::FAILURE;
        }

        $probes = [];

        foreach ($names as $queue) {
            $id = (string) Str::uuid();
            QueueProbeJob::dispatch($id)->onQueue($queue);
            $probes[$queue] = $id;
            $this->line("encolado en '$queue' ($id)");
        }

        $wait = (int) $wait;

        if ($wait === 0) {
            return self::SUCCESS;
        }

        $deadline = now()->addSeconds($wait);
        $pending = $probes;

        while ($pending !== [] && now()->lt($deadline)) {
            foreach ($pending as $queue => $id) {
                if (Cache::has(QueueProbeJob::cacheKey($id))) {
                    $this->info("procesado '$queue'");
                    unset($pending[$queue]);
                }
            }
            if ($pending !== []) {
                sleep(1);
            }
        }

        foreach (array_keys($pending) as $queue) {
            $this->error("sin procesar '$queue': hay un worker escuchando esa cola?");
        }

        return $pending === [] ? self::SUCCESS : self::FAILURE;
    }
}
