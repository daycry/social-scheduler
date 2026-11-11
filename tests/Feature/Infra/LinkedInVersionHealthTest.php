<?php

use App\Support\HealthCheck;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;

beforeEach(fn () => Cache::put(HealthCheck::HEARTBEAT_KEY, now()->timestamp));

function liCheck(string $version, string $today): array
{
    config(['social.linkedin.api_version' => $version]);

    return (new HealthCheck)->linkedinVersion(Carbon::parse($today));
}

test('una version reciente de LinkedIn no avisa', function () {
    $r = liCheck('202610', '2026-11-11');

    expect($r['ok'])->toBeTrue()->and($r)->not->toHaveKey('warn')->and($r['detail'])->toContain('202610');
});

test('con 10 meses avisa y con 12 la da por retirada', function () {
    expect(liCheck('202601', '2026-10-30'))->not->toHaveKey('warn')     // 9 meses
        ->and(liCheck('202601', '2026-11-01')['warn'])->toBeTrue()      // 10 meses
        ->and(liCheck('202511', '2026-11-11')['detail'])->toContain('retirada');
});

test('un valor sin formato AAAAMM avisa', function () {
    expect(liCheck('v1', '2026-11-11'))->toMatchArray(['warn' => true]);
});

test('el valor por defecto esta vigente y el aviso entra en el estado de app:health', function () {
    $defaults = require base_path('config/social.php');
    expect($defaults['linkedin']['api_version'])->toBe('202610');

    config(['social.linkedin.api_version' => '202401', 'health.native' => false]);
    $run = (new HealthCheck)->run();
    expect($run['checks']['linkedin_version']['warn'])->toBeTrue()->and($run['status'])->toBe('degraded');

    config(['social.linkedin.enabled' => false]);
    expect((new HealthCheck)->run()['checks'])->not->toHaveKey('linkedin_version');
});
