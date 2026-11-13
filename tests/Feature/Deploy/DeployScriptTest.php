<?php

use Illuminate\Process\ProcessResult;
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

/**
 * Ensayo completo con php, composer, npm y gh falsos y un origin git local: sin VPS, sin servicios reales.
 *
 * @return array{root: string, app: string, env: array<string, string>}
 */
function deploySandbox(): array
{
    $root = str_replace('\\', '/', sys_get_temp_dir()).'/deploy-rt-'.uniqid();
    $app = "$root/app";
    $git = fn (string $dir, array $args) => Process::path($dir)->run(['git', '-c', 'user.name=t', '-c', 'user.email=t@t', '-c', 'core.autocrlf=false', ...$args])->throw();

    File::makeDirectory($root.'/bin', 0777, true);
    $git($root, ['init', '-q', '-b', 'main', '--bare', 'origin.git']);
    $git($root, ['clone', '-q', 'origin.git', 'app']);
    File::makeDirectory("$app/storage/logs", 0777, true);
    file_put_contents("$app/storage/logs/.gitignore", "*\n");
    file_put_contents("$app/.gitignore", ".env\n/vendor\n/public/build\n/bootstrap/cache/*.php\n/storage/framework/down\n");
    file_put_contents("$app/f.txt", "uno\n");
    file_put_contents("$app/composer.json", '{}');
    file_put_contents("$app/package.json", '{}');
    $git($app, ['add', '-A']);
    $git($app, ['commit', '-q', '-m', 'uno']);
    $git($app, ['push', '-q', 'origin', 'main']);
    file_put_contents("$app/.env", "APP_KEY=x\n");
    File::makeDirectory("$app/vendor");
    File::makeDirectory("$app/public/build", 0777, true);
    File::makeDirectory("$app/bootstrap/cache", 0777, true);
    file_put_contents("$app/vendor/marker", 'v1');
    file_put_contents("$app/public/build/manifest.json", '{}');
    file_put_contents("$app/public/build/old.txt", 'old');

    $git($root, ['clone', '-q', 'origin.git', 'other']);
    file_put_contents("$root/other/f.txt", "dos\n");
    $git("$root/other", ['commit', '-q', '-am', 'dos']);
    $git("$root/other", ['push', '-q', 'origin', 'main']);

    // php falso: composer instala un vendor con la marca FAKE_VENDOR; con `broken` ninguna orden artisan arranca;
    // package:discover falla si queda un bootstrap/cache/packages.php obsoleto; migrate falla con FAIL_MIGRATE=1.
    file_put_contents("$root/bin/php", <<<'SH'
#!/bin/sh
echo "php $*" >> "$LOG"
case "$*" in
  *composer*install*) mkdir -p vendor; printf '%s' "${FAKE_VENDOR:-v2}" > vendor/marker; exit 0 ;;
  *artisan*) grep -q broken vendor/marker 2>/dev/null && { echo "version rota" >&2; exit 1; } ;;
esac
case "$*" in
  *package:discover*) [ -f bootstrap/cache/packages.php ] && { echo "bootstrap/cache obsoleto" >&2; exit 1; } ;;
  *migrate*) [ "${FAIL_MIGRATE:-0}" = 1 ] && exit 1 ;;
esac
exit 0
SH);
    file_put_contents("$root/bin/composer", "#!/bin/sh\nexit 0\n");
    file_put_contents("$root/bin/npm", <<<'SH'
#!/bin/sh
[ "$1" = run ] && { mkdir -p public/build; echo '{}' > public/build/manifest.json; echo new > public/build/new.txt; }
exit 0
SH);
    file_put_contents("$root/bin/gh", "#!/bin/sh\necho \"\${GH_STATE:-success}\"\n");
    foreach (['php', 'composer', 'npm', 'gh'] as $bin) {
        chmod("$root/bin/$bin", 0755);
    }

    return ['root' => $root, 'app' => $app, 'env' => [
        'APP_DIR' => $app, 'LOG' => "$root/log", 'SKIP_BACKUP' => '1',
        'PATH' => "$root/bin".PATH_SEPARATOR.getenv('PATH'),
    ]];
}

