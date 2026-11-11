<?php

namespace App\Jobs;

use App\Services\IdempotencyService;
use App\Services\SeriesService;

/**
 * Genera las ocurrencias de las series activas (T-19) y limpia las claves de idempotencia caducadas. Idempotente: la clave unica
 * (serie, fecha) hace que ejecutarlo dos veces no duplique nada. Se programa cada hora desde `routes/console.php`.
 */
class GenerateSeriesOccurrences
{
    public function __invoke(): int
    {
        app(IdempotencyService::class)->prune();

        return app(SeriesService::class)->generateAll();
    }
}
