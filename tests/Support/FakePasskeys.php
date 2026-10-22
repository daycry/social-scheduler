<?php

namespace Tests\Support;

use App\Models\User;
use Illuminate\Contracts\Auth\Authenticatable;
use Laravel\Passkeys\Actions\StorePasskey;
use Laravel\Passkeys\Actions\VerifyPasskey;
use Laravel\Passkeys\Contracts\PasskeyUser;
use Laravel\Passkeys\Events\PasskeyRegistered;
use Laravel\Passkeys\Events\PasskeyVerified;
use Laravel\Passkeys\Exceptions\InvalidPasskeyException;
use Laravel\Passkeys\Passkey;
use Laravel\Passkeys\Passkeys;
use ParagonIE\ConstantTime\Base64UrlSafe;
use RuntimeException;
use Webauthn\AuthenticatorAssertionResponse;
use Webauthn\Exception\AuthenticatorResponseVerificationException;
use Webauthn\Exception\CounterException;
use Webauthn\PublicKeyCredential;
use Webauthn\PublicKeyCredentialCreationOptions;
use Webauthn\PublicKeyCredentialRequestOptions;

/**
 * Sustituye la criptografia de las Actions del paquete (StorePasskey, VerifyPasskey) para probar los controladores
 * y el gateway sin navegador: conserva lo demas (unicidad, pertenencia, eventos, sesion). La criptografia real se
 * prueba con el vector grabado de PasskeyCeremonyTest.
 */
final class FakePasskeys
{
    /** Que debe hacer la proxima verificacion: null = pasa; 'counter' = CounterException; 'crypto' = rechazo de la libreria (firma invalida); 'server' = error inesperado (no es de WebAuthn). */
    public static ?string $verifyFailure = null;

    public static function bind(): void
    {
        self::$verifyFailure = null;

        app()->bind(StorePasskey::class, fn () => new class extends StorePasskey
        {
            public function __invoke(Authenticatable $user, string $name, PublicKeyCredential $credential, PublicKeyCredentialCreationOptions $options): Passkey
            {
                $id = Base64UrlSafe::encodeUnpadded($credential->rawId);

                if (Passkey::where('credential_id', $id)->exists()) {
                    throw InvalidPasskeyException::make('Unable to register this passkey.');
                }

                /** @var PasskeyUser $user */
                $passkey = $user->passkeys()->create(['name' => $name, 'credential_id' => $id, 'credential' => ['fake' => true]]);
                PasskeyRegistered::dispatch($user, $passkey);

                return $passkey;
            }
        });

        app()->bind(VerifyPasskey::class, fn () => new class extends VerifyPasskey
        {
            public function __invoke(PublicKeyCredential $credential, PublicKeyCredentialRequestOptions $options, ?PasskeyUser $user = null): Passkey
            {
                // Como el paquete: solo una assertion sirve para entrar.
                if (! $credential->response instanceof AuthenticatorAssertionResponse) {
                    throw InvalidPasskeyException::make('Unable to verify passkey. Please try again.');
                }

                $passkey = $this->getPasskey($credential);

                if (FakePasskeys::$verifyFailure === 'counter') {
                    throw CounterException::create(1, 5, 'Invalid counter.');
                }
                if (FakePasskeys::$verifyFailure === 'crypto') {
                    throw AuthenticatorResponseVerificationException::create('Invalid signature.');
                }
                if (FakePasskeys::$verifyFailure === 'server') {
                    throw new RuntimeException('Invalid origin: http:///');
                }

                $passkey->forceFill(['last_used_at' => now()])->save();
                PasskeyVerified::dispatch($passkey->user, $passkey);

                return $passkey;
            }
        });
    }

    /**
     * Respuesta de navegador (JSON) con el id de credencial dado, valida para el deserializador del paquete
     * (que acepta base64 con relleno en rawId, firma, authenticatorData y attestationObject).
     */
    public static function credential(string $credentialId, string $type = 'assertion', ?string $challenge = null): array
    {
        $url = fn (string $s) => Base64UrlSafe::encodeUnpadded($s);
        // Por defecto, el reto de la ultima ceremonia pendiente en la sesion (como si el navegador respondiera a las
        // opciones recien pedidas); sin ninguna, uno cualquiera. `$challenge` ya viene en base64url.
        $key = $type === 'assertion' ? 'passkey.verification_options' : 'passkey.registration_options';
        $pending = array_keys((array) session()->get($key, []));
        $challenge ??= $pending === [] ? $url('reto') : (string) end($pending);
        $clientData = fn (string $t) => $url(json_encode(['type' => $t, 'challenge' => $challenge, 'origin' => config('passkeys.allowed_origins')[0] ?? 'https://metricool.test']));
        // CBOR minimo {fmt: none, attStmt: {}, authData: 37 bytes a cero}
        $attestation = hex2bin('a363666d74646e6f6e656761747453746d74a06861757468446174615825').str_repeat("\0", 37);

        return [
            'id' => $url($credentialId),
            'rawId' => base64_encode($credentialId),
            'type' => 'public-key',
            'response' => $type === 'assertion'
                ? [
                    'clientDataJSON' => $clientData('webauthn.get'),
                    // Hash del RP ID configurado (el gateway lo comprueba antes de buscar la credencial) + flags y contador.
                    'authenticatorData' => base64_encode(hash('sha256', Passkeys::relyingPartyId(), true).str_repeat("\0", 5)),
                    'signature' => base64_encode('firma'),
                    'userHandle' => base64_encode('handle'),
                ]
                : [
                    'clientDataJSON' => $clientData('webauthn.create'),
                    'attestationObject' => base64_encode($attestation),
                ],
        ];
    }

    /** Crea una passkey en BD para un usuario (no valida nada: solo la fila). */
    public static function seed(User $user, string $name = 'Mi portátil', ?string $credentialId = null): Passkey
    {
        return $user->passkeys()->create([
            'name' => $name,
            'credential_id' => Base64UrlSafe::encodeUnpadded($credentialId ?? random_bytes(16)),
            'credential' => ['fake' => true],
        ]);
    }
}
