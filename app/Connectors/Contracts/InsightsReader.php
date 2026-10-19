<?php

namespace App\Connectors\Contracts;

use App\Models\SocialAccount;
use Carbon\CarbonInterface;

/**
 * Capacidad de leer metricas de la cuenta (fase 7).
 */
interface InsightsReader
{
    /** @return array<string, int|float|null> Metricas normalizadas del dia indicado. */
    public function accountInsights(SocialAccount $account, CarbonInterface $day): array;
}
