<?php

use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;

test('el perfil expone las cuentas vinculadas sin secretos ni ids del proveedor', function () {
    config([
        'services.google_login.client_id' => 'id-g',
        'services.google_login.client_secret' => 'sec-g',
        'services.facebook_login.client_id' => null,
        'services.facebook_login.client_secret' => null,
    ]);
    $user = User::factory()->create();
    $user->socialIdentities()->create(['provider' => 'google', 'provider_user_id' => 'sub-secreto-123', 'email' => 'ana@example.com', 'linked_at' => now()]);

    $response = $this->actingAs($user)->get('/profile');

    $response->assertInertia(fn (Assert $page) => $page
        ->component('Profile/Edit')
        ->where('socialAccounts.0.provider', 'google')
        ->where('socialAccounts.0.enabled', true)
        ->where('socialAccounts.0.linked', true)
        ->where('socialAccounts.0.email', 'ana@example.com')
        ->where('socialAccounts.1.provider', 'facebook')
        ->where('socialAccounts.1.enabled', false)
        ->where('socialAccounts.1.linked', false)
        ->missing('socialAccounts.0.provider_user_id'));

    expect($response->getContent())->not->toContain('sub-secreto-123')->not->toContain('sec-g');
});

test('vincular y desvincular piden la contrasena antes (redireccion, no 423 para la UI Inertia)', function () {
    config(['services.google_login.client_id' => 'id', 'services.google_login.client_secret' => 's']);
    $user = User::factory()->create();

    $this->actingAs($user)->post('/user/social/google/link', [], ['X-Inertia' => 'true'])->assertRedirect(route('password.confirm'));
    $this->actingAs($user)->delete('/user/social/google', [], ['X-Inertia' => 'true'])->assertRedirect(route('password.confirm'));
});
