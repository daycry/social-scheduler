<?php

use App\Connectors\ConnectorRegistry;
use App\Connectors\Contracts\StatusPoller;
use App\Connectors\LinkedIn\LinkedInConnector;
use App\Connectors\Network;
use App\Connectors\Pinterest\PinterestConnector;
use App\Enums\ContentType;
use App\Services\MediaValidator;
use Tests\Support\NewNetworksFake;

// T-18: LinkedIn y Pinterest solo se registran con su app configurada; configuradas, tienen todas sus superficies.

test('sin credenciales ninguna de las dos redes se registra ni se ofrece', function () {
    config(['services.linkedin.client_id' => '', 'services.pinterest.client_id' => '']);
    app()->forgetInstance(ConnectorRegistry::class);

    $registry = app(ConnectorRegistry::class);
    expect($registry->has('linkedin'))->toBeFalse()->and($registry->has('pinterest'))->toBeFalse()
        ->and($registry->publisherFor(Network::LinkedIn))->toBeNull()->and($registry->publisherFor(Network::Pinterest))->toBeNull()
        ->and(Network::offered())->not->toContain(Network::LinkedIn)->not->toContain(Network::Pinterest)
        ->and(ContentType::LinkedInText->isOffered())->toBeFalse()->and(ContentType::PinterestPin->isOffered())->toBeFalse();
});

test('con credenciales se registran conector y publicador, solo LinkedIn comenta y solo Pinterest sondea', function () {
    NewNetworksFake::linkedin();
    NewNetworksFake::pinterest();
    app()->forgetInstance(ConnectorRegistry::class);

    $registry = app(ConnectorRegistry::class);
    expect($registry->forNetwork(Network::LinkedIn))->toBeInstanceOf(LinkedInConnector::class)
        ->and($registry->forNetwork(Network::Pinterest))->toBeInstanceOf(PinterestConnector::class)
        ->and($registry->commenterFor(Network::LinkedIn))->not->toBeNull()->and($registry->commenterFor(Network::Pinterest))->toBeNull()
        ->and($registry->publisherFor(Network::Pinterest))->toBeInstanceOf(StatusPoller::class)
        ->and($registry->publisherFor(Network::LinkedIn))->not->toBeInstanceOf(StatusPoller::class)
        ->and(Network::offered())->toContain(Network::LinkedIn)->toContain(Network::Pinterest)
        ->and(Network::LinkedIn->authKind())->toBe('oauth')->and(Network::Pinterest->provider())->toBe('pinterest');
});

test('cada tipo de las redes nuevas tiene su regla de media y limite de texto', function () {
    foreach (ContentType::forNetwork(Network::LinkedIn) as $type) {
        expect(config("media_specs.types.{$type->value}.text.max"))->toBe(3000);
    }
    foreach (ContentType::forNetwork(Network::Pinterest) as $type) {
        expect(config("media_specs.types.{$type->value}.text.max"))->toBe(800)->and(config("media_specs.types.{$type->value}.first_comment"))->toBeNull();
    }
});

test('el validador de media aplica los limites de LinkedIn y Pinterest', function () {
    $validator = app(MediaValidator::class);
    $long = str_repeat('a', 3001);

    expect($validator->validate(ContentType::LinkedInText, [], $long))->not->toBeEmpty()
        ->and($validator->validate(ContentType::LinkedInText, [], 'ok'))->toBe([])
        ->and($validator->validate(ContentType::PinterestPin, [], 'sin imagen'))->not->toBeEmpty();
});
