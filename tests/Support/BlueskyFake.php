<?php

namespace Tests\Support;

use App\Connectors\Network;
use App\Models\Brand;
use App\Models\SocialAccount;
use App\Models\User;
use Illuminate\Support\Facades\Http;

/** Ayudas para los tests de Bluesky: JWT con `exp`, respuestas simuladas del entryway, el DID y el PDS, y cuentas listas. Sin red. */
final class BlueskyFake
{
    public const DID = 'did:plc:abcdefghijklmnopqrstuvwx';

    public const PDS = 'https://pds.example.com';

    /** JWT sin firma valida: solo importa el `exp` (la app lo lee sin verificar). */
    public static function jwt(int $expiresIn = 7200): string
    {
        $b64 = fn (array $a) => rtrim(strtr(base64_encode(json_encode($a)), '+/', '-_'), '=');

        return $b64(['alg' => 'ES256K']).'.'.$b64(['exp' => time() + $expiresIn, 'sub' => self::DID]).'.sig';
    }

    /** Simula resolveHandle, el documento DID y createSession. */
    public static function connect(array $over = []): void
    {
        Http::fake([
            'bsky.social/xrpc/com.atproto.identity.resolveHandle*' => Http::response(['did' => self::DID]),
            'plc.directory/'.self::DID => Http::response(['id' => self::DID, 'service' => [
                ['id' => '#atproto_pds', 'type' => 'AtprotoPersonalDataServer', 'serviceEndpoint' => self::PDS],
            ]]),
            'pds.example.com/xrpc/com.atproto.server.createSession' => Http::response($over['session'] ?? [
                'accessJwt' => self::jwt(), 'refreshJwt' => 'REFRESH-1', 'did' => self::DID, 'handle' => 'ana.example.com', 'emailConfirmed' => true,
            ]),
        ]);
    }

    public static function account(array $over = [], ?User $user = null): SocialAccount
    {
        $user ??= User::factory()->create();
        $brand = Brand::factory()->for($user)->create();

        return SocialAccount::factory()->for($user)->create(array_merge([
            'brand_id' => $brand->id,
            'network' => Network::Bluesky,
            'external_id' => self::DID,
            'name' => 'ana.example.com',
            'username' => 'ana.example.com',
            'access_token' => 'OLD-ACCESS',
            'refresh_token' => 'OLD-REFRESH',
            'credential_secret' => 'APP-PASS-WORD-1234',
            'token_expires_at' => now()->addMinutes(10),
            'refresh_expires_at' => null,
            'meta' => ['did' => self::DID, 'pds' => self::PDS, 'handle' => 'ana.example.com', 'email_confirmed' => true],
        ], $over));
    }
}
