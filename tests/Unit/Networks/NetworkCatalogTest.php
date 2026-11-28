<?php

use App\Connectors\ConnectorRegistry;
use App\Connectors\Network;
use Tests\Support\FakeConnector;
use Tests\TestCase;

uses(TestCase::class);

test('cada red del catalogo declara etiqueta, nombre corto, color hex, proveedor y forma de conexion', function () {
    foreach (Network::cases() as $network) {
        expect($network->label())->not->toBe('')
            ->and($network->shortLabel())->not->toBe('')
            ->and($network->color())->toMatch('/^#[0-9a-f]{6}$/i')
            ->and($network->provider())->not->toBe('')
            ->and([Network::AUTH_OAUTH, Network::AUTH_CREDENTIALS])->toContain($network->authKind());
    }
});

test('Bluesky, Mastodon y Telegram se conectan con credenciales y las demas por OAuth', function () {
    expect(Network::Bluesky->authKind())->toBe('credentials')
        ->and(Network::Bluesky->provider())->toBe('bluesky')
        ->and(Network::Mastodon->authKind())->toBe('credentials')->and(Network::Mastodon->provider())->toBe('mastodon')
        ->and(Network::Telegram->authKind())->toBe('credentials')->and(Network::Telegram->provider())->toBe('telegram');

    foreach ([Network::Instagram, Network::Facebook, Network::YouTube, Network::TikTok] as $network) {
        expect($network->authKind())->toBe('oauth');
    }
});

test('providers() se deriva de los casos, sin repetidos', function () {
    expect(Network::providers())->toBe(['meta', 'google', 'tiktok', 'bluesky', 'threads', 'linkedin', 'pinterest', 'mastodon', 'telegram']);
});

test('offered() solo incluye las redes con conector registrado', function () {
    $registry = new ConnectorRegistry;
    $registry->register(new FakeConnector('meta', [Network::Instagram, Network::Facebook]));
    app()->instance(ConnectorRegistry::class, $registry);

    expect(Network::offered())->toBe([Network::Instagram, Network::Facebook]);
});

test('listing() enumera las redes del catalogo para el MCP y la IA', function () {
    config(['social.bluesky.enabled' => true, 'social.threads.enabled' => false]);

    expect(Network::listing())->toBe('instagram, facebook, youtube, tiktok, bluesky, mastodon o telegram');

    config(['social.threads.enabled' => true, 'services.threads.client_id' => 'THAPP1']);

    expect(Network::listing())->toBe('instagram, facebook, youtube, tiktok, bluesky, threads, mastodon o telegram');

    config(['social.mastodon.enabled' => false, 'social.telegram.enabled' => false]);

    expect(Network::listing())->toBe('instagram, facebook, youtube, tiktok, bluesky o threads');
});

test('toArray() expone solo datos publicos', function () {
    expect(Network::Bluesky->toArray())->toBe([
        'value' => 'bluesky', 'label' => 'Bluesky', 'short_label' => 'BS',
        'color' => '#0a7aff', 'provider' => 'bluesky', 'auth_kind' => 'credentials',
    ]);
});
