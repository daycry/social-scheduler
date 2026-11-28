<?php

use App\Models\User;
use App\Support\MarketingDates;
use Illuminate\Support\Carbon;
use Inertia\Testing\AssertableInertia as Assert;

// T-90 · F-17: fechas señaladas propias (fijas y móviles calculadas), visibles en el calendario y ocultables por usuario.

function marketingDateOf(string $key, int $year): ?string
{
    foreach (MarketingDates::forYear($year) as $date) {
        if ($date['key'] === $key) {
            return $date['date'];
        }
    }

    return null;
}

it('calcula Black Friday y Cyber Monday: el día siguiente y el lunes posterior al cuarto jueves de noviembre', function (int $year, string $blackFriday, string $cyberMonday) {
    expect(marketingDateOf('black-friday', $year))->toBe($blackFriday)
        ->and(marketingDateOf('cyber-monday', $year))->toBe($cyberMonday);
})->with([
    [2024, '2024-11-29', '2024-12-02'],
    [2025, '2025-11-28', '2025-12-01'],
    [2026, '2026-11-27', '2026-11-30'],
    [2027, '2027-11-26', '2027-11-29'],
    [2028, '2028-11-24', '2028-11-27'],
]);

it('el Día de la Madre en España es el primer domingo de mayo', function (int $year, string $expected) {
    expect(marketingDateOf('mothers-day', $year))->toBe($expected);
})->with([[2025, '2025-05-04'], [2026, '2026-05-03'], [2027, '2027-05-02'], [2028, '2028-05-07']]);

it('las fechas fijas caen siempre en su día', function () {
    expect(marketingDateOf('new-year', 2027))->toBe('2027-01-01')
        ->and(marketingDateOf('valentines-day', 2027))->toBe('2027-02-14')
        ->and(marketingDateOf('womens-day', 2027))->toBe('2027-03-08')
        ->and(marketingDateOf('world-book-day', 2027))->toBe('2027-04-23')
        ->and(marketingDateOf('christmas', 2027))->toBe('2027-12-25');
});

it('devuelve cada año ordenado por fecha, con nombre y sin duplicados', function () {
    $dates = MarketingDates::forYear(2026);
    $days = array_column($dates, 'date');

    expect($dates)->not->toBeEmpty()
        ->and($days)->toBe(collect($days)->sort()->values()->all())
        ->and(array_unique(array_column($dates, 'key')))->toHaveCount(count($dates));
    foreach ($dates as $date) {
        expect($date['name'])->toBeString()->not->toBeEmpty()->and($date['date'])->toStartWith('2026-');
    }
});

it('between devuelve solo las fechas del rango, incluidos los extremos, también al cruzar de año', function () {
    $days = array_column(MarketingDates::between('2026-12-24', '2027-01-06'), 'date');

    expect($days)->toBe(['2026-12-25', '2026-12-31', '2027-01-01', '2027-01-06']);
});

it('el calendario envía las fechas señaladas del rango y la preferencia', function () {
    Carbon::setTestNow('2026-11-25 12:00:00');
    $user = User::factory()->create();

    $this->actingAs($user)->get(route('calendar.index', ['view' => 'week', 'date' => '2026-11-25']))
        ->assertInertia(fn (Assert $page) => $page
            ->where('marketing_dates_hidden', false)
            ->where('marketing_dates', fn ($dates) => collect($dates)->pluck('name', 'date')->all() === ['2026-11-27' => 'Black Friday']));
});

it('ocultar las fechas las quita del calendario y se recuerda por usuario', function () {
    Carbon::setTestNow('2026-11-25 12:00:00');
    $user = User::factory()->create();
    $other = User::factory()->create();

    $this->actingAs($user)->patch(route('calendar.marketing-dates.update'), ['hidden' => true])->assertRedirect();

    expect($user->fresh()->hide_marketing_dates)->toBeTrue()->and($other->fresh()->hide_marketing_dates)->toBeFalse();
    $this->actingAs($user)->get(route('calendar.index', ['view' => 'week', 'date' => '2026-11-25']))
        ->assertInertia(fn (Assert $page) => $page->where('marketing_dates_hidden', true)->where('marketing_dates', []));
    $this->actingAs($other)->get(route('calendar.index', ['view' => 'week', 'date' => '2026-11-25']))
        ->assertInertia(fn (Assert $page) => $page->where('marketing_dates_hidden', false)->has('marketing_dates', 1));

    $this->actingAs($user)->patch(route('calendar.marketing-dates.update'), ['hidden' => false])->assertRedirect();
    expect($user->fresh()->hide_marketing_dates)->toBeFalse();
});

it('la preferencia exige un booleano y un invitado no puede cambiarla', function () {
    $this->patch(route('calendar.marketing-dates.update'), ['hidden' => true])->assertRedirect(route('login'));
    $this->actingAs(User::factory()->create())->patch(route('calendar.marketing-dates.update'), ['hidden' => 'quizá'])->assertSessionHasErrors('hidden');
});

it('un 29 de febrero fijo solo existe en años bisiestos', function () {
    config(['marketing_dates.dates' => [['key' => 'leap', 'name' => 'Bisiesto', 'month' => 2, 'day' => 29]]]);

    expect(MarketingDates::forYear(2028))->toBe([['key' => 'leap', 'name' => 'Bisiesto', 'date' => '2028-02-29']])
        ->and(MarketingDates::forYear(2027))->toBe([]);
});

it('el enésimo día de la semana que el mes no tiene no cae en el mes siguiente; nth -1 es el último', function () {
    config(['marketing_dates.dates' => [
        ['key' => 'fifth-monday', 'name' => 'Quinto lunes', 'month' => 2, 'weekday' => 1, 'nth' => 5],
        ['key' => 'last-friday', 'name' => 'Último viernes', 'month' => 2, 'weekday' => 5, 'nth' => -1],
    ]]);

    expect(array_column(MarketingDates::forYear(2027), 'date'))->toBe(['2027-02-26'])
        ->and(array_column(MarketingDates::forYear(2028), 'date'))->toBe(['2028-02-25']);
    config(['marketing_dates.dates' => [['key' => 'fifth-monday', 'name' => 'Quinto lunes', 'month' => 2, 'weekday' => 1, 'nth' => 5]]]);
    expect(MarketingDates::forYear(2027))->toBe([]);
});

it('between incluye las fechas cuyo offset las lleva al año contiguo', function () {
    config(['marketing_dates.dates' => [
        ['key' => 'after-december', 'name' => 'Tras el último jueves de diciembre', 'month' => 12, 'weekday' => 4, 'nth' => -1, 'offset' => 10],
        ['key' => 'before-january', 'name' => 'Antes del primer lunes de enero', 'month' => 1, 'weekday' => 1, 'nth' => 1, 'offset' => -10],
    ]]);

    expect(array_column(MarketingDates::between('2027-01-05', '2027-01-15'), 'date'))->toBe(['2027-01-10'])
        ->and(array_column(MarketingDates::between('2026-12-20', '2026-12-30'), 'date'))->toBe(['2026-12-25']);
});
