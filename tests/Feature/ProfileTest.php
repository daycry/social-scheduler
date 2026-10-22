<?php

use App\Models\User;

test('profile page is displayed', function () {
    $user = User::factory()->create();

    $response = $this
        ->actingAs($user)
        ->get('/profile');

    $response->assertOk();
});

test('profile information can be updated', function () {
    $user = User::factory()->create();

    $response = $this
        ->actingAs($user)
        ->patch('/profile', [
            'name' => 'Test User',
            'email' => 'test@example.com',
        ]);

    $response
        ->assertSessionHasNoErrors()
        ->assertRedirect('/profile');

    $user->refresh();

    $this->assertSame('Test User', $user->name);
    $this->assertSame('test@example.com', $user->email);
    $this->assertNull($user->email_verified_at);
});

test('email verification status is unchanged when the email address is unchanged', function () {
    $user = User::factory()->create();

    $response = $this
        ->actingAs($user)
        ->patch('/profile', [
            'name' => 'Test User',
            'email' => $user->email,
        ]);

    $response
        ->assertSessionHasNoErrors()
        ->assertRedirect('/profile');

    $this->assertNotNull($user->refresh()->email_verified_at);
});

test('user can delete their account', function () {
    $user = User::factory()->create();

    $response = $this
        ->actingAs($user)
        ->delete('/profile', [
            'password' => 'password',
        ]);

    $response
        ->assertSessionHasNoErrors()
        ->assertRedirect('/');

    $this->assertGuest();
    $this->assertNull($user->fresh());
});

test('correct password must be provided to delete account', function () {
    $user = User::factory()->create();

    $response = $this
        ->actingAs($user)
        ->from('/profile')
        ->delete('/profile', [
            'password' => 'wrong-password',
        ]);

    $response
        ->assertSessionHasErrors('password')
        ->assertRedirect('/profile');

    $this->assertNotNull($user->fresh());
});

test('Security: Profile/Edit expone el estado del 2FA sin secretos', function () {
    $user = User::factory()->create();
    $user->forceFill([
        'two_factor_secret' => 'JBSWY3DPEHPK3PXP',
        'two_factor_recovery_codes' => ['hash-a', 'hash-b', 'hash-c'],
        'two_factor_confirmed_at' => now(),
    ])->save();

    $res = $this->actingAs($user)->get('/profile');

    $res->assertInertia(fn ($page) => $page
        ->component('Profile/Edit')
        ->where('twoFactor', ['enabled' => true, 'pending' => false, 'recoveryRemaining' => 3])
        ->where('twoFactorSetup', null)
        ->where('recoveryCodes', null)
        ->missing('auth.user.two_factor_secret')
        ->missing('auth.user.two_factor_recovery_codes'));
    expect($res->getContent())->not->toContain('JBSWY3DPEHPK3PXP')->not->toContain('hash-a');
});

test('Security: con una activacion pendiente solo hay estado, el secreto no va en props', function () {
    $user = User::factory()->create();
    $user->forceFill(['two_factor_secret' => 'JBSWY3DPEHPK3PXP'])->save();

    $res = $this->actingAs($user)->get('/profile');

    $res->assertInertia(fn ($page) => $page->where('twoFactor.pending', true)->where('twoFactor.enabled', false)->where('twoFactorSetup', null));
    expect($res->getContent())->not->toContain('JBSWY3DPEHPK3PXP');
});

test('Security: los codigos recien generados llegan una sola vez', function () {
    $user = User::factory()->create();
    $user->forceFill(['two_factor_secret' => 'JBSWY3DPEHPK3PXP', 'two_factor_confirmed_at' => now()])->save();

    $this->actingAs($user)->withSession(['auth.password_confirmed_at' => time()])
        ->from('/profile')->post('/user/two-factor/recovery-codes')->assertRedirect('/profile');
    $this->get('/profile')->assertInertia(fn ($page) => $page->has('recoveryCodes', 8));
    $this->get('/profile')->assertInertia(fn ($page) => $page->where('recoveryCodes', null));
});
