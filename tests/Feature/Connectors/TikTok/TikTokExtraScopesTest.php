<?php

use App\Connectors\TikTok\TikTokConnector;
use Illuminate\Support\Arr;

beforeEach(fn () => config(['services.tiktok.client_key' => 'CK']));

function tiktokScopeParam(): string
{
    parse_str((string) parse_url((new TikTokConnector)->authorizationUrl('ST', 'https://app.test/cb'), PHP_URL_QUERY), $q);

    return $q['scope'];
}

test('sin TIKTOK_EXTRA_SCOPES se piden solo los tres scopes de siempre', function () {
    config(['social.tiktok.extra_scopes' => []]);

    expect(tiktokScopeParam())->toBe('user.info.basic,video.upload,video.publish');
});

test('los scopes extra se concatenan sin duplicar', function () {
    config(['social.tiktok.extra_scopes' => ['user.info.stats', 'video.list', 'video.upload']]);

    expect(tiktokScopeParam())->toBe('user.info.basic,video.upload,video.publish,user.info.stats,video.list');
});

test('la variable de entorno se normaliza: espacios fuera y valores invalidos descartados', function () {
    // env() lee de $_SERVER, $_ENV y getenv() (el primero que la tenga): si un .env cargado ya definio la variable (vacia, como
    // en .env.example de la CI), putenv() solo no basta. Se fija en las tres fuentes y se restaura, para no depender del entorno.
    $name = 'TIKTOK_EXTRA_SCOPES';
    $previous = [array_key_exists($name, $_SERVER) ? $_SERVER[$name] : null, array_key_exists($name, $_ENV) ? $_ENV[$name] : null, getenv($name)];
    $value = ' user.info.stats , video.list,,Bad Scope,x;y ';
    $_SERVER[$name] = $_ENV[$name] = $value;
    putenv($name.'='.$value);

    try {
        $config = require base_path('config/social.php');
    } finally {
        [$server, $env, $process] = $previous;
        $server === null ? Arr::forget($_SERVER, $name) : $_SERVER[$name] = $server;
        $env === null ? Arr::forget($_ENV, $name) : $_ENV[$name] = $env;
        $process === false ? putenv($name) : putenv($name.'='.$process);
    }

    expect($config['tiktok']['extra_scopes'])->toBe(['user.info.stats', 'video.list']);
});
