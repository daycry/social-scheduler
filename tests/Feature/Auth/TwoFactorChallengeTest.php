<?php

use App\Models\AuditLog;
use App\Models\User;
use App\Services\TwoFactor\TotpService;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use PragmaRX\Google2FA\Google2FA;

function userWith2fa(): array
{
    $svc = app(TotpService::class);
    $secret = $svc->generateSecret();
    $user = User::factory()->create();
    $user->forceFill(['two_factor_secret' => $secret, 'two_factor_confirmed_at' => now()])->save();
    $codes = $svc->generateRecoveryCodes($user);

    return [$user->fresh(), $secret, $codes];
}

function loginStep(User $user, array $extra = [])
{
    return test()->post('/login', ['email' => $user->email, 'password' => 'password'] + $extra);
}

function otp(string $secret, int $offsetSteps = 0): string
{
    $g = new Google2FA;

    return $g->oathTotp($secret, $g->getTimestamp() + $offsetSteps);
}

test('un usuario sin 2FA entra igual que antes', function () {
    $user = User::factory()->create();

    loginStep($user)->assertRedirect(route('dashboard', absolute: false));
    $this->assertAuthenticated();
});

test('CA-04: con 2FA las credenciales correctas no autentican y llevan al reto', function () {
    [$user] = userWith2fa();

    loginStep($user)->assertRedirect(route('two-factor.challenge'));
    $this->assertGuest();
    $this->get('/dashboard')->assertRedirect('/login');
    $this->get('/two-factor-challenge')->assertOk();
});

test('el login con contraseña incorrecta sigue dando el mismo error y no abre reto', function () {
    [$user] = userWith2fa();

    $this->post('/login', ['email' => $user->email, 'password' => 'mal'])->assertSessionHasErrors('email');
    expect(session('login.id'))->toBeNull();
});

test('CA-05: TOTP valido entra, regenera la sesion y audita', function () {
    [$user, $secret] = userWith2fa();
    loginStep($user);
    $before = session()->getId();

    $this->post('/two-factor-challenge', ['code' => otp($secret)])->assertRedirect(route('dashboard', absolute: false));

    $this->assertAuthenticatedAs($user);
    expect(session()->getId())->not->toBe($before);
    expect(session('login.id'))->toBeNull();
    expect(AuditLog::where('action', 'auth.2fa.challenge_passed')->where('user_id', $user->id)->where('channel', 'web')->count())->toBe(1);
});

test('CA-05: remember se respeta', function () {
    [$user, $secret] = userWith2fa();
    loginStep($user, ['remember' => true]);
    $res = $this->post('/two-factor-challenge', ['code' => otp($secret)]);

    $cookies = collect($res->headers->getCookies())->map->getName()->all();
    expect(collect($cookies)->contains(fn ($n) => str_starts_with($n, 'remember_web_')))->toBeTrue();
});

test('CA-05: aplica el intended', function () {
    [$user, $secret] = userWith2fa();
    $this->get('/profile');
    loginStep($user);
    $this->post('/two-factor-challenge', ['code' => otp($secret)])->assertRedirect('http://localhost/profile');
});

test('CA-06: 5 fallos y el sexto se bloquea aunque el codigo sea correcto', function () {
    [$user, $secret] = userWith2fa();
    loginStep($user);

    for ($i = 0; $i < 5; $i++) {
        $this->post('/two-factor-challenge', ['code' => '000000'])->assertSessionHasErrors('code');
    }
    $res = $this->post('/two-factor-challenge', ['code' => otp($secret)]);

    $res->assertSessionHasErrors('code');
    expect(session('errors')->first('code'))->toContain('segundos');
    $this->assertGuest();
    expect(AuditLog::where('action', 'auth.2fa.challenge_failed')->count())->toBe(5);
    expect(AuditLog::where('action', 'auth.2fa.lockout')->count())->toBe(1);
});

test('CA-07: un TOTP reutilizado se rechaza', function () {
    [$user, $secret] = userWith2fa();
    $code = otp($secret);

    loginStep($user);
    $this->post('/two-factor-challenge', ['code' => $code])->assertRedirect();
    $this->post('/logout');

    loginStep($user);
    $this->post('/two-factor-challenge', ['code' => $code])->assertSessionHasErrors('code');
    $this->assertGuest();
});

test('CA-08: reto caducado o inexistente vuelve a login sin sesion', function () {
    [$user, $secret] = userWith2fa();

    $this->get('/two-factor-challenge')->assertRedirect('/login');
    $this->post('/two-factor-challenge', ['code' => otp($secret)])->assertRedirect('/login');

    loginStep($user);
    $this->travel(6)->minutes();
    $this->post('/two-factor-challenge', ['code' => otp($secret)])
        ->assertRedirect('/login')
        ->assertSessionHas('status');
    $this->assertGuest();
    expect(session('login.id'))->toBeNull();
});

