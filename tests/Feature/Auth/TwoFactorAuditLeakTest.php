<?php

use App\Models\User;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use PragmaRX\Google2FA\Google2FA;

// T-07: recorre todo el ciclo del 2FA (activar, fallar, confirmar, reto con TOTP y con codigo de recuperacion,
// bloqueo, regenerar, desactivar) y comprueba que ningun secreto, TOTP ni codigo de recuperacion acaba en
// `audit_logs` ni en el log, en ningun nivel.
test('TwoFactorAuditLeak: ningun secreto, TOTP ni codigo de recuperacion llega a la auditoria ni al log', function () {
    $logged = [];
    Event::listen(MessageLogged::class, function (MessageLogged $e) use (&$logged) {
        $logged[] = $e->level.' '.$e->message.' '.json_encode($e->context);
    });

    $g = new Google2FA;
    $totp = fn (string $secret, int $offset = 0) => $g->oathTotp($secret, $g->getTimestamp() + $offset);
    $confirmed = ['auth.password_confirmed_at' => time()];
    $user = User::factory()->create();
    $sensitive = [];

    // Activar: secreto pendiente, un intento fallido y la confirmacion.
    $this->actingAs($user)->withSession($confirmed)->post(route('two-factor.enable', absolute: false))->assertRedirect();
    $secret = $user->fresh()->two_factor_secret;
    $sensitive[] = $secret;
    $this->withSession($confirmed)->post(route('two-factor.confirm', absolute: false), ['code' => '000000']);
    $code = $totp($secret);
    $sensitive[] = $code;
    $this->withSession($confirmed)->post(route('two-factor.confirm', absolute: false), ['code' => $code])->assertRedirect();
    $codes = session('recoveryCodes');
    expect($codes)->toHaveCount(8);
    array_push($sensitive, ...$codes);

    // Reto: fallo, codigo de recuperacion y bloqueo por fuerza bruta.
    $this->post('/logout');
    $this->post('/login', ['email' => $user->email, 'password' => 'password']);
    $this->post(route('two-factor.challenge.store', absolute: false), ['code' => '111111']);
    $this->post(route('two-factor.challenge.store', absolute: false), ['recovery_code' => $codes[0]])->assertRedirect();
    $this->assertAuthenticatedAs($user);
    $this->post('/logout');
    $this->post('/login', ['email' => $user->email, 'password' => 'password']);
    foreach (range(1, 7) as $i) {
        $wrong = str_pad((string) $i, 6, '0', STR_PAD_LEFT);
        $sensitive[] = $wrong;
        $this->post(route('two-factor.challenge.store', absolute: false), ['code' => $wrong]);
    }

    // Regenerar y desactivar con un TOTP del paso siguiente (el actual ya se uso).
    $this->actingAs($user->fresh())->withSession($confirmed)->post(route('two-factor.recovery-codes', absolute: false))->assertRedirect();
    array_push($sensitive, ...session('recoveryCodes'));
    $next = $totp($secret, 1);
    $sensitive[] = $next;
    $this->withSession($confirmed)->delete(route('two-factor.disable', absolute: false), ['password' => 'password', 'code' => $next]);

    $audit = DB::table('audit_logs')->get()->map(fn ($row) => json_encode($row))->implode("\n");
    expect($audit)->toContain('auth.2fa.');
    $haystack = $audit."\n".implode("\n", $logged);
    foreach (array_unique(array_filter($sensitive)) as $value) {
        expect($haystack)->not->toContain($value);
    }
});
