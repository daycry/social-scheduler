<?php

namespace App\Analytics;

/**
 * Calculos puros (sin BD) sobre series de snapshots. Reglas de la capa de normalizacion (T-35):
 *  - `null` = "no disponible": nunca se trata como 0 ni se mezcla en sumas o medias.
 *  - Los FLUJOS (visualizaciones, alcance...) se suman en el periodo; los STOCKS (seguidores) se leen en un
 *    instante (el ultimo valor conocido del periodo).
 *  - Sin equivalencias inventadas entre redes: cada red aporta las metricas que su API ofrece.
 *  - Engagement = interacciones / visualizaciones del mismo ambito (cuenta o publicacion) x 100.
 */
final class Calculator
{
    /**
     * Valor de un periodo: ultimo valor conocido (stock) o suma de los dias con dato (flujo). `null` si no hay datos.
     *
     * @param  array<string, int|float|null>  $series  [fecha => valor]
     */
    public static function total(array $series, string $metric): int|float|null
    {
        $values = array_filter($series, fn ($v) => $v !== null);

        if ($values === []) {
            return null;
        }

        if (in_array($metric, Metric::STOCKS, true)) {
            ksort($values);

            return end($values);
        }

        return array_sum($values);
    }

    /**
     * Crecimiento de un stock: primer y ultimo valor conocidos del periodo, cambio absoluto y porcentual.
     * Con menos de dos puntos no hay crecimiento medible (`change` = null).
     *
     * @param  array<string, int|float|null>  $series
     * @return array{start: int|float|null, end: int|float|null, change: int|float|null, pct: float|null}
     */
    public static function growth(array $series): array
    {
        $values = array_filter($series, fn ($v) => $v !== null);
        ksort($values);
        $points = array_values($values);

        if ($points === []) {
            return ['start' => null, 'end' => null, 'change' => null, 'pct' => null];
        }

        $start = $points[0];
        $end = $points[count($points) - 1];

        if (count($points) < 2) {
            return ['start' => $start, 'end' => $end, 'change' => null, 'pct' => null];
        }

        return ['start' => $start, 'end' => $end, 'change' => $end - $start, 'pct' => self::pct($end - $start, $start)];
    }

    /**
     * Variacion frente al periodo de comparacion.
     *
     * @return array{abs: int|float|null, pct: float|null}
     */
    public static function delta(int|float|null $current, int|float|null $previous): array
    {
        if ($current === null || $previous === null) {
            return ['abs' => null, 'pct' => null];
        }

        return ['abs' => $current - $previous, 'pct' => self::pct($current - $previous, $previous)];
    }

    /** Tasa de engagement (%): interacciones / visualizaciones. `null` si falta alguna o no hay visualizaciones. */
    public static function engagementRate(int|float|null $interactions, int|float|null $views): ?float
    {
        if ($interactions === null || $views === null || $views <= 0) {
            return null;
        }

        return round($interactions / $views * 100, 2);
    }

    /**
     * Media por dia sobre los dias CON dato.
     *
     * @param  array<string, int|float|null>  $series
     */
    public static function perDay(array $series): ?float
    {
        $values = array_filter($series, fn ($v) => $v !== null);

        return $values === [] ? null : round(array_sum($values) / count($values), 2);
    }

    /**
     * Media de una metrica entre las publicaciones que la tienen.
     *
     * @param  list<int|float|null>  $values
     */
    public static function average(array $values): ?float
    {
        $values = array_filter($values, fn ($v) => $v !== null);

        return $values === [] ? null : round(array_sum($values) / count($values), 2);
    }

    /**
     * Suma por clave de varias series (p. ej. varias cuentas de una misma red): `null` solo si todas lo son ese dia.
     *
     * @param  list<array<string, int|float|null>>  $seriesList
     * @return array<string, int|float|null>
     */
    public static function sumSeries(array $seriesList): array
    {
        $out = [];

        foreach ($seriesList as $series) {
            foreach ($series as $date => $v) {
                $out[$date] = $v === null ? ($out[$date] ?? null) : (($out[$date] ?? 0) + $v);
            }
        }

        ksort($out);

        return $out;
    }

    private static function pct(int|float $change, int|float $base): ?float
    {
        return $base == 0 ? null : round($change / abs($base) * 100, 2);
    }
}
