<?php

namespace App\Services\Passkeys;

use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Log;
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
use Webauthn\AuthenticatorAssertionResponse;
use Webauthn\Exception\AuthenticatorResponseVerificationException;
use Webauthn\Exception\CounterException;
use Webauthn\Exception\WebauthnException;
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
 *
 * Las opciones de cada ceremonia viven en la sesion, por reto: una lista acotada de ceremonias vigentes
 * (`MAX_PENDING`) con caducidad de servidor (`passkeys.challenge_ttl`). La respuesta del navegador lleva el reto en su
 * `clientDataJSON`, asi que se consume exactamente el que corresponde (autofill + boton, o dos pestañas, no se pisan)
 * y es de un solo uso.
 *
 * Los rechazos esperados de WebAuthn (attestation/assertion invalida, contador, origen) son `PasskeyFailure` de
 * cliente (422). Lo inesperado (configuracion de origenes/RP ID, BD...) se registra en el log SIN material de la
 * credencial y sale como `SERVER_ERROR` (500) con un mensaje que no culpa al dispositivo.
 */
class PasskeyGateway
{
    public const REGISTRATION_SESSION_KEY = 'passkey.registration_options';

    public const VERIFICATION_SESSION_KEY = 'passkey.verification_options';

    /** Ceremonias pendientes por sesion y tipo (autofill + boton + alguna pestaña mas). */
    public const MAX_PENDING = 5;

    /** Opciones de alta (JSON para el navegador). Excluye los autenticadores que el usuario ya tiene registrados. */
    public function registrationOptions(User $user): array
    {
        $this->warnIfHandleSecretMissing();

        $options = app(GenerateRegistrationOptions::class)($user);

        $this->storeOptions(self::REGISTRATION_SESSION_KEY, $options->challenge, WebAuthn::toJson($options));

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
        $parsed = $this->parseCredential($credential);
        $options = $this->pullOptions(self::REGISTRATION_SESSION_KEY, PublicKeyCredentialCreationOptions::class, $parsed);
        $this->assertAllowedOrigin($parsed, $user->id);

        try {
            return app(StorePasskey::class)($user, $name, $parsed, $options);
        } catch (Throwable $e) {
            throw match (true) {
                $this->isDomainMismatch($e) => $this->domainFailure($user->id),
                $this->isExpected($e) => new PasskeyFailure(
                    PasskeyFailure::REGISTRATION_REJECTED,
                    'No se pudo registrar la passkey. Puede que este dispositivo ya esté registrado: inténtalo de nuevo o usa otro.',
                    $user->id,
                ),
                default => $this->serverFailure($e, 'alta', $user->id),
            };
        }
    }

    /** Opciones de login sin usuario conocido (credencial discoverable), JSON para el navegador. */
    public function loginOptions(): array
    {
        $options = app(GenerateVerificationOptions::class)();

        $this->storeOptions(self::VERIFICATION_SESSION_KEY, $options->challenge, WebAuthn::toJson($options));

        return WebAuthn::toBrowserArray($options);
    }

    /**
     * Verifica la assertion (firma, reto, origen, RP ID, contador) y devuelve la passkey ya actualizada, con su
     * usuario. Dispara `PasskeyVerified`. No abre sesion: eso es del controlador.
     *
     * El dueño (`PasskeyFailure::$userId`, solo para la auditoria) se atribuye UNICAMENTE cuando la verificacion llego
     * a comprobar la firma y fallo el contador: ahi la credencial ya es de verdad suya. Con una credencial solo
     * «presentada» (cualquiera puede mandar un `credential_id` ajeno) no se ensucia la auditoria de su dueño.
     *
     * @param  array<string, mixed>  $credential
     *
     * @throws PasskeyFailure con un mensaje igual para cualquier causa de cliente (no revela si la cuenta existe)
     */
    public function authenticate(array $credential): Passkey
    {
        $parsed = $this->parseCredential($credential);
        $options = $this->pullOptions(self::VERIFICATION_SESSION_KEY, PublicKeyCredentialRequestOptions::class, $parsed);

        // El dominio (origen del navegador y hash del RP ID del autenticador) se comprueba ANTES de buscar la credencial:
        // asi el aviso de «otro dominio» sale igual exista o no la credencial (no es un oraculo).
        $this->assertAllowedOrigin($parsed, null);
        $this->assertRelyingPartyHash($parsed, null);

        try {
            return app(VerifyPasskey::class)($parsed, $options);
        } catch (Throwable $e) {
            if (! $this->isExpected($e)) {
                throw $this->serverFailure($e, 'login', null);
            }
            if ($this->isDomainMismatch($e)) {
                throw $this->domainFailure(null);
            }

            throw new PasskeyFailure(
                match (true) {
                    $e instanceof CounterException => PasskeyFailure::SIGN_COUNT_REGRESSION,
                    ! $parsed->response instanceof AuthenticatorAssertionResponse => PasskeyFailure::INVALID_RESPONSE,
                    $e instanceof InvalidPasskeyException => PasskeyFailure::UNKNOWN_CREDENTIAL,
                    default => PasskeyFailure::VERIFICATION_FAILED,
                },
                'No se pudo entrar con la passkey. Inténtalo de nuevo o entra con tu contraseña.',
                $e instanceof CounterException ? $this->ownerOf($parsed) : null,
            );
        }
    }

    /** Borra la passkey (dispara `PasskeyDeleted`). */
    public function delete(User $user, Passkey $passkey): void
    {
        app(DeletePasskey::class)($user, $passkey);
    }

