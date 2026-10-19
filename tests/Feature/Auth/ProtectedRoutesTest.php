<?php

use App\Models\User;

test('guests are redirected to login from protected routes', function (string $uri) {
    $this->get($uri)->assertRedirect(route('login', absolute: false));
})->with(['/dashboard', '/profile']);

test('a registered user lands on an empty dashboard', function () {
    $this->post('/register', [
        'name' => 'Test User',
        'email' => 'test@example.com',
        'password' => 'password',
        'password_confirmation' => 'password',
    ])->assertRedirect(route('dashboard', absolute: false));

    $this->actingAs(User::firstWhere('email', 'test@example.com'))
        ->get('/dashboard')
        ->assertOk();
});
