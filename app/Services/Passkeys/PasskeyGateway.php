<?php

namespace App\Services\Passkeys;

use App\Models\User;
use Laravel\Passkeys\Actions\DeletePasskey;
use Laravel\Passkeys\Actions\GenerateRegistrationOptions;
use Laravel\Passkeys\Actions\GenerateVerificationOptions;
use Laravel\Passkeys\Actions\StorePasskey;
use Laravel\Passkeys\Actions\VerifyPasskey;
use Laravel\Passkeys\Exceptions\InvalidPasskeyException;
use Laravel\Passkeys\Passkey;
use Laravel\Passkeys\Passkeys;
use Laravel\Passkeys\Support\WebAuthn;
use ParagonIE\ConstantTime\Base64UrlSafe;
use Throwable;
use Webauthn\Exception\CounterException;
use Webauthn\PublicKeyCredential;
use Webauthn\PublicKeyCredentialCreationOptions;
use Webauthn\PublicKeyCredentialRequestOptions;

/**
 * Unico punto de contacto con `laravel/passkeys` (y, por debajo, con `web-auth/webauthn-lib`). Lo demas de la app
 * (controladores, listeners de auditoria) habla con este gateway y no importa nada del paquete.
 *
 * Version del paquete: `laravel/passkeys` ~0.2.1 (pre-1.0, fijada en composer.json) sobre `webauthn-lib` 5.3.x.
 * Regla del ADR-003: al subir de version hay que releer las Actions (firmas, esquema de `credential`, eventos) y
 * volver a pasar `PasskeyGatewayTest` y `PasskeyCeremonyTest`; un 0.x puede romper la API sin avisar.
 *
 * Las Actions se resuelven del contenedor en cada llamada, asi que un test puede sustituirlas con `bind`.
 * Las opciones de cada ceremonia viven en la sesion y se consumen con `pull` (reto de un solo uso).
 */
class PasskeyGateway
{
    public const REGISTRATION_SESSION_KEY = 'passkey.registration_options';

    public const VERIFICATION_SESSION_KEY = 'passkey.verification_options';

    /** Opciones de alta (JSON para el navegador). Excluye los autenticadores que el usuario ya tiene registrados. */
    public function registrationOptions(User $user): array
    {
        $options = app(GenerateRegistrationOptions::class)($user);

        session()->put(self::REGISTRATION_SESSION_KEY, WebAuthn::toJson($options));

        return WebAuthn::toBrowserArray($options);
    }

    /**
     * Valida la attestation contra las opciones guardadas en sesion y guarda la passkey (dispara `PasskeyRegistered`).
     *
     * @param  array<string, mixed>  $credential  respuesta del navegador (`credential` de la peticion)
     *
     * @throws PasskeyFailure
     */
    public function register(User $user, string $name, array $credential): Passkey
    {
        $options = $this->pullOptions(self::REGISTRATION_SESSION_KEY, PublicKeyCredentialCreationOptions::class);
        $parsed = $this->parseCredential($credential);

        try {
            return app(StorePasskey::class)($user, $name, $parsed, $options);
        } catch (Throwable) {
            throw new PasskeyFailure(
                PasskeyFailure::REGISTRATION_REJECTED,
                'No se pudo registrar la passkey. Puede que este dispositivo ya esté registrado: inténtalo de nuevo o usa otro.',
                $user->id,
            );
        }
    }

    /** Opciones de login sin usuario conocido (credencial discoverable), JSON para el navegador. */
    public function loginOptions(): array
    {
        $options = app(GenerateVerificationOptions::class)();

        session()->put(self::VERIFICATION_SESSION_KEY, WebAuthn::toJson($options));

        return WebAuthn::toBrowserArray($options);
    }

    /**
     * Verifica la assertion (firma, reto, origen, RP ID, contador) y devuelve la passkey ya actualizada, con su
     * usuario. Dispara `PasskeyVerified`. No abre sesion: eso es del controlador.
     *
     * @param  array<string, mixed>  $credential
     *
     * @throws PasskeyFailure con un mensaje igual para cualquier causa (no revela si la cuenta existe)
     */
    public function authenticate(array $credential): Passkey
    {
        $options = $this->pullOptions(self::VERIFICATION_SESSION_KEY, PublicKeyCredentialRequestOptions::class);
        $parsed = $this->parseCredential($credential);

        try {
            return app(VerifyPasskey::class)($parsed, $options);
        } catch (Throwable $e) {
            throw new PasskeyFailure(
                match (true) {
                    $e instanceof CounterException => PasskeyFailure::SIGN_COUNT_REGRESSION,
                    $e instanceof InvalidPasskeyException => PasskeyFailure::UNKNOWN_CREDENTIAL,
                    default => PasskeyFailure::VERIFICATION_FAILED,
                },
                'No se pudo entrar con la passkey. Inténtalo de nuevo o entra con tu contraseña.',
                $this->ownerOf($parsed),
            );
        }
    }

    /** Borra la passkey (dispara `PasskeyDeleted`). */
    public function delete(User $user, Passkey $passkey): void
    {
        app(DeletePasskey::class)($user, $passkey);
    }

    /**
     * @template T of object
     *
     * @param  class-string<T>  $class
     * @return T
     *
     * @throws PasskeyFailure
     */
    private function pullOptions(string $key, string $class): object
    {
        $serialized = session()->pull($key);

        try {
            if (is_string($serialized) && $serialized !== '') {
                return WebAuthn::fromJson($serialized, $class);
            }
        } catch (Throwable) {
            // cae al fallo generico de abajo
        }

        throw new PasskeyFailure(PasskeyFailure::SESSION_EXPIRED, 'La operación con la passkey caducó. Vuelve a intentarlo.');
    }

    /** @param  array<string, mixed>  $credential */
    private function parseCredential(array $credential): PublicKeyCredential
    {
        try {
            return WebAuthn::fromJson(json_encode($credential, JSON_THROW_ON_ERROR), PublicKeyCredential::class);
        } catch (Throwable) {
            throw new PasskeyFailure(PasskeyFailure::INVALID_FORMAT, 'La respuesta de la passkey no es válida.');
        }
    }

    /** Dueño de la credencial presentada, solo para la auditoria del servidor (null si no existe). */
    private function ownerOf(PublicKeyCredential $credential): ?int
    {
        try {
            $owner = Passkeys::passkeyModel()::where('credential_id', Base64UrlSafe::encodeUnpadded($credential->rawId))->value('user_id');

            return $owner !== null ? (int) $owner : null;
        } catch (Throwable) {
            return null;
        }
    }
}
