<?php

use App\Models\AuditLog;
use App\Models\User;
use App\Services\Passkeys\PasskeyFailure;
use App\Services\Passkeys\PasskeyGateway;
use Illuminate\Support\Facades\Route;
use Illuminate\Testing\TestResponse;
use Tests\Support\FakePasskeys;

beforeEach(fn () => FakePasskeys::bind());

function confirmedPasskeyUser(?User $user = null): User
{
    $user ??= User::factory()->create();
    test()->actingAs($user)->withSession(['auth.password_confirmed_at' => time()]);

    return $user;
}

function registerPasskey(string $name, string $credentialId): TestResponse
{
    test()->getJson('/user/passkeys/options')->assertOk();

    return test()->postJson('/user/passkeys', [
        'name' => $name,
        'credential' => FakePasskeys::credential($credentialId, 'attestation'),
    ]);
}

test('PasskeyManagement: las rutas exigen auth, password.confirm y su propio limitador', function () {
    foreach (Route::getRoutes() as $route) {
        if (str_starts_with($route->uri(), 'user/passkeys')) {
            expect($route->gatherMiddleware())
                ->toContain('auth')->toContain('password.confirm')->toContain('throttle:passkey-manage');
        }
    }

    $this->getJson('/user/passkeys/options')->assertUnauthorized();
    $this->postJson('/user/passkeys', [])->assertUnauthorized();
});

test('PasskeyManagement: sin confirmar la contrasena el fetch recibe 423 y las visitas Inertia redirigen', function () {
    $user = User::factory()->create();
    $passkey = FakePasskeys::seed($user);

    $this->actingAs($user)->getJson('/user/passkeys/options')->assertStatus(423);
    $this->actingAs($user)->postJson('/user/passkeys', ['name' => 'X'])->assertStatus(423);
    $this->actingAs($user)->patch("/user/passkeys/{$passkey->id}", ['name' => 'Nuevo'])->assertRedirect(route('password.confirm'));
    $this->actingAs($user)->delete("/user/passkeys/{$passkey->id}")->assertRedirect(route('password.confirm'));

    expect($user->passkeys()->count())->toBe(1)->and($passkey->fresh()->name)->toBe($passkey->name);
});

test('PasskeyManagement CA-13: el alta con nombre aparece en la lista y audita auth.passkey.registered', function () {
    $user = confirmedPasskeyUser();

    $this->getJson('/user/passkeys/options')->assertOk()
        ->assertJsonPath('options.authenticatorSelection.userVerification', 'required')
        ->assertJsonPath('options.authenticatorSelection.residentKey', 'required');

    registerPasskey('Portátil de casa', 'cred-1')->assertCreated()->assertJsonPath('name', 'Portátil de casa');

    expect($user->passkeys()->pluck('name')->all())->toBe(['Portátil de casa']);
    $row = AuditLog::where('action', 'auth.passkey.registered')->sole();
    expect($row->channel)->toBe('web')->and($row->user_id)->toBe($user->id);
});

test('PasskeyManagement CA-14: dos passkeys conviven y el mismo autenticador se rechaza', function () {
    $user = confirmedPasskeyUser();

    registerPasskey('Primera', 'cred-1')->assertCreated();
    registerPasskey('Segunda', 'cred-2')->assertCreated();
    expect($user->passkeys()->count())->toBe(2);

    // La segunda ronda de opciones excluye las dos ya registradas.
    $this->getJson('/user/passkeys/options')->assertJsonCount(2, 'options.excludeCredentials');

    registerPasskey('Repetida', 'cred-1')->assertStatus(422)->assertJsonStructure(['message']);
    expect($user->passkeys()->count())->toBe(2);
    expect(AuditLog::where('action', 'auth.passkey.register_failed')->count())->toBe(1);
});

