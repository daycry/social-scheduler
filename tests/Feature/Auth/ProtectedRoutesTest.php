<?php

use App\Models\User;

test('guests are redirected to login from protected routes', function (string $uri) {
    $this->get($uri)->assertRedirect(route('login', absolute: false));
})->with(['/dashboard', '/profile', '/brands']);

test('unverified users are sent to email verification from app routes', function (string $uri) {
    $this->actingAs(User::factory()->unverified()->create())->get($uri)
        ->assertRedirect(route('verification.notice', absolute: false));
})->with(['/dashboard', '/brands']);

test('a registered user lands on an empty dashboard', function () {
    $this->post('/register', [
        'name' => 'Test User',
        'email' => 'test@example.com',
        'password' => 'password',
        'password_confirmation' => 'password',
    ])->assertRedirect(route('dashboard', absolute: false));

    $user = User::firstWhere('email', 'test@example.com');
    expect($user->hasVerifiedEmail())->toBeFalse();
    $user->markEmailAsVerified();

    $this->actingAs($user)
        ->get('/dashboard')
        ->assertOk();
});
