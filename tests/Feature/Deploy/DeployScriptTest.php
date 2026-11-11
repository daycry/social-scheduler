<?php

use Illuminate\Support\Facades\Process;

/**
 * Auditoria A-10: el despliegue compila ANTES de entrar en mantenimiento, comprueba la CI, hace el volcado
 * previo a migrate y la vuelta atras se puede ensayar en seco. Se lee el script y su `--dry-run` (sin tocar nada).
 */
function dryRunSteps(string ...$args): array
{
    $result = Process::run(['bash', base_path('deploy/hestia/deploy.sh'), '--dry-run', ...$args]);
    test()->assertTrue($result->successful(), $result->errorOutput());

    preg_match_all('/^\s*\d+\. \[(\w+)\]/m', $result->output(), $m);

    return $m[1];
}

function bashAvailable(): bool
{
    return Process::run(['bash', '-c', 'true'])->successful();
}

it('el script tiene sintaxis valida', function () {
    if (! bashAvailable()) {
        $this->markTestSkipped('Necesita bash.');
    }

    foreach (['hestia/deploy.sh', 'backup/backup.sh', 'backup/restore.sh', 'github/protect-main.sh'] as $script) {
        $r = Process::run(['bash', '-n', base_path("deploy/$script")]);
        expect($r->successful())->toBeTrue("$script: ".$r->errorOutput());
    }
});

it('despliega en el orden: fetch, CI, compilar, down, volcado, codigo, migrate, up, smoke', function () {
    if (! bashAvailable()) {
        $this->markTestSkipped('Necesita bash.');
    }

    $steps = dryRunSteps();

    $order = ['fetch', 'ci', 'build', 'down', 'dump', 'code', 'swap', 'migrate', 'cache', 'queue', 'up', 'smoke'];
    $positions = array_map(fn ($tag) => array_search($tag, $steps, true), $order);

    expect($positions)->each->not->toBeFalse()
        ->and($positions)->toBe(collect($positions)->sort()->values()->all());
});

it('compilar y comprobar la CI van antes de artisan down y el volcado antes de migrate', function () {
    $sh = file_get_contents(base_path('deploy/hestia/deploy.sh'));

    // En la definicion de los pasos de despliegue (no en el rollback).
    $deploy = substr($sh, (int) strpos($sh, 'if [ "$MODE" = "deploy" ]'), (int) strpos($sh, 'print_steps()') - (int) strpos($sh, 'if [ "$MODE" = "deploy" ]'));

    expect(strpos($deploy, '"build|build_stage"'))->toBeLessThan(strpos($deploy, 'artisan down'))
        ->and(strpos($deploy, '"ci|check_ci"'))->toBeLessThan(strpos($deploy, '"build|build_stage"'))
        ->and(strpos($deploy, 'artisan down'))->toBeLessThan(strpos($deploy, 'backup.sh --db-only'))
        ->and(strpos($deploy, 'backup.sh --db-only'))->toBeLessThan(strpos($deploy, 'artisan migrate'))
        ->and(strpos($deploy, 'artisan migrate'))->toBeLessThan(strpos($deploy, 'artisan up'));

    expect($sh)->toContain('--force-unverified', 'check-runs', 'trap on_failure ERR', 'public/build/manifest.json', 'set -eEuo pipefail')
        ->and($sh)->not->toContain('reset --hard');
});

it('SKIP_BACKUP quita el volcado y --force-unverified no cambia el orden', function () {
    if (! bashAvailable()) {
        $this->markTestSkipped('Necesita bash.');
    }

    $result = Process::env(['SKIP_BACKUP' => '1'])->run(['bash', base_path('deploy/hestia/deploy.sh'), '--dry-run', '--force-unverified']);
    expect($result->successful())->toBeTrue()
        ->and($result->output())->not->toContain('[dump]')
        ->and($result->output())->toContain('[down]', '[migrate]');
});

it('el rollback se puede ensayar en seco y no hace nada', function () {
    if (! bashAvailable()) {
        $this->markTestSkipped('Necesita bash.');
    }

    $result = Process::run(['bash', base_path('deploy/hestia/deploy.sh'), 'rollback', '--dry-run']);
    preg_match_all('/^\s*\d+\. \[(\w+)\]/m', $result->output(), $m);

    expect($result->successful())->toBeTrue($result->errorOutput())
        ->and($result->output())->toContain('Nada se ha modificado')
        ->and(array_slice($m[1], 0, 2))->toBe(['down', 'code'])
        ->and($m[1])->toContain('up');
});
