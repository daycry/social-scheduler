<?php

use App\Models\User;
use App\Services\TwoFactor\TotpService;
use Tests\Support\McpClient;

uses(McpClient::class);

function mcpUserWith2fa(): User
{
    $svc = app(TotpService::class);
    $user = User::factory()->create();
    $user->forceFill(['two_factor_secret' => $svc->generateSecret(), 'two_factor_confirmed_at' => now()])->save();

    return $user->fresh();
}

test('CA-22: un usuario con 2FA usa el MCP por API key igual que sin 2FA, sin reto', function () {
    $plain = User::factory()->create();
    $with2fa = mcpUserWith2fa();

    $a = $this->rpc($this->mcpKey($plain), 'tools/list')->assertOk();
    $b = $this->rpc($this->mcpKey($with2fa), 'tools/list')->assertOk();

    expect($b->json('result.tools'))->toEqual($a->json('result.tools'));
});

test('CA-22: una sesion a medio reto 2FA no autentica /mcp', function () {
    $user = mcpUserWith2fa();

    $this->post('/login', ['email' => $user->email, 'password' => 'password'])->assertRedirect(route('two-factor.challenge'));
    $this->assertGuest();

    $this->rpc(null, 'tools/list')->assertUnauthorized();
});
