<?php

use App\Connectors\ConnectorRegistry;
use App\Connectors\Contracts\Connector;
use App\Connectors\Contracts\CredentialConnector;
use App\Connectors\Contracts\OAuthConnector;
use App\Connectors\Data\ConnectionCandidate;
use App\Connectors\Data\RefreshResult;
use App\Connectors\Network;
use App\Models\Brand;
use App\Models\SocialAccount;
use App\Models\User;
use App\Services\SocialAccountService;
use Illuminate\Support\Facades\DB;

function secretCandidate(): ConnectionCandidate
{
    return new ConnectionCandidate(
        network: Network::Instagram,
        externalId: 'did:plc:abc',
        name: 'Cuenta',
        username: 'cuenta.test',
        accessToken: 'ACCESS-JWT',
        refreshToken: 'REFRESH-JWT',
        secret: 'PLAIN-APP-PASSWORD',
    );
}

test('Meta, Google y TikTok son OAuthConnector y ninguno es CredentialConnector', function () {
    $registry = app(ConnectorRegistry::class);

    foreach (['meta', 'google', 'tiktok'] as $provider) {
        $connector = $registry->forProvider($provider);
        expect($connector)->toBeInstanceOf(OAuthConnector::class)
            ->and($connector)->toBeInstanceOf(Connector::class)
            ->and($connector)->not->toBeInstanceOf(CredentialConnector::class);
    }
});

test('credential_secret se guarda cifrado, oculto y se borra al desconectar', function () {
    $user = User::factory()->create();
    $brand = Brand::factory()->for($user)->create();
    $service = app(SocialAccountService::class);

    $account = $service->connect($user, $brand, secretCandidate());

    expect(DB::table('social_accounts')->first()->credential_secret)->not->toContain('PLAIN-APP-PASSWORD')
        ->and(SocialAccount::withoutUserScope()->first()->credential_secret)->toBe('PLAIN-APP-PASSWORD')
        ->and($account->toArray())->not->toHaveKey('credential_secret')
        ->and($account->toJson())->not->toContain('PLAIN-APP-PASSWORD');

    $service->disconnect($account);

    expect(DB::table('social_accounts')->first()->credential_secret)->toBeNull();
});

test('ConnectionCandidate::toPublicArray nunca incluye el secreto', function () {
    $public = secretCandidate()->toPublicArray();

    expect(json_encode($public))->not->toContain('PLAIN-APP-PASSWORD')->not->toContain('ACCESS-JWT')
        ->and($public)->not->toHaveKey('secret');
});

test('el flujo OAuth responde 404 para un proveedor que no es OAuthConnector', function () {
    $user = User::factory()->create();
    $brand = Brand::factory()->for($user)->create();
    $registry = app(ConnectorRegistry::class);
    $registry->register(new class implements CredentialConnector
    {
        public function provider(): string
        {
            return 'tiktok';
        }

        public function networks(): array
        {
            return [];
        }

        public function refresh(SocialAccount $account): RefreshResult
        {
            throw new LogicException;
        }

        public function refreshMarginHours(): int
        {
            return 1;
        }

        public function credentialFields(): array
        {
            return [];
        }

        public function connectWithCredentials(array $input): array
        {
            return [];
        }
    });

    $this->actingAs($user)->post(route('brands.connections.connect', [$brand, 'tiktok']))->assertNotFound();
    $this->actingAs($user)->get(route('connections.callback', 'tiktok'))->assertNotFound();
});
