<?php

use App\Models\User;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Laravel\Passkeys\Support\WebAuthn;
use Webauthn\PublicKeyCredentialCreationOptions;
use Webauthn\PublicKeyCredentialRequestOptions;

// T-14: recorre el ciclo completo de una passkey con la ceremonia REAL del vector grabado (alta, renombrado, login,
// login con firma y dominio incorrectos, reto reutilizado, borrado y login con la passkey borrada) y comprueba que
// ningun material de la credencial WebAuthn ni el nombre de la passkey acaban en `audit_logs` ni en el log.
test('PasskeyAuditLeak: ningun material de la credencial llega a la auditoria ni al log', function () {
    $vector = json_decode(file_get_contents(base_path('tests/Fixtures/passkeys/vector.json')), true, flags: JSON_THROW_ON_ERROR);
    config(['passkeys.relying_party_id' => $vector['rpId'], 'passkeys.allowed_origins' => [$vector['origin']]]);

    $logged = [];
    Event::listen(MessageLogged::class, function (MessageLogged $e) use (&$logged) {
        $logged[] = $e->level.' '.$e->message.' '.json_encode($e->context);
    });
    $seed = function (string $key, string $class, array $options) {
        session()->put($key, [$options['challenge'] => ['at' => time(), 'options' => WebAuthn::toJson(WebAuthn::fromJson(json_encode($options), $class))]]);
    };
    $login = function (array $credential) use ($seed, $vector) {
        $seed('passkey.verification_options', PublicKeyCredentialRequestOptions::class, $vector['assertions'][0]['options']);

        return $this->postJson('/passkeys/login', ['credential' => $credential]);
    };

    // Alta y renombrado.
    $user = User::factory()->create();
    $this->actingAs($user)->withSession(['auth.password_confirmed_at' => time()]);
    $seed('passkey.registration_options', PublicKeyCredentialCreationOptions::class, $vector['registration']['options']);
    $id = $this->postJson('/user/passkeys', ['name' => 'Portátil secreto', 'credential' => $vector['registration']['credential']])->assertCreated()->json('id');
    $this->patchJson("/user/passkeys/{$id}", ['name' => 'Móvil confidencial'])->assertSuccessful();

    // Login correcto, firma malformada, otro dominio y reto ya usado.
    $this->post('/logout');
    $this->flushSession();
    $assertion = $vector['assertions'][0]['credential'];
    $login($assertion)->assertSuccessful();
    $this->post('/logout');
    $this->flushSession();
    $bad = $assertion;
    $bad['response']['signature'] = rtrim(strtr(base64_encode(random_bytes(70)), '+/', '-_'), '=');
    $login($bad)->assertStatus(422);
    config(['passkeys.relying_party_id' => 'metricool.test']);
    $login($assertion)->assertStatus(422);
    config(['passkeys.relying_party_id' => $vector['rpId']]);
    $this->postJson('/passkeys/login', ['credential' => $vector['assertions'][1]['credential']])->assertStatus(422);

    // Borrado y login con la passkey borrada.
    $this->actingAs($user)->withSession(['auth.password_confirmed_at' => time()]);
    $this->deleteJson("/user/passkeys/{$id}")->assertSuccessful();
    $this->post('/logout');
    $this->flushSession();
    $login($vector['assertions'][2]['credential'])->assertStatus(422);

    $audit = DB::table('audit_logs')->get()->map(fn ($row) => json_encode($row))->implode("\n");
    expect($audit)->toContain('auth.passkey.registered')->toContain('auth.passkey.login')->toContain('auth.passkey.deleted');

    $registration = $vector['registration']['credential'];
    $sensitive = [
        $registration['id'],
        $registration['rawId'],
        $registration['response']['attestationObject'],
        $registration['response']['clientDataJSON'],
        $assertion['response']['authenticatorData'],
        $assertion['response']['signature'],
        $assertion['response']['clientDataJSON'],
        $assertion['response']['userHandle'] ?? null,
        'Portátil secreto',
        'Móvil confidencial',
    ];
    $haystack = $audit."\n".implode("\n", $logged);
    foreach (array_filter($sensitive) as $value) {
        // Tambien escapado como en JSON (las tildes y barras salen escapadas en `json_encode`).
        expect($haystack)->not->toContain($value)->not->toContain(trim(json_encode($value), '"'));
    }
});
