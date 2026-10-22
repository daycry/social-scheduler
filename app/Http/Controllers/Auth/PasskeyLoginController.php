<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\AuditLog;
use App\Services\Passkeys\PasskeyFailure;
use App\Services\Passkeys\PasskeyGateway;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * Login con passkey, como `guest`. La passkey (credencial discoverable, con verificacion de usuario) sustituye a
 * contrasena + 2FA: si la ceremonia verifica, se abre sesion sin reto. Las URL son las que espera el cliente
 * `@laravel/passkeys`; la respuesta es JSON `{redirect}` (el cliente navega con Inertia) o `{message}` con 422.
 *
 * Cualquier fallo de cliente devuelve el mismo mensaje y el mismo 422 (no revela si la cuenta existe); el motivo real
 * solo queda en la auditoria (`auth.passkey.login_failed`). Dos excepciones que no dependen de la cuenta: «otro dominio»
 * (`code: invalid_domain`, se comprueba antes de buscar la credencial) y el error del servidor (500, `code: server_error`). El exito lo audita el listener de `PasskeyVerified`.
 */
class PasskeyLoginController extends Controller
{
    public function __construct(private readonly PasskeyGateway $passkeys, private readonly AuditLog $audit) {}

    public function options(): JsonResponse
    {
        return response()->json(['options' => $this->passkeys->loginOptions()]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'credential' => ['required', 'array'],
            'credential.id' => ['required', 'string'],
            'credential.rawId' => ['required', 'string'],
            'credential.type' => ['required', 'string', 'in:public-key'],
            'credential.response' => ['required', 'array'],
            'remember' => ['sometimes', 'boolean'],
        ]);

        try {
            $passkey = $this->passkeys->authenticate($data['credential']);
        } catch (PasskeyFailure $e) {
            $owner = $e->userId !== null ? User::find($e->userId) : null;
            $this->audit->record($owner, 'auth.passkey.login_failed', AuditLog::DENIED, 'passkey', null, $e->reason, 'web');

            return response()->json($e->payload(), $e->status());
        }

        // Un reto 2FA pendiente de un login con contrasena a medias no sobrevive a un login ya completo.
        $request->session()->forget(['login.id', 'login.remember', 'login.at']);

        Auth::login($passkey->user, (bool) ($data['remember'] ?? false));
        $request->session()->regenerate();

        return response()->json([
            'redirect' => redirect()->intended(route('dashboard', absolute: false))->getTargetUrl(),
        ]);
    }
}
