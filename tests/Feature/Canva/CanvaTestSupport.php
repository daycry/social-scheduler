<?php

use App\Models\Brand;
use App\Models\Canva\CanvaConnection;
use App\Models\User;
use App\Services\Media\UrlGuard;
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
