<?php

use App\Models\User;
use App\Providers\AppServiceProvider;
use App\Services\Auth\Social\FakeSocialiteProvider;
use Illuminate\Http\Request;
use Laravel\Socialite\Facades\Socialite;
use Laravel\Socialite\Two\FacebookProvider;
use Laravel\Socialite\Two\GoogleProvider;

require_once __DIR__.'/Social/SocialTestHelpers.php';

/** Fija APP_URL en el entorno de proceso (lo que lee env()); `null` lo quita, como en un servidor donde nunca se definio. */
function setEnvAppUrl(?string $url): void
{
    if ($url === null) {
        putenv('APP_URL');
        unset($_ENV['APP_URL'], $_SERVER['APP_URL']);

        return;
    }
    putenv('APP_URL='.$url);
    $_ENV['APP_URL'] = $_SERVER['APP_URL'] = $url;
}

function driverClasses(string $env, string $url, ?string $envAppUrl = 'explicit'): array
{
    setEnvAppUrl($envAppUrl === 'explicit' ? $url : $envAppUrl);
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
$originalAppUrl = $_ENV['APP_URL'] ?? $_SERVER['APP_URL'] ?? (getenv('APP_URL') ?: null);
afterEach(function () use ($originalAppUrl) {
    Request::setTrustedHosts([]);
    setEnvAppUrl($originalAppUrl);
});

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

test('con APP_ENV=e2e y SIN APP_URL explicita (config usa http://localhost) se usa el driver real', function () {
    [$google, $facebook] = driverClasses('e2e', 'http://localhost', null);

    expect($google)->toBe(GoogleProvider::class)->and($facebook)->toBe(FacebookProvider::class);
});

test('allowed() exige tambien que el Host de la peticion sea local', function () {
    expect(FakeSocialiteProvider::allowed('e2e', 'http://localhost:8080', 'localhost', true))->toBeTrue()
        ->and(FakeSocialiteProvider::allowed('e2e', 'http://localhost:8080', 'app.example.com', true))->toBeFalse()
        ->and(FakeSocialiteProvider::allowed('e2e', 'http://localhost:8080', null, false))->toBeFalse();
});

test('allowed() solo acepta e2e con host local', function () {
    expect(FakeSocialiteProvider::allowed('e2e', 'http://localhost:8080', null, true))->toBeTrue()
        ->and(FakeSocialiteProvider::allowed('e2e', 'http://127.0.0.1:8080', null, true))->toBeTrue()
        ->and(FakeSocialiteProvider::allowed('production', 'http://localhost', null, true))->toBeFalse()
        ->and(FakeSocialiteProvider::allowed('e2e', 'https://localhost.evil.com', null, true))->toBeFalse()
        ->and(FakeSocialiteProvider::allowed('e2e', '', null, true))->toBeFalse();
});

test('con e2e activo y sin fichero de identidad, la query no inicia sesion ni se lee', function () {
    [$google] = driverClasses('e2e', 'http://localhost:8080');
    expect($google)->toBe(FakeSocialiteProvider::class);
    Socialite::forgetDrivers(); // el driver de cada peticion se crea con SU request (con sesion)

    $path = FakeSocialiteProvider::identityFile();
    $backup = is_file($path) ? file_get_contents($path) : null;
    @unlink($path);

    try {
        foreach (['?driver=fake', '?APP_ENV=e2e', '?fake_email=a@b.test&fake_id=1'] as $query) {
            // El redirect falso vuelve al callback con un code; la query de la peticion no se refleja en el viaje.
            $location = $this->get('http://localhost:8080/auth/google/redirect'.$query)->assertRedirect()->headers->get('Location');
            expect($location)->not->toContain('fake_email')->not->toContain('a@b.test');
            parse_str((string) parse_url($location, PHP_URL_QUERY), $q);

            Socialite::forgetDrivers();
            // Sin identidad preparada el callback no puede autenticar a nadie, ni aunque la query traiga fake_email.
            $this->get('http://localhost:8080/auth/google/callback?code='.($q['code'] ?? 'x').'&state='.($q['state'] ?? 'x').'&fake_email=a@b.test&fake_id=1');
            $this->assertGuest();
            $this->assertDatabaseMissing('users', ['email' => 'a@b.test']);
        }
    } finally {
        if ($backup !== null) {
            file_put_contents($path, $backup);
        }
    }
});

test('en e2e el viaje usa el state real y vuelve al callback con la identidad preparada', function () {
    setEnvAppUrl('http://localhost:8080');
    app()->detectEnvironment(fn () => 'e2e');
    config(['app.url' => 'http://localhost:8080']);
    enableSocialProviders();
    FakeSocialiteProvider::register();
    Socialite::forgetDrivers();

    $path = FakeSocialiteProvider::identityFile();
    $backup = is_file($path) ? file_get_contents($path) : null;
    file_put_contents($path, json_encode(['google' => ['id' => 'g-e2e', 'email' => 'x@example.test', 'email_verified' => true]]));

    try {
        $location = $this->get('http://localhost:8080/auth/google/redirect')->assertRedirect()->headers->get('Location');
        expect($location)->toContain('/auth/google/callback?')->toContain('state=');
        parse_str((string) parse_url($location, PHP_URL_QUERY), $q);

        // El state de la sesion se valida: uno inventado cae en "sesion caducada", sin sesion iniciada.
        $this->get('http://localhost:8080/auth/google/callback?code=x&state=inventado')->assertRedirect('/login')->assertSessionHasErrors('email');
        $this->assertGuest();

        // Con el state de la sesion, la identidad preparada entra por la vinculacion existente.
        $user = User::factory()->create();
        $user->socialIdentities()->create(['provider' => 'google', 'provider_user_id' => 'g-e2e', 'linked_at' => now()]);
        $location = $this->get('http://localhost:8080/auth/google/redirect')->headers->get('Location');
        parse_str((string) parse_url($location, PHP_URL_QUERY), $q);
        Socialite::forgetDrivers(); // en un servidor real cada peticion crea su driver
        $r = $this->get('http://localhost:8080/auth/google/callback?code='.$q['code'].'&state='.$q['state']);
        $r->assertRedirect();
        $this->assertAuthenticatedAs($user);
    } finally {
        $backup === null ? @unlink($path) : file_put_contents($path, $backup);
    }
});