test('CA-09: un codigo de recuperacion entra, no sirve dos veces y no se audita el codigo', function () {
    [$user, , $codes] = userWith2fa();

    loginStep($user);
    $this->post('/two-factor-challenge', ['recovery_code' => $codes[0]])->assertRedirect(route('dashboard', absolute: false));
    $this->assertAuthenticatedAs($user);
    $this->post('/logout');

    loginStep($user);
    $this->post('/two-factor-challenge', ['recovery_code' => $codes[0]])->assertSessionHasErrors('recovery_code');
    $this->assertGuest();

    $entry = AuditLog::where('action', 'auth.2fa.recovery_used')->first();
    expect($entry->detail)->toBe('restantes: 7');
    expect(AuditLog::all()->toJson())->not->toContain($codes[0]);
});

test('si el usuario desactiva el 2FA a mitad del reto, el reto deja de valer', function () {
    [$user, $secret] = userWith2fa();
    loginStep($user);
    $user->forceFill(['two_factor_secret' => null, 'two_factor_confirmed_at' => null])->save();

    $this->post('/two-factor-challenge', ['code' => otp($secret)])->assertRedirect('/login');
    $this->assertGuest();
});

test('I1: el limitador es por cuenta: rotar la IP no da intentos nuevos y el 6.o queda bloqueado', function () {
    [$user, $secret] = userWith2fa();
    loginStep($user);

    foreach (range(1, 5) as $i) {
        $this->withServerVariables(['REMOTE_ADDR' => '10.0.0.'.$i])->post('/two-factor-challenge', ['code' => '000000'])->assertSessionHasErrors('code');
    }

    // Sexto intento desde otra IP, incluso con el TOTP correcto: bloqueado.
    $this->withServerVariables(['REMOTE_ADDR' => '10.0.0.99'])->post('/two-factor-challenge', ['code' => otp($secret)])->assertSessionHasErrors('code');
    expect(session('errors')->first('code'))->toContain('segundos');
    $this->assertGuest();
    expect(RateLimiter::attempts('2fa:'.$user->id))->toBe(6);
});

test('I1: el limite tambien cubre los codigos de recuperacion', function () {
    [$user, , $codes] = userWith2fa();
    loginStep($user);

    foreach (range(1, 5) as $i) {
        $this->withServerVariables(['REMOTE_ADDR' => '10.0.1.'.$i])->post('/two-factor-challenge', ['recovery_code' => 'xxxx-xxxx'])->assertSessionHasErrors('recovery_code');
    }

    $this->post('/two-factor-challenge', ['recovery_code' => $codes[0]])->assertSessionHasErrors('recovery_code');
    $this->assertGuest();
});

test('I1: acertar limpia el contador', function () {
    [$user, $secret] = userWith2fa();
    loginStep($user);
    $this->post('/two-factor-challenge', ['code' => '000000']);
    expect(RateLimiter::attempts('2fa:'.$user->id))->toBe(1);

    $this->post('/two-factor-challenge', ['code' => otp($secret)])->assertRedirect();
    expect(RateLimiter::attempts('2fa:'.$user->id))->toBe(0);
});

test('M6: el bloqueo se audita solo la primera vez en la ventana', function () {
    [$user] = userWith2fa();
    loginStep($user);

    foreach (range(1, 9) as $i) {
        $this->post('/two-factor-challenge', ['code' => '000000']);
    }

    expect(AuditLog::where('action', 'auth.2fa.lockout')->count())->toBe(1);
    expect(AuditLog::where('action', 'auth.2fa.challenge_failed')->count())->toBe(5);
});

test('I2: el login con 2FA rehashea la contraseña si las rondas subieron', function () {
    [$user] = userWith2fa();
    $before = $user->password;
    config(['hashing.bcrypt.rounds' => 6]);
    app('hash')->forgetDrivers();

    loginStep($user)->assertRedirect(route('two-factor.challenge', absolute: false));

    $after = $user->fresh()->password;
    expect($after)->not->toBe($before)->and(Hash::needsRehash($after))->toBeFalse();
    expect(Hash::check('password', $after))->toBeTrue();
});

test('I2: el login sin 2FA tambien rehashea', function () {
    $user = User::factory()->create();
    $before = $user->password;
    config(['hashing.bcrypt.rounds' => 6]);
    app('hash')->forgetDrivers();

    loginStep($user)->assertRedirect(route('dashboard', absolute: false));

    expect($user->fresh()->password)->not->toBe($before);
});
