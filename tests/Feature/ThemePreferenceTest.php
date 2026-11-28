<?php

use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;

it('shares system as the default account theme and no account for guests', function () {
    $this->get('/login')->assertInertia(fn (Assert $page) => $page->where('auth.user', null));

    $this->actingAs(User::factory()->create())->get('/profile')
        ->assertInertia(fn (Assert $page) => $page->where('auth.user.theme', 'system'))
        ->assertSee('data-account-theme="system"', false);
});

it('persists the selected theme only for the signed-in account', function () {
    $user = User::factory()->create();
    $other = User::factory()->create();

    foreach (['dark', 'light', 'system'] as $theme) {
        $this->actingAs($user)->from('/profile')->patch('/profile/theme', ['theme' => $theme, 'user_id' => $other->id])
            ->assertRedirect('/profile');
        expect($user->refresh()->theme)->toBe($theme)
            ->and($other->refresh()->theme)->toBe('system');
        $this->get('/profile')
            ->assertInertia(fn (Assert $page) => $page->where('auth.user.theme', $theme))
            ->assertSee('data-account-theme="'.$theme.'"', false);
    }

    $this->actingAs($other)->get('/profile')
        ->assertInertia(fn (Assert $page) => $page->where('auth.user.theme', 'system'));
});

it('rejects invalid themes and guest writes', function () {
    $this->patchJson('/profile/theme', ['theme' => 'dark'])->assertUnauthorized();

    $user = User::factory()->create();
    foreach ([[], ['theme' => 'sepia'], ['theme' => ['dark']]] as $payload) {
        $this->actingAs($user)->patchJson('/profile/theme', $payload)->assertUnprocessable()->assertJsonValidationErrors('theme');
    }
    expect($user->refresh()->theme)->toBe('system');
});
