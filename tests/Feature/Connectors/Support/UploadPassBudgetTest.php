<?php

use App\Connectors\Support\UploadPassBudget;

test('UploadPassBudget: el timeout de cada llamada se acota a lo que queda de la pasada, nunca supera la marca dura (revision F4-5 #7)', function (string $network) {
    config(["social.$network.pass_seconds" => 100]);
    $pass = new UploadPassBudget($network);
    $timeout = function (float $elapsed) use ($pass) {
        $pass->begin(microtime(true) - $elapsed);

        return $pass->callTimeout(60.0);
    };

    expect($timeout(0))->toBe(60.0)                                         // pasada recien empezada: el tope propio
        ->and($timeout(70))->toBeGreaterThan(29.0)->toBeLessThanOrEqual(30.0) // quedan ~30 s: la llamada no puede pasarse
        ->and($timeout(99.5))->toBeLessThanOrEqual(1.0)                     // al borde de la marca: 1 s
        ->and($timeout(120))->toBe(1.0);
})->with(['tiktok', 'youtube']);

test('UploadPassBudget: el presupuesto blando baja con el tiempo y se agota en 0', function (string $network) {
    config(["social.$network.upload_budget_seconds" => 80]);
    $pass = new UploadPassBudget($network);

    $pass->begin(microtime(true) - 60);
    expect($pass->budgetLeft())->toBeGreaterThan(19.0)->toBeLessThanOrEqual(20.0);

    $pass->begin(microtime(true) - 200);
    expect($pass->budgetLeft())->toBe(0.0);
})->with(['tiktok', 'youtube']);

test('UploadPassBudget: fuera de una pasada no recorta nada (tope propio y presupuesto entero) y end() la cierra', function () {
    config(['social.tiktok.upload_budget_seconds' => 80]);
    $pass = new UploadPassBudget('tiktok');

    expect($pass->callTimeout(60.0))->toBe(60.0)->and($pass->budgetLeft())->toBe(80.0);

    $pass->begin(microtime(true) - 90);
    $pass->end();

    expect($pass->callTimeout(60.0))->toBe(60.0)->and($pass->budgetLeft())->toBe(80.0);
});

test('UploadPassBudget: cada red lee su propia configuracion', function () {
    config(['social.tiktok.upload_budget_seconds' => 30, 'social.youtube.upload_budget_seconds' => 70]);

    expect((new UploadPassBudget('tiktok'))->budgetLeft())->toBe(30.0)->and((new UploadPassBudget('youtube'))->budgetLeft())->toBe(70.0);
});
