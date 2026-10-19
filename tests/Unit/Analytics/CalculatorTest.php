<?php

use App\Analytics\Calculator;
use App\Analytics\Metric;
use App\Analytics\Period;
use Carbon\CarbonImmutable;

it('Analytics: el total de un flujo suma los dias con dato y es null si no hay ninguno', function () {
    expect(Calculator::total(['2026-10-01' => 5, '2026-10-02' => null, '2026-10-03' => 7], Metric::VIEWS))->toBe(12)
        ->and(Calculator::total(['2026-10-01' => null, '2026-10-02' => null], Metric::VIEWS))->toBeNull()
        ->and(Calculator::total([], Metric::VIEWS))->toBeNull()
        ->and(Calculator::total(['2026-10-01' => 0, '2026-10-02' => 0], Metric::VIEWS))->toBe(0); // 0 real != no disponible
});

it('Analytics: un stock toma el ultimo valor conocido y no se suma', function () {
    $s = ['2026-10-03' => 130, '2026-10-01' => 100, '2026-10-02' => null];

    expect(Calculator::total($s, Metric::FOLLOWERS))->toBe(130);
});

it('Analytics: crecimiento de seguidores absoluto y porcentual', function () {
    $g = Calculator::growth(['2026-10-01' => 200, '2026-10-02' => null, '2026-10-03' => 250]);

    expect($g)->toBe(['start' => 200, 'end' => 250, 'change' => 50, 'pct' => 25.0]);
});

it('Analytics: crecimiento negativo, sin base y sin puntos suficientes', function () {
    expect(Calculator::growth(['a' => 200, 'b' => 150])['pct'])->toBe(-25.0)
        ->and(Calculator::growth(['a' => 0, 'b' => 10]))->toBe(['start' => 0, 'end' => 10, 'change' => 10, 'pct' => null])
        ->and(Calculator::growth(['a' => null, 'b' => 10]))->toBe(['start' => 10, 'end' => 10, 'change' => null, 'pct' => null])
        ->and(Calculator::growth(['a' => null]))->toBe(['start' => null, 'end' => null, 'change' => null, 'pct' => null]);
});

it('Analytics: variacion frente al periodo de comparacion', function () {
    expect(Calculator::delta(150, 100))->toBe(['abs' => 50, 'pct' => 50.0])
        ->and(Calculator::delta(50, 100))->toBe(['abs' => -50, 'pct' => -50.0])
        ->and(Calculator::delta(10, 0))->toBe(['abs' => 10, 'pct' => null])
        ->and(Calculator::delta(null, 100))->toBe(['abs' => null, 'pct' => null])
        ->and(Calculator::delta(100, null))->toBe(['abs' => null, 'pct' => null]);
});

it('Analytics: engagement = interacciones / visualizaciones y no disponible si falta alguna', function () {
    expect(Calculator::engagementRate(50, 1000))->toBe(5.0)
        ->and(Calculator::engagementRate(1, 3))->toBe(33.33)
        ->and(Calculator::engagementRate(0, 100))->toBe(0.0)
        ->and(Calculator::engagementRate(null, 100))->toBeNull()
        ->and(Calculator::engagementRate(10, null))->toBeNull()
        ->and(Calculator::engagementRate(10, 0))->toBeNull();
});

it('Analytics: medias por dia y por publicacion ignoran lo no disponible', function () {
    expect(Calculator::perDay(['a' => 10, 'b' => null, 'c' => 20]))->toBe(15.0)
        ->and(Calculator::perDay(['a' => null]))->toBeNull()
        ->and(Calculator::average([10, null, 20, 0]))->toBe(10.0)
        ->and(Calculator::average([null]))->toBeNull();
});

it('Analytics: sumar series de varias cuentas conserva null solo si todas lo son', function () {
    $sum = Calculator::sumSeries([
        ['2026-10-01' => 1, '2026-10-02' => null, '2026-10-03' => null],
        ['2026-10-01' => 2, '2026-10-02' => 5, '2026-10-03' => null],
    ]);

    expect($sum)->toBe(['2026-10-01' => 3, '2026-10-02' => 5, '2026-10-03' => null]);
});

it('Analytics: el periodo calcula dias, fechas y comparacion', function () {
    $p = Period::make('2026-10-01', '2026-10-10');

    expect($p->days())->toBe(10)->and($p->dates())->toHaveCount(10)->and($p->dates()[0])->toBe('2026-10-01')
        ->and($p->comparison('previous')->toArray())->toBe(['from' => '2026-09-21', 'to' => '2026-09-30', 'days' => 10])
        ->and($p->comparison('year')->toArray()['from'])->toBe('2025-10-01')
        ->and($p->comparison('none'))->toBeNull();
});

it('Analytics: periodo por defecto y validaciones', function () {
    CarbonImmutable::setTestNow('2026-11-01 12:00:00');

    expect(Period::make(null, null)->toArray())->toBe(['from' => '2026-10-02', 'to' => '2026-10-31', 'days' => 30])
        ->and(fn () => Period::make('2026-10-10', '2026-10-01'))->toThrow(InvalidArgumentException::class)
        ->and(fn () => Period::make('2024-01-01', '2026-01-01'))->toThrow(InvalidArgumentException::class)
        ->and(fn () => Period::make('no-fecha', null))->toThrow(InvalidArgumentException::class);

    CarbonImmutable::setTestNow();
});
