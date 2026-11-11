<?php

namespace Tests\Support;

use App\Connectors\Network;
use App\Models\Brand;
use App\Models\SocialAccount;
use App\Models\User;

/** Configuracion y cuentas listas para los tests de LinkedIn y Pinterest (T-18). Todo con Http::fake: nada llega a un proveedor. */
final class NewNetworksFake
{
    public static function linkedin(): void
    {
        config([
            'services.linkedin.client_id' => 'LIAPP1', 'services.linkedin.client_secret' => 'LISECRET1', 'social.linkedin.enabled' => true,
            'social.linkedin.auth_host' => 'https://www.linkedin.com', 'social.linkedin.api_host' => 'https://api.linkedin.com',
        ]);
    }

    public static function pinterest(): void
    {
        config([
            'services.pinterest.client_id' => 'PIAPP1', 'services.pinterest.client_secret' => 'PISECRET1', 'social.pinterest.enabled' => true,
            'social.pinterest.auth_host' => 'https://www.pinterest.com', 'social.pinterest.api_host' => 'https://api.pinterest.com',
        ]);
    }

    public static function linkedinAccount(array $over = [], string $kind = 'profile'): SocialAccount
    {
        self::linkedin();
        $user = User::factory()->create();
        $brand = Brand::factory()->for($user)->create();

        return SocialAccount::factory()->for($user)->create($over + [
            'brand_id' => $brand->id, 'network' => Network::LinkedIn, 'external_id' => $kind === 'page' ? 'org-777' : 'abc123', 'name' => 'Ana LinkedIn',
            'access_token' => 'LI-TOKEN', 'refresh_token' => null, 'token_expires_at' => now()->addDays(60), 'scopes' => ['w_member_social', 'r_member_social'],
            'meta' => ['kind' => $kind, 'author_urn' => $kind === 'page' ? 'urn:li:organization:777' : 'urn:li:person:abc123'],
        ]);
    }

    public static function pinterestAccount(array $over = []): SocialAccount
    {
        self::pinterest();
        $user = User::factory()->create();
        $brand = Brand::factory()->for($user)->create();

        return SocialAccount::factory()->for($user)->create($over + [
            'brand_id' => $brand->id, 'network' => Network::Pinterest, 'external_id' => 'pin-user-1', 'name' => 'ana_pins', 'username' => 'ana_pins',
            'access_token' => 'PI-TOKEN', 'refresh_token' => 'PI-REFRESH', 'token_expires_at' => now()->addDays(30), 'scopes' => ['pins:write'],
            'meta' => ['boards' => [['id' => '549755885175', 'name' => 'Recetas']]],
        ]);
    }
}
