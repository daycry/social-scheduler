<?php

use App\Models\CalendarView;
use App\Models\User;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\Auth;

// C-03a · CA-11: vistas de calendario guardadas (nombre + filtros de un solo valor) por usuario.

it('cada usuario solo ve sus vistas (UserScope)', function () {
    $a = User::factory()->create();
    $b = User::factory()->create();
    CalendarView::factory()->create(['user_id' => $a->id, 'name' => 'de A']);
    CalendarView::factory()->create(['user_id' => $b->id, 'name' => 'de B']);

    Auth::login($a);

    expect(CalendarView::pluck('name')->all())->toBe(['de A'])
        ->and(CalendarView::withoutUserScope()->count())->toBe(2);
});

it('filters se castea a array y conserva marca, red, estado y vista', function () {
    $user = User::factory()->create();
    $view = CalendarView::factory()->create(['user_id' => $user->id, 'filters' => ['brand_id' => 7, 'network' => 'instagram', 'status' => 'programada', 'view' => 'list']]);

    $fresh = CalendarView::withoutUserScope()->find($view->id);

    expect($fresh->filters)->toBeArray()->toBe(['brand_id' => 7, 'network' => 'instagram', 'status' => 'programada', 'view' => 'list'])
        ->and($fresh->is_default)->toBeFalse();
});

it('is_default se castea a booleano', function () {
    $view = CalendarView::factory()->create(['is_default' => true]);

    expect(CalendarView::withoutUserScope()->find($view->id)->is_default)->toBeTrue();
});

it('el nombre es único por usuario pero se puede repetir entre usuarios', function () {
    $a = User::factory()->create();
    $b = User::factory()->create();
    CalendarView::factory()->create(['user_id' => $a->id, 'name' => 'Pendientes IG']);
    CalendarView::factory()->create(['user_id' => $b->id, 'name' => 'Pendientes IG']);

    expect(fn () => CalendarView::factory()->create(['user_id' => $a->id, 'name' => 'Pendientes IG']))
        ->toThrow(UniqueConstraintViolationException::class);
});
