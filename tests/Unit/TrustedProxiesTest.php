<?php

use App\Support\TrustedProxies;

test('TrustedProxies parses the TRUSTED_PROXIES env value', function () {
    expect(TrustedProxies::parse(null))->toBeNull()
        ->and(TrustedProxies::parse(''))->toBeNull()
        ->and(TrustedProxies::parse(' , '))->toBeNull()
        ->and(TrustedProxies::parse('*'))->toBe('*')
        ->and(TrustedProxies::parse('10.0.0.1, 192.168.0.0/16'))->toBe(['10.0.0.1', '192.168.0.0/16']);
});