function runDeploy(array $sandbox, array $extraEnv = [], string ...$args): ProcessResult
{
    return Process::path($sandbox['app'])->env($extraEnv + $sandbox['env'])->timeout(120)->run(['bash', base_path('deploy/hestia/deploy.sh'), ...$args]);
}

function appHead(array $sandbox): string
{
    return trim(Process::path($sandbox['app'])->run(['git', 'log', '-1', '--format=%s'])->output());
}

it('un despliegue correcto limpia bootstrap/cache antes de package:discover y se niega con la CI en rojo', function () {
    if (! bashAvailable()) {
        $this->markTestSkipped('Necesita bash.');
    }
    $sb = deploySandbox();
    file_put_contents("{$sb['app']}/bootstrap/cache/packages.php", '<?php // obsoleto');

    $red = runDeploy($sb, ['GH_STATE' => 'failure']);
    expect($red->successful())->toBeFalse()
        ->and(appHead($sb))->toBe('uno')
        ->and(file_get_contents("{$sb['app']}/vendor/marker"))->toBe('v1');

    $ok = runDeploy($sb);
    expect($ok->successful())->toBeTrue($ok->errorOutput())
        ->and(appHead($sb))->toBe('dos')
        ->and(file_get_contents("{$sb['app']}/vendor/marker"))->toBe('v2')
        ->and(file_exists("{$sb['app']}/public/build/new.txt"))->toBeTrue()
        ->and(file_exists("{$sb['app']}/storage/framework/down"))->toBeFalse();

    File::deleteDirectory($sb['root']);
});

it('el rollback pone el modo mantenimiento sin arrancar Laravel y funciona con una version rota', function () {
    if (! bashAvailable()) {
        $this->markTestSkipped('Necesita bash.');
    }
    $sb = deploySandbox();

    // Version nueva rota: ningun artisan arranca tras el cambio de vendor.
    $bad = runDeploy($sb, ['FAKE_VENDOR' => 'broken']);
    expect($bad->successful())->toBeFalse()->and(appHead($sb))->toBe('dos');

    file_put_contents("{$sb['app']}/bootstrap/cache/packages.php", '<?php // obsoleto');
    file_put_contents($sb['root'].'/log', '');
    $rollback = runDeploy($sb, [], 'rollback');

    expect($rollback->successful())->toBeTrue($rollback->errorOutput())
        ->and(appHead($sb))->toBe('uno')
        ->and(file_get_contents("{$sb['app']}/vendor/marker"))->toBe('v1')
        ->and(file_exists("{$sb['app']}/public/build/old.txt"))->toBeTrue()
        ->and(file_exists("{$sb['app']}/storage/framework/down"))->toBeFalse()
        // Ni down ni up pasan por php en el rollback: no dependen de que la version rota arranque.
        ->and(file_get_contents($sb['root'].'/log'))->not->toContain('artisan down')->not->toContain('artisan up');

    File::deleteDirectory($sb['root']);
});

it('reejecutar el despliegue tras un fallo conserva el punto de vuelta original', function () {
    if (! bashAvailable()) {
        $this->markTestSkipped('Necesita bash.');
    }
    $sb = deploySandbox();

    $first = runDeploy($sb, ['FAIL_MIGRATE' => '1']);
    expect($first->successful())->toBeFalse();
    expect(file_get_contents("{$sb['app']}/storage/logs/.deploy/last-deploy"))->toContain('status=incomplete');

    $second = runDeploy($sb, ['FAIL_MIGRATE' => '1']);
    expect($second->successful())->toBeFalse()
        ->and(file_get_contents("{$sb['app']}/storage/logs/.deploy/last-deploy"))->toContain('prev_rev='.trim(Process::path($sb['app'])->run(['git', 'rev-parse', 'HEAD~1'])->output()));

    $rollback = runDeploy($sb, [], 'rollback');
    expect($rollback->successful())->toBeTrue($rollback->errorOutput())
        ->and(appHead($sb))->toBe('uno')
        ->and(file_get_contents("{$sb['app']}/vendor/marker"))->toBe('v1')
        ->and(file_exists("{$sb['app']}/public/build/old.txt"))->toBeTrue()
        ->and(file_exists("{$sb['app']}/public/build/new.txt"))->toBeFalse();

    File::deleteDirectory($sb['root']);
});
