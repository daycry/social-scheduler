<?php

use App\Connectors\Network;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\File;

dataset('provider networks', fn () => array_map(fn (Network $network) => $network->value, Network::cases()));

test('hay ejemplos de éxito y error para cada red', function (string $network) {
    foreach (['success', 'error'] as $outcome) {
        $path = base_path("tests/Fixtures/providers/$network/$outcome.json");
        expect(is_file($path))->toBeTrue("Falta la respuesta $outcome de $network.");
        $fixture = json_decode(file_get_contents($path), true, flags: JSON_THROW_ON_ERROR);

        expect($fixture)->toHaveKeys(['source', 'method', 'host', 'path', 'status', 'headers', 'body']);
        expect(parse_url($fixture['source'], PHP_URL_SCHEME))->toBe('https');
        expect($fixture['path'])->toStartWith('/');
        expect($fixture['host'])->toMatch('/^[a-z0-9.-]+$/');
        expect($fixture['method'])->toBeIn(['GET', 'POST', 'PUT', 'PATCH', 'DELETE']);
        expect(is_array($fixture['body']) || is_string($fixture['body']))->toBeTrue();
        expect($fixture['headers'])->toBeArray();
        foreach ($fixture['headers'] as $header => $pattern) {
            expect(@preg_match($pattern, ''))->not->toBeFalse("$network/$outcome: patrón inválido para $header.");
        }
        expect($fixture['query'] ?? [])->toBeArray();
        foreach ($fixture['query'] ?? [] as $value) {
            expect($value)->toBeString();
        }
        if ($outcome === 'error') {
            expect($fixture)->toHaveKey('fixed');
            expect($fixture['fixed'])->toBeArray()->not->toBeEmpty();
        }
        foreach ($fixture['fixed'] ?? [] as $field) {
            expect(Arr::has($fixture['body'], $field))->toBeTrue("$network/$outcome: falta $field.");
        }
        expect($fixture['status'])->toBeInt()->toBeGreaterThanOrEqual($outcome === 'success' ? 200 : 400)
            ->toBeLessThan($outcome === 'success' ? 300 : 600);
    }
})->with('provider networks')->group('provider-contracts');

test('los ejemplos de proveedores no guardan credenciales ni datos personales', function () {
    $paths = glob(base_path('tests/Fixtures/providers/*/*.json'));
    expect($paths)->toHaveCount(count(Network::cases()) * 2);
    foreach ($paths as $path) {
        expect(file_get_contents($path))->not->toMatch(
            '~(?:access_token|refresh_token|upload_token|client_secret|authorization|cookie|EAA[a-z0-9]{20,}|ya29\.[a-z0-9._-]{20,}|act\.[a-z0-9._-]{20,}|[?&](?:sig|signature|key|x-amz-signature)=|bearer\s+[a-z0-9._-]{20,}|[a-z0-9._%+-]+@[a-z0-9.-]+\.[a-z]{2,})~i',
            basename(dirname($path)).'/'.basename($path),
        );
    }
})->group('provider-contracts');

test('cada ejemplo se comprueba una vez en los tests de conectores', function () {
    $examples = array_map(
        fn (string $path) => basename(dirname($path)).'/'.basename($path, '.json'),
        glob(base_path('tests/Fixtures/providers/*/*.json')),
    );
    $references = [];
    foreach (File::allFiles(base_path('tests/Feature/Connectors')) as $file) {
        if (! str_ends_with($file->getFilename(), 'Test.php')) {
            continue;
        }
        preg_match_all("/^\\h*FixtureRecorder::assertRecorded\\('([a-z][a-z0-9_-]*\\/(?:success|error))'\\);/m", $file->getContents(), $matches);
        array_push($references, ...$matches[1]);
    }
    sort($examples);
    sort($references);

    expect($references)->toBe($examples);
})->group('provider-contracts');
