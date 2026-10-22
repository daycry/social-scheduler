<?php

use App\Models\User;
use App\Services\Auth\LoginFlow;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Route;
use Illuminate\Testing\TestResponse;

/** Ejecuta LoginFlow::complete dentro de una peticion con sesion real. */
function runLoginFlow(User $user, bool $remember): TestResponse
{
    Route::middleware('web')->get('/_test/login-flow', function (Request $request) use ($user, $remember) {
        return app(LoginFlow::class)->complete($request, $user, $remember);
    });

    return test()->get('/_test/login-flow');
}

test('sin 2FA abre la sesion y va a intended', function () {
    $user = User::factory()->create();

    runLoginFlow($user, false)->assertRedirect(route('dashboard', absolute: false));

    $this->assertAuthenticatedAs($user);
});

test('sin 2FA con remember crea la cookie recaller', function () {
    $user = User::factory()->create();

    $response = runLoginFlow($user, true);

    $this->assertAuthenticatedAs($user);
    $recaller = collect($response->headers->getCookies())->first(fn ($c) => str_starts_with($c->getName(), 'remember_web_'));
    expect($recaller)->not->toBeNull()->and($recaller->getValue())->not->toBe('');
});

test('con 2FA no autentica, deja login.* y redirige al reto', function (bool $remember) {
    $user = User::factory()->create();
    $user->forceFill(['two_factor_secret' => 'SECRETSECRET', 'two_factor_confirmed_at' => now()])->save();

    runLoginFlow($user->fresh(), $remember)->assertRedirect(route('two-factor.challenge'));

    $this->assertGuest();
    expect(Auth::check())->toBeFalse();
    $this->assertSame($user->getKey(), session('login.id'));
    $this->assertSame($remember, session('login.remember'));
    $this->assertNotNull(session('login.at'));
})->with([true, false]);
