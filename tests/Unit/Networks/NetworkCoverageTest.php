<?php

use App\Analytics\Metric;
use App\Connectors\Network;
use App\Enums\ContentType;
use App\Services\AnalyticsService;
use Tests\TestCase;

uses(TestCase::class);

/**
 * Una red del catalogo no puede quedar a medias: cada superficie por red (tipos de contenido y reglas de media,
 * flujos y metricas de la analitica, zona horaria del dia, icono) debe existir antes de ofrecerla. Si se anade un
 * caso a `Network` y se registra su conector sin una superficie, este test falla en CI.
 *
 * @return list<string> Superficies que le faltan a la red.
 */
function surfaceGaps(string $network): array
{
    $gaps = [];
    $case = Network::tryFrom($network);

    if ($case === null) {
        return ['catalogo (Network)'];
    }

    $types = ContentType::forNetwork($case);
    if ($types === []) {
        $gaps[] = 'tipos de contenido (ContentType)';
    }
    foreach ($types as $type) {
        foreach ([$type->specKey(), $type->specKey('video')] as $key) {
            if (! array_key_exists($key, config('media_specs.types'))) {
                $gaps[] = "regla de media {$key} (media_specs)";
            }
        }
    }
    if (! array_key_exists($network, Metric::EXPECTED_FLOWS)) {
        $gaps[] = 'Metric::EXPECTED_FLOWS';
    }
    if (! array_key_exists($network, AnalyticsService::SUPPORTED)) {
        $gaps[] = 'AnalyticsService::SUPPORTED';
    }
    if (! array_key_exists($network, config('social.analytics.timezones'))) {
        $gaps[] = 'social.analytics.timezones';
    }
    if (! str_contains((string) file_get_contents(resource_path('js/Components/NetworkIcon.vue')), "network === '{$network}'")) {
        $gaps[] = 'icono en NetworkIcon.vue';
    }
    if (! preg_match('/^#[0-9a-f]{6}$/i', $case->color()) || trim($case->label()) === '' || trim($case->shortLabel()) === '') {
        $gaps[] = 'etiqueta o color';
    }

    return $gaps;
}

test('el detector encuentra una red sin superficies', function () {
    expect(surfaceGaps('inexistente'))->not->toBeEmpty();
});

test('toda red ofrecida tiene todas sus superficies', function () {
    foreach (Network::offered() as $network) {
        expect(surfaceGaps($network->value))->toBe([], "A {$network->value} le faltan superficies");
    }
});

test('las redes OAuth de siempre siguen con todas sus superficies', function () {
    foreach ([Network::Instagram, Network::Facebook, Network::YouTube, Network::TikTok] as $network) {
        expect(surfaceGaps($network->value))->toBe([]);
    }
});

test('ninguna pagina repite una lista fija de redes fuera de NetworkIcon.vue', function () {
    $offenders = [];
    $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(resource_path('js'), FilesystemIterator::SKIP_DOTS));
    $values = implode('|', array_map(fn (Network $n) => $n->value, Network::cases()));
    // Lista: dos redes seguidas como literales («'instagram', 'facebook'») o un mapa por red («instagram: {» / «{ value: 'tiktok'»).
    $pattern = "/['\"](?:{$values})['\"]\s*,\s*['\"](?:{$values})['\"]|\bvalue:\s*['\"](?:{$values})['\"]|^\s*(?:{$values}):\s*[\{'\[]/m";

    foreach ($files as $file) {
        if (! in_array($file->getExtension(), ['vue', 'js'], true) || $file->getFilename() === 'NetworkIcon.vue') {
            continue;
        }
        if (preg_match($pattern, (string) file_get_contents($file->getPathname()), $m)) {
            $offenders[] = str_replace(base_path().DIRECTORY_SEPARATOR, '', $file->getPathname()).' -> '.trim($m[0]);
        }
    }

    expect($offenders)->toBe([]);
});
