<?php

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

pest()->extend(TestCase::class)
    ->use(RefreshDatabase::class)
    ->beforeEach(fn () => $this->withoutVite())
    ->afterEach(fn () => gc_collect_cycles())
    ->in('Feature');

/** Genera un par EC P-256 de prueba, configura VAPID con el y devuelve las claves de una suscripcion de navegador. */
function pushTestKeys(): array
{
    $config = storage_path('push-fixture-openssl.cnf');
    file_put_contents($config, "[req]\ndistinguished_name = dn\n[dn]\nCN = push-fixture\n");
    $key = openssl_pkey_new(['config' => $config, 'private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_EC, 'curve_name' => 'prime256v1']);
    $ec = openssl_pkey_get_details($key)['ec'];
    unlink($config);
    foreach (['x', 'y', 'd'] as $coordinate) {
        $ec[$coordinate] = str_pad($ec[$coordinate], 32, "\x00", STR_PAD_LEFT);
    }
    $encode = fn (string $bytes) => rtrim(strtr(base64_encode($bytes), '+/', '-_'), '=');
    config()->set([
        'push.enabled' => true, 'push.vapid.subject' => 'mailto:operator@example.test',
        'push.vapid.public_key' => $encode("\x04".$ec['x'].$ec['y']), 'push.vapid.private_key' => $encode($ec['d']),
    ]);

    return ['p256dh' => $encode("\x04".$ec['x'].$ec['y']), 'auth' => $encode(str_repeat("\x02", 16))];
}
