<?php

namespace Tests\Support;

use App\Connectors\Network;
use App\Models\Brand;
use App\Models\SocialAccount;
use App\Models\User;
use Illuminate\Support\Facades\Http;

/** Ayudas para los tests de Threads: configuracion de la app, cuentas listas y respuestas simuladas de graph.threads.com. Sin red. */
final class ThreadsFake
{
    public const USER_ID = '17841400000000001';

    /** Configura la app de Threads (ID y secreto) y la enciende. */
    public static function configure(): void
    {
        config([
            'services.threads.client_id' => 'THAPP1',
            'services.threads.client_secret' => 'THSECRET1',
            'social.threads.enabled' => true,
            'social.threads.auth_host' => 'https://threads.com',
            'social.threads.graph_host' => 'https://graph.threads.com',
        ]);
    }

    /** Cuenta de Threads activa, con token largo. */
    public static function account(array $over = [], ?User $user = null, ?Brand $brand = null): SocialAccount
    {
        self::configure();
        $user ??= User::factory()->create();
        $brand ??= Brand::factory()->for($user)->create();

        return SocialAccount::factory()->for($user)->create($over + [
            'brand_id' => $brand->id,
            'network' => Network::Threads,
            'external_id' => self::USER_ID,
            'name' => 'Ana Threads',
            'username' => 'ana.threads',
            'access_token' => 'TH-LONG-TOKEN',
            'refresh_token' => null,
            'token_expires_at' => now()->addDays(60),
            'scopes' => ['threads_basic', 'threads_content_publish', 'threads_manage_insights'],
        ]);
    }

    /** Simula el canje del codigo, el token largo y /me. */
    public static function connect(array $over = []): void
    {
        Http::fake(array_merge([
            'graph.threads.com/oauth/access_token*' => Http::response(['access_token' => 'TH-SHORT', 'token_type' => 'bearer', 'user_id' => (int) self::USER_ID]),
            'graph.threads.com/access_token*' => Http::response(['access_token' => 'TH-LONG-TOKEN', 'token_type' => 'bearer', 'expires_in' => 5183944]),
            'graph.threads.com/v1.0/me*' => Http::response(['id' => self::USER_ID, 'username' => 'ana.threads', 'name' => 'Ana Threads', 'threads_profile_picture_url' => 'https://cdn.example/p.jpg']),
        ], $over));
    }
}
