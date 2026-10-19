<?php

namespace App\Analytics;

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

    /** Por defecto los ultimos 30 dias cerrados (hasta ayer). */
    public static function make(?string $from, ?string $to): self
    {
        try {
            $end = $to ? CarbonImmutable::createFromFormat('!Y-m-d', $to, 'UTC') : CarbonImmutable::now('UTC')->startOfDay()->subDay();
            $start = $from ? CarbonImmutable::createFromFormat('!Y-m-d', $from, 'UTC') : $end->subDays(29);
        } catch (\Throwable) {
            throw new InvalidArgumentException('Fecha no valida.');
        }

        if ($end === false || $start === false) {
            throw new InvalidArgumentException('Fecha no valida.');
        }

        return self::between($start, $end);
    }

    public function days(): int
    {
        return (int) $this->from->diffInDays($this->to) + 1;
    }

    /** Periodo de comparacion: el inmediatamente anterior de igual duracion, o el mismo rango un anio antes. */
    public function comparison(string $mode): ?self
    {
        return match ($mode) {
            'previous' => self::between($this->from->subDays($this->days()), $this->from->subDay()),
            'year' => self::between($this->from->subYear(), $this->to->subYear()),
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
