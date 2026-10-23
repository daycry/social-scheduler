<?php

use App\Models\AuditLog;
use App\Models\User;
use App\Notifications\PasskeyAdded;
use App\Services\Passkeys\PasskeyFailure;
use App\Services\Passkeys\PasskeyGateway;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Route;
use Illuminate\Testing\TestResponse;
use Laravel\Passkeys\Actions\StorePasskey;
use Laravel\Passkeys\Passkey;
use Tests\Support\FakePasskeys;
use Webauthn\PublicKeyCredential;
use Webauthn\PublicKeyCredentialCreationOptions;

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

test('PasskeyManagement P5: un {id} que desborda el entero es un 404, no un 500', function () {
    confirmedPasskeyUser();
    $huge = '99999999999999999999999';

    $this->patch("/user/passkeys/{$huge}", ['name' => 'X'])->assertNotFound();
    $this->deleteJson("/user/passkeys/{$huge}")->assertNotFound();
    $this->delete('/user/passkeys/9223372036854775807')->assertNotFound();
});

test('PasskeyManagement P7: dar de alta una passkey avisa por correo con nombre, fecha e IP aproximada', function () {
    Notification::fake();
    $user = confirmedPasskeyUser();

    registerPasskey('Portátil de casa', 'cred-mail')->assertCreated();

    Notification::assertSentTo($user, PasskeyAdded::class, function (PasskeyAdded $n) use ($user) {
        $mail = $n->toMail($user);
        $text = implode(' ', $mail->introLines);

        return $n->via($user) === ['mail']
            && str_contains($text, 'Portátil de casa')
            && str_contains($text, now()->format('d/m/Y'))
            && str_contains($text, '127.0.0.x')
            && $mail->actionUrl === route('profile.edit');
    });
});

test('PasskeyManagement P7: un alta rechazada no avisa por correo', function () {
    Notification::fake();
    confirmedPasskeyUser();
    registerPasskey('A', 'misma')->assertCreated();
    Notification::assertCount(1);

    registerPasskey('B', 'misma')->assertStatus(422);

    Notification::assertCount(1);
});

test('PasskeyManagement P7: la IP del aviso se muestra aproximada y el nombre no inyecta marcado', function () {
    expect(PasskeyAdded::maskIp('203.0.113.77'))->toBe('203.0.113.x')
        ->and(PasskeyAdded::maskIp('2001:db8:85a3:8d3:1319:8a2e:370:7348'))->toBe('2001:db8:85a3:8d3:x:x:x:x')
        // Formas comprimidas e IPv4 mapeada (N2): nunca se devuelve la IP completa.
        ->and(PasskeyAdded::maskIp('2001:db8::1'))->toBe('2001:db8:0:0:x:x:x:x')
        ->and(PasskeyAdded::maskIp('::1'))->toBe('0:0:0:0:x:x:x:x')
        ->and(PasskeyAdded::maskIp('::ffff:1.2.3.4'))->toBe('1.2.3.x')
        ->and(PasskeyAdded::maskIp(null))->toBe('desconocida')
        ->and(PasskeyAdded::maskIp('no-es-una-ip'))->toBe('desconocida');

    $mail = (new PasskeyAdded('[click](http://x) <b>hola</b>', '01/01/2027 10:00', '203.0.113.5'))->toMail(User::factory()->make());
    expect(implode(' ', $mail->introLines))->not->toContain('](')->not->toContain('<b>');
});

test('PasskeyManagement P12: un credential ID de 1023 bytes (1364 caracteres en base64url) se guarda y sirve para entrar', function () {
    $user = confirmedPasskeyUser();
    $raw = random_bytes(1023);

    $this->getJson('/user/passkeys/options')->assertOk();
    $this->postJson('/user/passkeys', [
        'name' => 'ID largo',
        'credential' => FakePasskeys::credential($raw, 'attestation'),
    ])->assertCreated();

    $stored = $user->passkeys()->sole()->credential_id;
    expect(strlen($stored))->toBe(1364)->and($stored)->toBe(rtrim(strtr(base64_encode($raw), '+/', '-_'), '='));

    auth()->logout();
    $gateway = app(PasskeyGateway::class);
    $gateway->loginOptions();
    expect($gateway->authenticate(FakePasskeys::credential($raw))->user->is($user))->toBeTrue();
});

