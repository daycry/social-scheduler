<?php

namespace App\Support;

use Carbon\CarbonImmutable;
use DateTimeZone;
use Exception;
use ValueError;

/** Convierte una fecha local al minuto solo si representa un único instante real. */
final class LocalDateTime
{
    public static function parseUnique(string $wall, string $timezone): ?CarbonImmutable
    {
        try {
            $naive = CarbonImmutable::createFromFormat('!Y-m-d\TH:i', $wall, 'UTC');
            $zone = new DateTimeZone($timezone);
        } catch (Exception|ValueError) {
            return null;
        }
        if (! $naive || $naive->format('Y-m-d\TH:i') !== $wall) {
            return null;
        }

        $offsets = [$zone->getOffset($naive)];
        foreach ($zone->getTransitions($naive->getTimestamp() - 86400, $naive->getTimestamp() + 86400) ?: [] as $transition) {
            $offsets[] = $transition['offset'];
        }
        $valid = [];
        foreach (array_unique($offsets) as $offset) {
            $at = $naive->subSeconds($offset)->setTimezone($zone);
            if ($at->format('Y-m-d\TH:i') === $wall) {
                $valid[] = $at;
            }
        }

        return count($valid) === 1 ? $valid[0] : null;
    }
}
