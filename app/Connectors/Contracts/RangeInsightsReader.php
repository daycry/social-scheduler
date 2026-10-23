<?php

namespace App\Connectors\Contracts;

use App\Models\SocialAccount;
use Carbon\CarbonInterface;

/**
 * Lector que puede traer VARIOS dias de metricas de cuenta en pocas llamadas (historico inicial, T-36): YouTube con
 * `dimensions=day`, Facebook con `since/until` de <= 30 dias. Los que no pueden (Instagram: sus metricas `total_value`
 * se piden de un dia en un dia) devuelven 0 en `maxRangeDays` y se leen dia a dia.
 */
interface RangeInsightsReader extends InsightsReader
{
    /** Maximo de dias por consulta para esta cuenta; 0 o 1 = sin soporte de rango. */
    public function maxRangeDays(SocialAccount $account): int;

    /**
     * Metricas de cuenta por dia de [$from, $to] (inclusive, dias de la red). Una entrada por dia con el mapa completo
     * de claves (`null` donde la API no da dato); sin stocks (no existen para dias pasados).
     *
     * @return array<string, array<string, int|float|null>> [Y-m-d => metricas]
     */
    public function accountInsightsRange(SocialAccount $account, CarbonInterface $from, CarbonInterface $to): array;
}
