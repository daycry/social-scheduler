<?php

use App\Models\Brand;
use App\Models\Canva\CanvaConnection;
use App\Models\User;
use App\Services\Media\UrlGuard;
use Firebase\JWT\JWT;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;

/** PNG real y pequeño generado con GD. */
function canvaPng(int $w = 64, int $h = 48): string
{
    $image = imagecreatetruecolor($w, $h);
    imagefill($image, 0, 0, imagecolorallocate($image, 124, 58, 237));
    ob_start();
    imagepng($image);

    return (string) ob_get_clean();
}

/** Entorno comun: credenciales ficticias, almacenamiento falso, DNS publico para el host de descargas de Canva y sin red. */
function canvaEnvironment(): void
{
    Storage::fake('local');
    config(['filesystems.media' => 'local', 'services.canva.client_id' => 'OC-test-client', 'services.canva.client_secret' => 'test-secret-value', 'services.canva.poll_delay' => 0]);
    Http::preventStrayRequests();
    app()->instance(UrlGuard::class, new UrlGuard(fn (string $host) => str_ends_with($host, 'canva.com') ? ['151.101.2.2'] : ['93.184.216.34']));
}

/** @return array{0: User, 1: Brand} */
function canvaUserAndBrand(): array
{
    $user = User::factory()->create();

    return [$user, Brand::factory()->for($user)->create()];
}

function canvaConnect(User $user, Brand $brand, array $scopes = [], array $capabilities = ['export_png_transparency'], ?DateTimeInterface $expires = null): CanvaConnection
{
    $connection = new CanvaConnection;
    $connection->forceFill([
        'user_id' => $user->id, 'brand_id' => $brand->id, 'access_token' => 'at-live', 'refresh_token' => 'rt-live', 'expires_at' => $expires ?? now()->addHours(3),
        'scopes' => $scopes ?: config('services.canva.scopes'), 'capabilities' => $capabilities, 'capabilities_at' => now(), 'display_name' => 'Ana en Canva', 'status' => 'active',
    ])->save();

    return $connection;
}

/**
 * Par de claves RSA generado en el propio test (nunca una clave real) y su JWK publico tal como lo serviria `/connect/keys`.
 *
 * @return array{pem: string, jwk: array<string, string>}
 */
function canvaKeyPair(string $kid = 'kid-1'): array
{
    // Configuracion minima propia: en Windows/Laragon OpenSSL puede no encontrar su `openssl.cnf` y `openssl_pkey_new` falla.
    $config = sys_get_temp_dir().DIRECTORY_SEPARATOR.'canva-test-openssl.cnf';
    file_put_contents($config, '[req]
distinguished_name = dn
[dn]
');
    $options = ['config' => $config, 'private_key_type' => OPENSSL_KEYTYPE_RSA, 'private_key_bits' => 2048];
    $key = openssl_pkey_new($options);
    openssl_pkey_export($key, $pem, null, $options);
    $rsa = openssl_pkey_get_details($key)['rsa'];
    $b64 = fn (string $raw) => rtrim(strtr(base64_encode($raw), '+/', '-_'), '=');

    return ['pem' => $pem, 'jwk' => ['kty' => 'RSA', 'use' => 'sig', 'kid' => $kid, 'n' => $b64($rsa['n']), 'e' => $b64($rsa['e'])]];
}

/** `correlation_jwt` firmado RS256 con la clave dada (reclamaciones validas por defecto; `$over` las cambia). */
function canvaSignedJwt(array $pair, string $state, array $over = []): string
{
    return JWT::encode($over + ['type' => 'rti', 'aud' => 'OC-test-client', 'exp' => now()->addDay()->timestamp, 'correlation_state' => $state], $pair['pem'], 'RS256', $pair['jwk']['kid']);
}

/** Publica un conjunto de claves en el endpoint de Canva (la respuesta de `Http::fake`). */
function canvaFakeKeys(array ...$pairs): void
{
    Http::fake(['api.canva.com/rest/v1/connect/keys' => Http::response(['keys' => array_map(fn ($p) => $p['jwk'], $pairs)])]);
}
