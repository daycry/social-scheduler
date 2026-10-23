<?php

namespace App\Analytics;

use App\Connectors\Network;
use Carbon\CarbonImmutable;
use InvalidArgumentException;

/** Rango de fechas (inclusivo, dias UTC como los snapshots) del selector de periodo del dashboard. */
final class Period
{
    public const MAX_DAYS = 366;

    public const COMPARE_MODES = ['none', 'previous', 'year'];

    private function __construct(public readonly CarbonImmutable $from, public readonly CarbonImmutable $to) {}

    public static function between(CarbonImmutable $from, CarbonImmutable $to): self
    {
        $from = $from->startOfDay();
        $to = $to->startOfDay();

        if ($to->lt($from)) {
            throw new InvalidArgumentException('El periodo es invalido: la fecha final es anterior a la inicial.');
        }

        if ($from->diffInDays($to) + 1 > self::MAX_DAYS) {
            throw new InvalidArgumentException('El periodo no puede superar '.self::MAX_DAYS.' dias.');
        }

        return new self($from, $to);
    }

    /**
     * Por defecto los ultimos 30 dias cerrados. El fin es "ayer" en la zona indicada (la de la marca) sin pasar del ultimo
     * dia cerrado de ninguna red (el dia de Meta/YouTube es el del Pacifico y se recoge mas tarde): asi el ultimo dia del
     * periodo nunca es uno aun sin recoger. Las fechas son etiquetas Y-m-d, como las de los snapshots.
     */
    public static function make(?string $from, ?string $to, ?string $timezone = null): self
    {
        try {
            $today = CarbonImmutable::now(self::validZone($timezone))->format('Y-m-d');
            $end = $to ? CarbonImmutable::createFromFormat('!Y-m-d', $to, 'UTC') : self::defaultEnd($today);
            $start = $from ? CarbonImmutable::createFromFormat('!Y-m-d', $from, 'UTC') : $end->subDays(29);
        } catch (\Throwable) {
            throw new InvalidArgumentException('Fecha no valida.');
        }

        if ($end === false || $start === false) {
            throw new InvalidArgumentException('Fecha no valida.');
        }

        return self::between($start, $end);
    }

    /**
     * Limites UTC [desde, hasta) del periodo: sus dias son dias de la zona indicada (la de la marca). Una publicacion de
     * las 23:30 locales del ultimo dia pertenece al periodo aunque en UTC ya sea el dia siguiente.
     *
     * @return array{0: CarbonImmutable, 1: CarbonImmutable}
     */
    public function utcBounds(?string $timezone): array
    {
        $zone = self::validZone($timezone);

        return [
            CarbonImmutable::createFromFormat('!Y-m-d', $this->from->format('Y-m-d'), $zone)->utc(),
            CarbonImmutable::createFromFormat('!Y-m-d', $this->to->format('Y-m-d'), $zone)->addDay()->utc(),
        ];
    }

    private static function defaultEnd(string $today): CarbonImmutable
    {
        $end = CarbonImmutable::createFromFormat('!Y-m-d', $today, 'UTC')->subDay();

        foreach (Network::cases() as $network) {
            $closed = Days::closed($network);
            $end = $closed->lt($end) ? $closed : $end;
        }

        return $end;
    }

    private static function validZone(?string $zone): string
    {
        try {
            return (new \DateTimeZone($zone ?: 'UTC'))->getName();
        } catch (\Throwable) {
            return 'UTC';
        }
    }

    public function days(): int
    {
        return (int) $this->from->diffInDays($this->to) + 1;
    }

    /** Periodo de comparacion: el inmediatamente anterior de igual duracion, o el rango que empieza un anio antes con la misma duracion. */
    public function comparison(string $mode): ?self
    {
        return match ($mode) {
            'previous' => self::between($this->from->subDays($this->days()), $this->from->subDay()),
            // Misma LONGITUD que el periodo actual (un rango con 29-feb no debe comparar con uno de otra duracion).
            'year' => self::between($this->from->subYearNoOverflow(), $this->from->subYearNoOverflow()->addDays($this->days() - 1)),
            default => null,
        };
    }

    /** @return list<string> Todas las fechas del periodo (Y-m-d). */
    public function dates(): array
    {
        $out = [];

        for ($d = $this->from; $d->lte($this->to); $d = $d->addDay()) {
            $out[] = $d->format('Y-m-d');
        }

        return $out;
    }

    /** @return array{from: string, to: string, days: int} */
    public function toArray(): array
    {
        return ['from' => $this->from->format('Y-m-d'), 'to' => $this->to->format('Y-m-d'), 'days' => $this->days()];
    }
}
