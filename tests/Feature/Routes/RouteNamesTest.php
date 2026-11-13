<?php

use Illuminate\Support\Facades\Route;

it('no comparte nombre entre dos rutas', function () {
    $names = collect(Route::getRoutes()->getRoutes())->map(fn ($route) => $route->getName())->filter()->values();
    $repeated = $names->duplicates()->unique()->values()->all();

    expect($repeated)->toBe([]);
});

it('no deja nombres autogenerados por indice', function () {
    $generated = collect(Route::getRoutes()->getRoutes())
        ->map(fn ($route) => $route->getName())
        ->filter(fn ($name) => $name && (preg_match('/(^|\.)generated::/', $name) || preg_match('/-\d+$/', $name)))
        ->values()->all();

    expect($generated)->toBe([]);
});

it('mantiene las rutas de la API bajo api.studio y las web bajo studio', function () {
    $names = collect(Route::getRoutes()->getRoutes())->map(fn ($route) => $route->getName())->filter();

    expect($names->contains('studio.folders.index'))->toBeTrue()
        ->and($names->contains('api.studio.folders.index'))->toBeTrue()
        ->and($names->contains('studio.datasets.import-create'))->toBeTrue()
        ->and($names->contains('studio.datasets.import-replace'))->toBeTrue();
});