test('PasskeyManagement: el alta rechaza nombre vacio o largo y una sesion de alta caducada', function () {
    $user = confirmedPasskeyUser();
    $this->getJson('/user/passkeys/options')->assertOk();

    $this->postJson('/user/passkeys', ['name' => '', 'credential' => FakePasskeys::credential('c', 'attestation')])
        ->assertStatus(422)->assertJsonValidationErrors('name');
    $this->postJson('/user/passkeys', ['name' => str_repeat('a', 101), 'credential' => FakePasskeys::credential('c', 'attestation')])
        ->assertStatus(422)->assertJsonValidationErrors('name');

    // Con las opciones aun en sesion el alta pasa; reutilizar el reto (segunda llamada) no.
    $this->postJson('/user/passkeys', ['name' => 'Ok', 'credential' => FakePasskeys::credential('c', 'attestation')])->assertCreated();
    $this->postJson('/user/passkeys', ['name' => 'Ok2', 'credential' => FakePasskeys::credential('d', 'attestation')])->assertStatus(422);

    expect($user->passkeys()->count())->toBe(1);
});

test('PasskeyManagement CA-15: renombrar actualiza el nombre y audita; vacio o de mas de 100 se rechaza', function () {
    $user = confirmedPasskeyUser();
    $passkey = FakePasskeys::seed($user, 'Vieja');

    $this->patch("/user/passkeys/{$passkey->id}", ['name' => '  Nueva  '])->assertRedirect();
    expect($passkey->fresh()->name)->toBe('Nueva');

    $row = AuditLog::where('action', 'auth.passkey.renamed')->sole();
    expect($row->channel)->toBe('web')->and($row->user_id)->toBe($user->id)->and($row->resource_id)->toBe($passkey->id)
        ->and($row->detail)->toBeNull();

    $this->from('/profile')->patch("/user/passkeys/{$passkey->id}", ['name' => ''])->assertSessionHasErrors('name');
    $this->from('/profile')->patch("/user/passkeys/{$passkey->id}", ['name' => str_repeat('a', 101)])->assertSessionHasErrors('name');
    $this->patch("/user/passkeys/{$passkey->id}", ['name' => str_repeat('a', 100)])->assertRedirect();

    expect(mb_strlen($passkey->fresh()->name))->toBe(100);
    expect(AuditLog::where('action', 'auth.passkey.renamed')->count())->toBe(2);
});

test('PasskeyManagement CA-16: borrar quita la passkey, deja de servir para entrar y audita', function () {
    $user = confirmedPasskeyUser();
    $passkey = FakePasskeys::seed($user, 'Mia', 'cred-z');
    $other = FakePasskeys::seed($user, 'Otra', 'cred-y');

    $this->delete("/user/passkeys/{$passkey->id}")->assertRedirect();

    expect($user->passkeys()->pluck('id')->all())->toBe([$other->id]);
    $row = AuditLog::where('action', 'auth.passkey.deleted')->sole();
    expect($row->channel)->toBe('web')->and($row->user_id)->toBe($user->id)->and($row->resource_id)->toBe($passkey->id);

    // Ya no sirve para entrar.
    $gateway = app(PasskeyGateway::class);
    $gateway->loginOptions();
    try {
        $gateway->authenticate(FakePasskeys::credential('cred-z'));
        $this->fail('la passkey borrada no debe verificar');
    } catch (PasskeyFailure $e) {
        expect($e->reason)->toBe(PasskeyFailure::UNKNOWN_CREDENTIAL);
    }
});

test('PasskeyManagement CA-19: renombrar o borrar la passkey de otro usuario da 404 y no la altera', function () {
    $owner = User::factory()->create();
    $victim = FakePasskeys::seed($owner, 'De otro');
    confirmedPasskeyUser();

    $this->patch("/user/passkeys/{$victim->id}", ['name' => 'Hackeada'])->assertNotFound();
    $this->patchJson("/user/passkeys/{$victim->id}", ['name' => 'Hackeada'])->assertNotFound();
    $this->delete("/user/passkeys/{$victim->id}")->assertNotFound();
    $this->deleteJson("/user/passkeys/{$victim->id}")->assertNotFound();
    // Inexistente: misma respuesta que una ajena.
    $this->delete('/user/passkeys/999999')->assertNotFound();

    expect($victim->fresh()->name)->toBe('De otro')->and($owner->passkeys()->count())->toBe(1);
    expect(AuditLog::whereIn('action', ['auth.passkey.renamed', 'auth.passkey.deleted'])->count())->toBe(0);
});

test('PasskeyManagement: la gestion tiene su propio limitador por usuario', function () {
    $user = confirmedPasskeyUser();

    for ($i = 0; $i < 20; $i++) {
        $this->getJson('/user/passkeys/options')->assertOk();
    }
    $this->getJson('/user/passkeys/options')->assertStatus(429);
});
