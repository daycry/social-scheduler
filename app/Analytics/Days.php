<?php

namespace App\Analytics;

use App\Connectors\Network;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Throwable;

/**
 * "Dia" de un snapshot por red. Cada API define el dia en SU zona (Meta: dia natural del Pacifico; YouTube Analytics:
 * Pacifico tambien), asi que el ultimo dia CERRADO de una red no es "ayer en UTC": a las 03:30 UTC todavia no ha
 * terminado el dia de ayer en el Pacifico. Las fechas se guardan como etiqueta Y-m-d (a medianoche UTC) del dia de la red.
 */
final class Days
{
    public static function zone(Network $network): string
    {
        $zone = config('social.analytics.timezones.'.$network->value, 'UTC');

        try {
            new \DateTimeZone((string) $zone);
        } catch (Throwable) {
            return 'UTC';
        }

        return (string) $zone;
    }

    /** Ultimo dia cerrado de la red (el de "ayer" en su zona) como fecha a medianoche UTC. */
    public static function closed(Network $network, ?CarbonInterface $now = null): CarbonImmutable
    {
        $local = CarbonImmutable::instance($now ?? now())->setTimezone(self::zone($network))->startOfDay()->subDay();

        return CarbonImmutable::createFromFormat('!Y-m-d', $local->format('Y-m-d'), 'UTC');
    }

    /** ¿$day queda fuera de la ventana de reintentos (`settle_days`) contada desde el ultimo dia cerrado? */
    public static function isHistoric(Network $network, CarbonInterface $day, ?CarbonInterface $now = null): bool
    {
        $limit = self::closed($network, $now)->subDays(max(0, (int) config('social.analytics.settle_days', 2)));

        return CarbonImmutable::createFromFormat('!Y-m-d', $day->format('Y-m-d'), 'UTC')->lte($limit);
    }
}