    /** Guarda las opciones de la ceremonia bajo su reto; poda las caducadas y conserva las `MAX_PENDING` mas recientes. */
    private function storeOptions(string $key, string $challenge, string $json): void
    {
        $pending = $this->livePending($key);
        $pending[Base64UrlSafe::encodeUnpadded($challenge)] = ['at' => now()->timestamp, 'options' => $json];

        session()->put($key, array_slice($pending, -self::MAX_PENDING, null, true));
    }

    /** @return array<string, array{at: int, options: string}> ceremonias vigentes (no caducadas) de la sesion */
    private function livePending(string $key): array
    {
        $stored = session()->get($key);
        if (! is_array($stored)) {
            return [];
        }

        $ttl = (int) config('passkeys.challenge_ttl', 300);

        return array_filter(
            $stored,
            fn ($entry) => is_array($entry) && is_int($entry['at'] ?? null) && is_string($entry['options'] ?? null) && now()->timestamp - $entry['at'] <= $ttl,
        );
    }

    /**
     * Consume (un solo uso) las opciones que corresponden al reto de la respuesta.
     *
     * @template T of object
     *
     * @param  class-string<T>  $class
     * @return T
     *
     * @throws PasskeyFailure
     */
    private function pullOptions(string $key, string $class, PublicKeyCredential $credential): object
    {
        $pending = $this->livePending($key);
        $challenge = Base64UrlSafe::encodeUnpadded($credential->response->clientDataJSON->challenge);
        $entry = $pending[$challenge] ?? null;

        // Se consume ese reto y, de paso, se descartan las caducadas.
        unset($pending[$challenge]);
        session()->put($key, $pending);

        try {
            if ($entry !== null) {
                return WebAuthn::fromJson($entry['options'], $class);
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

    /** El origen que declara el navegador debe estar en la lista fija (lo mismo que exige la libreria, sin tocar la BD). */
    private function assertAllowedOrigin(PublicKeyCredential $credential, ?int $userId): void
    {
        try {
            $allowed = Passkeys::allowedOrigins();
        } catch (Throwable $e) {
            throw $this->serverFailure($e, 'origenes', $userId);
        }

        if (! in_array($credential->response->clientDataJSON->origin, $allowed, true)) {
            throw $this->domainFailure($userId);
        }
    }

    /** El hash del RP ID que firma el autenticador debe ser el de este dominio (lo mismo que `CheckRelyingPartyIdIdHash`). */
    private function assertRelyingPartyHash(PublicKeyCredential $credential, ?int $userId): void
    {
        $response = $credential->response;
        if (! $response instanceof AuthenticatorAssertionResponse) {
            return; // la libreria lo rechaza despues como respuesta invalida
        }

        try {
            $expected = hash('sha256', Passkeys::relyingPartyId(), true);
        } catch (Throwable $e) {
            throw $this->serverFailure($e, 'rp-id', $userId);
        }

        if (! hash_equals($expected, $response->authenticatorData->rpIdHash)) {
            throw $this->domainFailure($userId);
        }
    }

    private function domainFailure(?int $userId): PasskeyFailure
    {
        return new PasskeyFailure(PasskeyFailure::INVALID_DOMAIN, 'Esta passkey no es válida en este dominio.', $userId);
    }

    /**
     * Rechazo esperado de la ceremonia (respuesta invalida, contador, origen, duplicada), no un fallo del servidor. Incluye
     * lo que lanzan sin envolver las librerias de CBOR/COSE/WebAuthn al decodificar datos del cliente (p. ej. una firma
     * que no es DER: `InvalidArgumentException` de cose-lib): lo provoca quien envia la respuesta, no la configuracion.
     */
    private function isExpected(Throwable $e): bool
    {
        if ($e instanceof WebauthnException || $e instanceof InvalidPasskeyException) {
            return true;
        }

        $file = str_replace(DIRECTORY_SEPARATOR, '/', $e->getFile());

        return ! $e instanceof QueryException
            && (str_contains($file, '/vendor/web-auth/') || str_contains($file, '/vendor/spomky-labs/'));
    }

    /** La libreria rechazo el origen o el RP ID (mensajes de `CheckAllowedOrigins`/`CheckOrigin`/`CheckRelyingPartyIdIdHash`). */
    private function isDomainMismatch(Throwable $e): bool
    {
        return $e instanceof AuthenticatorResponseVerificationException
            && preg_match('/\b(origin|rpId)\b/i', $e->getMessage()) === 1;
    }

    /**
     * Error inesperado (configuracion de origenes/RP ID, BD...): queda en el log con clase y mensaje, SIN material de la
     * credencial (un QueryException lleva los bindings: solo se guarda el SQLSTATE), y el usuario recibe un mensaje
     * honesto en vez de culpar a su dispositivo.
     */
    private function serverFailure(Throwable $e, string $ceremony, ?int $userId): PasskeyFailure
    {
        Log::error('Passkeys: error inesperado en la ceremonia de '.$ceremony, [
            'exception' => $e::class,
            'message' => $e instanceof QueryException ? 'error de base de datos (SQLSTATE '.$e->getCode().')' : mb_substr($e->getMessage(), 0, 300),
            'user_id' => $userId,
        ]);

        return new PasskeyFailure(
            PasskeyFailure::SERVER_ERROR,
            'No se pudo completar la passkey por un problema del servidor; inténtalo más tarde.',
            $userId,
        );
    }

    /** Aviso en el log si en produccion el handle de usuario sale de APP_KEY en vez de su secreto propio (P11). */
    private function warnIfHandleSecretMissing(): void
    {
        if (app()->isProduction() && ! config('passkeys.user_handle_secret_explicit')) {
            Log::warning('Passkeys: PASSKEYS_USER_HANDLE_SECRET esta vacio y el handle de usuario sale de APP_KEY. Define un secreto propio (rotarlo no invalida las passkeys ya registradas, solo cambia el handle de las nuevas).');
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
