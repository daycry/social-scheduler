<?php

use App\Connectors\Bluesky\Tid;

test('una TID tiene 13 caracteres base32-sortable', function () {
    $tid = Tid::generate();

    expect($tid)->toHaveLength(13)->toMatch('/^[234567abcdefghij][234567abcdefghijklmnopqrstuvwxyz]{12}$/')
        ->and(Tid::isValid($tid))->toBeTrue();
});

test('las TID son estrictamente crecientes, tambien dentro del mismo microsegundo', function () {
    $tids = [];
    for ($i = 0; $i < 2000; $i++) {
        $tids[] = Tid::generate();
    }

    $sorted = $tids;
    sort($sorted, SORT_STRING);

    expect($sorted)->toBe($tids)->and(count(array_unique($tids)))->toBe(2000);
});

test('encode produce el alfabeto esperado y el valor cero', function () {
    expect(Tid::encode(0))->toBe('2222222222222')
        ->and(Tid::encode(31))->toBe('222222222222z')
        ->and(Tid::encode(32))->toBe('2222222222232');
});

test('la marca de tiempo esta en los bits altos: una TID mas nueva ordena despues', function () {
    $old = Tid::encode((1_700_000_000_000_000 << 10) | 5);
    $new = Tid::encode((1_800_000_000_000_000 << 10) | 5);

    expect(strcmp($old, $new))->toBeLessThan(0)->and(Tid::isValid($old))->toBeTrue();
});

test('isValid rechaza lo que no es una TID', function () {
    foreach (['', 'abc', '3kabcdefghij2x', 'zzzzzzzzzzzzz', '0123456789abc', 'ABCDEFGHIJKLM'] as $bad) {
        expect(Tid::isValid($bad))->toBeFalse($bad);
    }
    expect(Tid::isValid('3kabcdefghij2'))->toBeTrue();
});
