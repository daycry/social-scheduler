<?php

use App\Connectors\TikTok\TikTokConnector;

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
    putenv('TIKTOK_EXTRA_SCOPES= user.info.stats , video.list,,Bad Scope,x;y ');
    $config = require base_path('config/social.php');
    putenv('TIKTOK_EXTRA_SCOPES');

    expect($config['tiktok']['extra_scopes'])->toBe(['user.info.stats', 'video.list']);
});
