<?php

use App\Connectors\Google\GoogleConnector;
use App\Connectors\Google\YouTubePublisher;
use App\Connectors\Network;
use App\Models\Brand;
use App\Models\SocialAccount;
use App\Models\User;

test('YouTube scopes keep the publishing, read and analytics permissions', function () {
    expect(config('social.google.scopes'))
        ->toContain('https://www.googleapis.com/auth/youtube')
        ->toContain('https://www.googleapis.com/auth/youtube.readonly')
        ->toContain('https://www.googleapis.com/auth/yt-analytics.readonly');
});

test('YouTube authorization requests the force-ssl scope needed to comment', function () {
    expect(config('social.google.scopes'))->toContain('https://www.googleapis.com/auth/youtube.force-ssl');

    $url = (new GoogleConnector)->authorizationUrl('ST', 'https://app.test/cb');

    expect($url)->toContain(urlencode('https://www.googleapis.com/auth/youtube.force-ssl'));
});

test('a YouTube account without force-ssl cannot comment but keeps publishing scopes', function () {
    $user = User::factory()->create();
    $brand = Brand::factory()->for($user)->create();

    $old = SocialAccount::factory()->for($user)->create([
        'brand_id' => $brand->id, 'network' => Network::YouTube,
        'scopes' => ['https://www.googleapis.com/auth/youtube'],
    ]);
    $new = SocialAccount::factory()->for($user)->create([
        'brand_id' => $brand->id, 'network' => Network::YouTube,
        'scopes' => ['https://www.googleapis.com/auth/youtube', 'https://www.googleapis.com/auth/youtube.force-ssl'],
    ]);

    expect(YouTubePublisher::canComment($old))->toBeFalse()
        ->and(YouTubePublisher::canComment($new))->toBeTrue();
});