test('PasskeyManagement P1: un error inesperado en el alta queda en el log y el mensaje no culpa al dispositivo', function () {
    $user = confirmedPasskeyUser();
    app()->bind(StorePasskey::class, fn () => new class extends StorePasskey
    {
        public function __invoke(Authenticatable $user, string $name, PublicKeyCredential $credential, PublicKeyCredentialCreationOptions $options): Passkey
        {
            throw new QueryException('mysql', 'insert into passkeys (credential_id) values (?)', ['SECRETO-DE-LA-CREDENCIAL'], new Exception('SQLSTATE[HY000]'));
        }
    });
    Log::spy();

    $response = registerPasskey('X', 'cred-err')->assertStatus(500)->assertJsonPath('code', 'server_error');

    expect($response->json('message'))->toContain('problema del servidor')->not->toContain('ya esté registrado');
    Log::shouldHaveReceived('error')->once()->withArgs(fn ($message, $context) => $context['exception'] === QueryException::class
        && ! str_contains(json_encode($context), 'SECRETO-DE-LA-CREDENCIAL')
        && $context['user_id'] === $user->id);
    expect($user->passkeys()->count())->toBe(0);
});

test('PasskeyManagement P10: una attestation de otro origen se rechaza con el aviso de dominio', function () {
    confirmedPasskeyUser();
    $this->getJson('/user/passkeys/options')->assertOk();
    $credential = FakePasskeys::credential('cred-dom', 'attestation');

    config(['passkeys.allowed_origins' => ['https://otro.example']]);

    $this->postJson('/user/passkeys', ['name' => 'X', 'credential' => $credential])->assertStatus(422)
        ->assertJsonPath('code', 'invalid_domain')
        ->assertJsonPath('message', 'Esta passkey no es válida en este dominio.');
});

test('PasskeyManagement P8: el reto de alta caduca en el servidor', function () {
    confirmedPasskeyUser();
    $this->getJson('/user/passkeys/options')->assertOk();
    $credential = FakePasskeys::credential('cred-ttl', 'attestation');

    $this->travel(301)->seconds();

    $this->postJson('/user/passkeys', ['name' => 'X', 'credential' => $credential])->assertStatus(422)
        ->assertJsonPath('message', 'La operación con la passkey caducó. Vuelve a intentarlo.');
});

/** Pide las opciones de alta directamente al gateway (sin HTTP: el entorno de produccion toca estado estatico de peticion). */
function registrationOptionsInEnv(string $env, bool $explicitSecret): void
{
    $user = User::factory()->create();
    config(['passkeys.user_handle_secret_explicit' => $explicitSecret]);
    app()['env'] = $env;

    try {
        app(PasskeyGateway::class)->registrationOptions($user);
    } finally {
        app()['env'] = 'testing';
    }
}

test('PasskeyManagement P11: en produccion sin PASSKEYS_USER_HANDLE_SECRET se avisa en el log', function () {
    Log::spy();

    registrationOptionsInEnv('production', explicitSecret: false);

    Log::shouldHaveReceived('warning')->once()->withArgs(fn ($message) => str_contains($message, 'PASSKEYS_USER_HANDLE_SECRET'));
});

test('PasskeyManagement P11: con el secreto definido, o fuera de produccion, no hay aviso', function () {
    Log::spy();

    registrationOptionsInEnv('production', explicitSecret: true);
    registrationOptionsInEnv('testing', explicitSecret: false);

    Log::shouldNotHaveReceived('warning');
});
