<?php

use App\Connectors\Network;
use App\Connectors\Support\UploadPassBudget;

test('UploadPassBudget: el timeout de cada llamada se acota a lo que queda de la pasada, nunca supera la marca dura (revision F4-5 #7)', function (Network $network) {
    config(["social.{$network->value}.pass_seconds" => 100]);
    $pass = new UploadPassBudget($network, minSliceDefault: 10);
    $timeout = function (float $elapsed) use ($pass) {
        $pass->begin(microtime(true) - $elapsed);

        return $pass->callTimeout(60.0);
    };

    expect($timeout(0))->toBe(60.0)                                         // pasada recien empezada: el tope propio
        ->and($timeout(70))->toBeGreaterThan(29.0)->toBeLessThanOrEqual(30.0) // quedan ~30 s: la llamada no puede pasarse
        ->and($timeout(99.5))->toBeLessThanOrEqual(1.0)                     // al borde de la marca: 1 s
        ->and($timeout(120))->toBe(1.0);
})->with([Network::TikTok, Network::YouTube]);

test('UploadPassBudget: el presupuesto blando baja con el tiempo y se agota en 0', function (Network $network) {
    config(["social.{$network->value}.upload_budget_seconds" => 80]);
    $pass = new UploadPassBudget($network, minSliceDefault: 10);

    $pass->begin(microtime(true) - 60);
    expect($pass->budgetLeft())->toBeGreaterThan(19.0)->toBeLessThanOrEqual(20.0);

    $pass->begin(microtime(true) - 200);
    expect($pass->budgetLeft())->toBe(0.0);
})->with([Network::TikTok, Network::YouTube]);

test('UploadPassBudget: fuera de una pasada no recorta nada (tope propio y presupuesto entero) y end() la cierra', function () {
    config(['social.tiktok.upload_budget_seconds' => 80]);
    $pass = new UploadPassBudget(Network::TikTok, minSliceDefault: 15);

    expect($pass->callTimeout(60.0))->toBe(60.0)->and($pass->budgetLeft())->toBe(80.0);

    $pass->begin(microtime(true) - 90);
    $pass->end();

    expect($pass->callTimeout(60.0))->toBe(60.0)->and($pass->budgetLeft())->toBe(80.0);
});

test('UploadPassBudget: cada red lee su propia configuracion', function () {
    config(['social.tiktok.upload_budget_seconds' => 30, 'social.youtube.upload_budget_seconds' => 70]);

    expect((new UploadPassBudget(Network::TikTok, minSliceDefault: 15))->budgetLeft())->toBe(30.0)->and((new UploadPassBudget(Network::YouTube, minSliceDefault: 10))->budgetLeft())->toBe(70.0);
});

test('UploadPassBudget: cada red aplica su margen y una pasada agotada no admite trozos aunque el mínimo sea cero', function () {
    config([
        'social.tiktok.upload_budget_seconds' => 80, 'social.tiktok.upload_min_slice_seconds' => 40,
        'social.youtube.upload_budget_seconds' => 80, 'social.youtube.upload_min_slice_seconds' => 5,
    ]);
    $tiktok = new UploadPassBudget(Network::TikTok, minSliceDefault: 15);
    $youtube = new UploadPassBudget(Network::YouTube, minSliceDefault: 10);
    $tiktok->begin(microtime(true) - 60);
    $youtube->begin(microtime(true) - 60);

    expect($tiktok->canStartChunk())->toBeFalse()->and($youtube->canStartChunk())->toBeTrue();

    config(['social.youtube.upload_min_slice_seconds' => 0]);
    expect($youtube->canStartChunk())->toBeTrue();

    $youtube->begin(microtime(true) - 120);
    expect($youtube->canStartChunk())->toBeFalse();
});

test('UploadPassBudget: conserva el margen por defecto del llamador si falta la clave', function () {
    foreach ([Network::TikTok, Network::YouTube] as $network) {
        $key = 'social.'.$network->value;
        $settings = config($key);
        unset($settings['upload_min_slice_seconds']);
        $settings['upload_budget_seconds'] = 80;
        config([$key => $settings]);
    }

    $tiktok = new UploadPassBudget(Network::TikTok, minSliceDefault: 15);
    $youtube = new UploadPassBudget(Network::YouTube, minSliceDefault: 10);
    $started = microtime(true) - 68;
    $tiktok->begin($started);
    $youtube->begin($started);

    expect($tiktok->canStartChunk())->toBeFalse()
        ->and($youtube->canStartChunk())->toBeTrue();
});
