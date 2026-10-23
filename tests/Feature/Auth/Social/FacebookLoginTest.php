<?php

use App\Models\AuditLog;
use App\Models\SocialIdentity;
use App\Models\User;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use Laravel\Socialite\Facades\Socialite;

require_once __DIR__.'/SocialTestHelpers.php';

beforeEach(fn () => enableSocialProviders());

/** Cliente Guzzle simulado que guarda las peticiones que recibe. */
function facebookHttp(array $responses, array &$history): Client
{
    $stack = HandlerStack::create(new MockHandler($responses));
    $stack->push(Middleware::history($history));

    return new Client(['handler' => $stack]);
}

test('la version de Graph sale de la config y no del driver, y se piden email y public_profile', function () {
    config(['social.meta.graph_version' => 'v99.0']);

    $url = Socialite::driver('facebook-login')->stateless()->redirect()->getTargetUrl();

    expect($url)->toContain('facebook.com/v99.0/dialog/oauth')
        ->and($url)->toContain('client_id=test-meta-id')
        ->and(urldecode($url))->toContain('scope=email,public_profile')
        ->and($url)->not->toContain('v23.0');
});

test('el payload crudo de Graph usa la version configurada, los campos pedidos y appsecret_proof', function () {
    config(['social.meta.graph_version' => 'v99.0']);
    $history = [];
    $client = facebookHttp([
        new Response(200, [], json_encode(['access_token' => 'tok-fb', 'token_type' => 'bearer'])),
        new Response(200, [], json_encode(['id' => 'fb-77', 'name' => 'Ana'])),
    ], $history);

    $user = Socialite::driver('facebook-login')->stateless()->setHttpClient($client)->user();

    expect($history)->toHaveCount(2);
    expect((string) $history[0]['request']->getUri())->toContain('/v99.0/oauth/access_token');
    $me = $history[1]['request']->getUri();
    expect($me->getPath())->toBe('/v99.0/me');
    parse_str($me->getQuery(), $q);
    expect($q['fields'])->toBe('id,name,email')
        ->and($q['appsecret_proof'])->toBe(hash_hmac('sha256', 'tok-fb', 'test-meta-secret'));
    expect($user->getId())->toBe('fb-77')->and($user->getEmail())->toBeNull();
});

test('sin email y sin identidad Facebook se rechaza sin crear nada', function () {
    User::factory()->create();
    $before = User::count();
    socialProviderReturns('facebook', fakeSocialUser('fb-1', null));

    $this->get('/auth/facebook/callback')->assertSessionHasErrors('email');

    $this->assertGuest();
    expect(SocialIdentity::count())->toBe(0)->and(User::count())->toBe($before);
    expect(AuditLog::where('action', 'auth.social.login')->sole()->detail)->toBe('provider=facebook;motivo=unlinked');
});

test('CA-05: Facebook nunca vincula solo, ni con email que coincide y llega como verificado', function () {
    User::factory()->create(['email' => 'ana@example.com']);
    socialProviderReturns('facebook', fakeSocialUser('fb-2', 'ana@example.com', true));

    $this->get('/auth/facebook/callback')->assertSessionHasErrors('email');

    $this->assertGuest();
    expect(SocialIdentity::count())->toBe(0);
});

test('la redireccion de vuelta de Facebook sustituye el fragmento heredado #_=_ por uno vacio', function () {
    $user = User::factory()->create();
    $user->socialIdentities()->create(['provider' => 'facebook', 'provider_user_id' => 'fb-3', 'linked_at' => now()]);
    socialProviderReturns('facebook', fakeSocialUser('fb-3'));

    $response = $this->get('/auth/facebook/callback?code=x&state=y');

    expect($response->headers->get('Location'))->toEndWith('/dashboard#');
});

test('Google no lleva fragmento', function () {
    $user = User::factory()->create();
    $user->socialIdentities()->create(['provider' => 'google', 'provider_user_id' => 'g-3', 'linked_at' => now()]);
    socialProviderReturns('google', fakeSocialUser('g-3', null));

    expect($this->get('/auth/google/callback')->headers->get('Location'))->toEndWith('/dashboard');
});
