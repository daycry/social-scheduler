<?php

use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;

require_once __DIR__.'/Mcp/OAuth/Helpers.php';

test('CA-25: el listado distingue una key manual de un token OAuth y revoca ambos', function () {
    $user = User::factory()->create();
    $manual = $user->createToken('Mi key', ['posts:read'], now()->addDays(30))->accessToken;
    $oauth = $user->createToken('OAuth · Claude Code', ['posts:read'], now()->addDays(30))->accessToken;
    $oauth->forceFill(['oauth_client_id' => oauthClient()->id, 'origin' => 'oauth'])->save();

    $this->actingAs($user)->get('/api-keys')->assertInertia(fn (Assert $page) => $page
        ->component('ApiKeys/Index')
        ->where('keys', fn ($keys) => collect($keys)->firstWhere('id', $manual->id)['origin'] === 'manual'
            && collect($keys)->firstWhere('id', $manual->id)['oauth_client'] === null
            && collect($keys)->firstWhere('id', $oauth->id)['origin'] === 'oauth'
            && collect($keys)->firstWhere('id', $oauth->id)['oauth_client'] === 'Claude Code'));

    $this->actingAs($user)->delete("/api-keys/{$oauth->id}")->assertRedirect();
    $this->assertDatabaseMissing('personal_access_tokens', ['id' => $oauth->id]);
    $this->assertDatabaseHas('personal_access_tokens', ['id' => $manual->id]);
});

test('una key sin origen (creada antes de OAuth) se ve como manual', function () {
    $user = User::factory()->create();
    $user->createToken('Antigua', ['posts:read'], now()->addDays(30));

    $this->actingAs($user)->get('/api-keys')->assertInertia(fn (Assert $page) => $page
        ->where('keys.0.origin', 'manual')->where('keys.0.oauth_client', null));
});
