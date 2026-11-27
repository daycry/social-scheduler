<?php

namespace App\Support;

use Carbon\CarbonImmutable;
use InvalidArgumentException;

/**
 * Fechas señaladas de marketing (T-90): calcula, para un año o un rango, las fechas de `config/marketing_dates.php` (fijas y
 * móviles con regla). Sin base de datos ni servicios externos; la lista editable vive en esa config.
 */
final class MarketingDates
{
    /**
     * Las fechas del año, ordenadas por día (`Y-m-d`). Las que ese año no existen (29 de febrero en un año no bisiesto, un quinto lunes
     * que el mes no tiene) se omiten; con `offset`, la fecha puede caer en el año contiguo.
     *
     * @return list<array{key: string, name: string, date: string}>
     */
    public static function forYear(int $year): array
    {
        $dates = [];
        foreach ((array) config('marketing_dates.dates') as $entry) {
            $day = self::resolve($entry, $year);
            if ($day !== null) {
                $dates[] = ['key' => (string) $entry['key'], 'name' => (string) $entry['name'], 'date' => $day->format('Y-m-d')];
            }
        }
        usort($dates, fn (array $a, array $b) => [$a['date'], $a['key']] <=> [$b['date'], $b['key']]);

        return $dates;
    }

    /**
     * Fechas entre dos días `Y-m-d` (ambos incluidos), también si el rango cruza de año.
     *
     * @return list<array{key: string, name: string, date: string}>
     */
    public static function between(string $from, string $to): array
    {
        $fromYear = (int) substr($from, 0, 4);
        $toYear = (int) substr($to, 0, 4);
        $found = [];
        // Un `offset` puede llevar una fecha al año contiguo a su año de cálculo: se calculan también los años vecinos.
        for ($year = $fromYear - 1; $year <= $toYear + 1; $year++) {
            foreach (self::forYear($year) as $date) {
                if ($date['date'] >= $from && $date['date'] <= $to) {
                    $found[] = $date;
                }
            }
        }

        usort($found, fn (array $a, array $b) => [$a['date'], $a['key']] <=> [$b['date'], $b['key']]);

        return $found;
    }

    /** @param array<string, mixed> $entry */
    private static function resolve(array $entry, int $year): ?CarbonImmutable
    {
        $month = (int) $entry['month'];

        if (isset($entry['day'])) {
            if (! checkdate($month, (int) $entry['day'], $year)) {
                return null;
            }

            return CarbonImmutable::create($year, $month, (int) $entry['day'], 0, 0, 0, 'UTC');
        }

        if (! isset($entry['weekday'], $entry['nth'])) {
            throw new InvalidArgumentException("La fecha señalada «{$entry['key']}» necesita `day` o `weekday` + `nth`.");
        }

        $nth = (int) $entry['nth'];
        $weekday = (int) $entry['weekday'];
        $first = CarbonImmutable::create($year, $month, 1, 0, 0, 0, 'UTC');
        $day = $nth === -1
            ? $first->endOfMonth()->startOfDay()->subDays(($first->endOfMonth()->dayOfWeekIso - $weekday + 7) % 7)
            : $first->addDays(($weekday - $first->dayOfWeekIso + 7) % 7 + 7 * ($nth - 1));

        // El enésimo día de la semana que el mes no tiene (p. ej. un quinto lunes) no cae en el mes siguiente: ese año no hay fecha.
        if ($day->month !== $month) {
            return null;
        }

        return $day->addDays((int) ($entry['offset'] ?? 0));
    }
}
