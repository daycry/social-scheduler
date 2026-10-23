<?php

use App\Models\User;
use App\Providers\AppServiceProvider;
use App\Services\Auth\Social\FakeSocialiteProvider;
use Illuminate\Http\Request;
use Laravel\Socialite\Facades\Socialite;
use Laravel\Socialite\Two\FacebookProvider;
use Laravel\Socialite\Two\GoogleProvider;

require_once __DIR__.'/Social/SocialTestHelpers.php';

function driverClasses(string $env, string $url): array
{
    app()->detectEnvironment(fn () => $env);
    config(['app.url' => $url]);
    enableSocialProviders();
    // Estado limpio: los drivers ya resueltos y los creadores propios del arranque.
    Socialite::forgetDrivers();
    (new AppServiceProvider(app()))->boot();

    return [
        get_class(Socialite::driver('google-login')),
        get_class(Socialite::driver('facebook-login')),
    ];
}

// Con un entorno distinto de `testing`, TrustHosts fija los hosts de confianza en el estado ESTATICO de Symfony: se limpia para no
// contaminar a los tests siguientes (p. ej. los de proxies de confianza, que usan otro host).
afterEach(fn () => Request::setTrustedHosts([]));

test('el driver falso NO existe en production, local ni testing', function (string $env) {
    [$google, $facebook] = driverClasses($env, 'http://localhost:8080');

    expect($google)->toBe(GoogleProvider::class)->and($facebook)->toBe(FacebookProvider::class);
})->with(['production', 'local', 'testing', 'staging']);

test('el driver falso solo se registra con APP_ENV=e2e', function () {
    [$google, $facebook] = driverClasses('e2e', 'http://localhost:8080');

    expect($google)->toBe(FakeSocialiteProvider::class)->and($facebook)->toBe(FakeSocialiteProvider::class);
});

test('con APP_ENV=e2e pero un APP_URL publico tampoco se registra (segunda barrera)', function () {
    [$google] = driverClasses('e2e', 'https://app.example.com');

    expect($google)->toBe(GoogleProvider::class);
});

test('allowed() solo acepta e2e con host local', function () {
    expect(FakeSocialiteProvider::allowed('e2e', 'http://localhost:8080'))->toBeTrue()
        ->and(FakeSocialiteProvider::allowed('e2e', 'http://127.0.0.1:8080'))->toBeTrue()
        ->and(FakeSocialiteProvider::allowed('production', 'http://localhost'))->toBeFalse()
        ->and(FakeSocialiteProvider::allowed('e2e', 'https://localhost.evil.com'))->toBeFalse()
        ->and(FakeSocialiteProvider::allowed('e2e', ''))->toBeFalse();
});

test('el driver falso no se activa por peticion ni por query', function () {
    enableSocialProviders();

    foreach (['?driver=fake', '?APP_ENV=e2e', '?fake_email=a@b.test&fake_id=1'] as $query) {
        $this->get('/auth/google/redirect'.$query)->assertRedirectContains('accounts.google.com');
    }
    expect(get_class(Socialite::driver('google-login')))->toBe(GoogleProvider::class);
});

test('en e2e el viaje usa el state real y vuelve al callback con la identidad preparada', function () {
    app()->detectEnvironment(fn () => 'e2e');
    config(['app.url' => 'http://localhost:8080']);
    enableSocialProviders();
    FakeSocialiteProvider::register();
    Socialite::forgetDrivers();

    $path = FakeSocialiteProvider::identityFile();
    $backup = is_file($path) ? file_get_contents($path) : null;
    file_put_contents($path, json_encode(['google' => ['id' => 'g-e2e', 'email' => 'x@example.test', 'email_verified' => true]]));

    try {
        $location = $this->get('/auth/google/redirect')->assertRedirect()->headers->get('Location');
        expect($location)->toContain('/auth/google/callback?')->toContain('state=');
        parse_str((string) parse_url($location, PHP_URL_QUERY), $q);

        // El state de la sesion se valida: uno inventado cae en "sesion caducada", sin sesion iniciada.
        $this->get('/auth/google/callback?code=x&state=inventado')->assertRedirect('/login')->assertSessionHasErrors('email');
        $this->assertGuest();

        // Con el state de la sesion, la identidad preparada entra por la vinculacion existente.
        $user = User::factory()->create();
        $user->socialIdentities()->create(['provider' => 'google', 'provider_user_id' => 'g-e2e', 'linked_at' => now()]);
        $location = $this->get('/auth/google/redirect')->headers->get('Location');
        parse_str((string) parse_url($location, PHP_URL_QUERY), $q);
        Socialite::forgetDrivers(); // en un servidor real cada peticion crea su driver
        $r = $this->get('/auth/google/callback?code='.$q['code'].'&state='.$q['state']);
        $r->assertRedirect();
        $this->assertAuthenticatedAs($user);
    } finally {
        $backup === null ? @unlink($path) : file_put_contents($path, $backup);
    }
});
