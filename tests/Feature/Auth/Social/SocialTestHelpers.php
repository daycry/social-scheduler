<?php

use App\Models\User;
use App\Services\TwoFactor\TotpService;
use Laravel\Socialite\Facades\Socialite;
use Laravel\Socialite\Two\User as SocialiteUser;

/** Usuario devuelto por el proveedor: id estable, email y, para Google, email_verified del userinfo. */
function fakeSocialUser(string $id = 'g-123', ?string $email = null, bool|string|null $verified = null, string $name = 'Persona'): SocialiteUser
{
    $raw = ['sub' => $id, 'id' => $id, 'name' => $name];
    if ($email !== null) {
        $raw['email'] = $email;
    }
    if ($verified !== null) {
        $raw['email_verified'] = $verified;
    }

    return (new SocialiteUser)->setRaw($raw)->map(['id' => $id, 'name' => $name, 'email' => $email]);
}

/** Activa los dos proveedores de login con credenciales de prueba (no reales). */
function enableSocialProviders(): void
{
    config([
        'services.google_login.client_id' => 'test-google-id',
        'services.google_login.client_secret' => 'test-google-secret',
        'services.facebook_login.client_id' => 'test-meta-id',
        'services.facebook_login.client_secret' => 'test-meta-secret',
    ]);
}

/** El proveedor "devuelve" este usuario en el callback. */
function socialProviderReturns(string $provider, SocialiteUser $user): void
{
    Socialite::fake($provider.'-login', $user);
}

function socialUserWith2fa(?User $user = null): User
{
    $user ??= User::factory()->create();
    $user->forceFill(['two_factor_secret' => app(TotpService::class)->generateSecret(), 'two_factor_confirmed_at' => now()])->save();

    return $user->fresh();
}
