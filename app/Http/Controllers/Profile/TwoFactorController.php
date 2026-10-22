<?php

namespace App\Http\Controllers\Profile;

use App\Http\Controllers\Controller;
use App\Http\Requests\Profile\TwoFactorConfirmRequest;
use App\Services\AuditLog;
use App\Services\TwoFactor\TotpService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Gestion del 2FA desde el perfil. Todas las rutas van tras `auth` + `password.confirm`.
 * Los secretos pendientes y los codigos de recuperacion en claro viajan SOLO como flash de sesion en la
 * respuesta de generacion: no hay ninguna ruta que permita volver a leerlos.
 */
class TwoFactorController extends Controller
{
    public function __construct(private readonly TotpService $totp, private readonly AuditLog $audit) {}

    /** Genera el secreto pendiente de confirmar y lo muestra una vez con su QR. */
    public function store(Request $request): RedirectResponse
    {
        $user = $request->user();

        if ($user->hasTwoFactorEnabled()) {
            return back()->with('status', 'El doble factor ya está activo.');
        }

        $secret = $this->totp->generateSecret();
        $user->forceFill([
            'two_factor_secret' => $secret,
            'two_factor_confirmed_at' => null,
            'two_factor_recovery_codes' => null,
            'two_factor_last_used_at' => null,
        ])->save();

        return back()->with('twoFactorSetup', $this->setup($request, $secret));
    }

    /** Valida el primer TOTP: activa el 2FA, genera los 8 codigos (una sola vez) y rota remember_token. */
    public function confirm(TwoFactorConfirmRequest $request): RedirectResponse
    {
        $user = $request->user();

        if ($user->hasTwoFactorEnabled() || $user->two_factor_secret === null) {
            return back()->with('status', 'No hay una activación pendiente.');
        }

        if (! $this->totp->verify($user, $request->validated('code'))) {
            // El secreto pendiente se conserva: se vuelve a mostrar para reintentar sin re-escanear.
            return back()
                ->withErrors(['code' => 'El código no es válido. Comprueba la hora de tu móvil e inténtalo de nuevo.'])
                ->with('twoFactorSetup', $this->setup($request, $user->two_factor_secret));
        }

        $user->forceFill(['two_factor_confirmed_at' => now()]);
        // Las sesiones «recordadas» anteriores dejan de valer y vuelven a pasar por el login.
        $user->setRememberToken(Str::random(60));
        $user->save();

        $codes = $this->totp->generateRecoveryCodes($user);
        $this->audit->record($user, 'auth.2fa.enabled', AuditLog::OK, 'user', $user->id, null, 'web');

        return back()->with('recoveryCodes', $codes);
    }

    /** Desactiva el 2FA: exige la contraseña y un TOTP o código de recuperación válido. */
    public function destroy(Request $request): RedirectResponse
    {
        $user = $request->user();

        $data = $request->validate([
            'password' => ['required', 'string', 'current_password'],
            'code' => ['nullable', 'string', 'max:32'],
            'recovery_code' => ['nullable', 'string', 'max:32'],
        ], ['password.current_password' => 'La contraseña no es correcta.']);

        if (! $user->hasTwoFactorEnabled()) {
            return back();
        }

        if (filled($data['recovery_code'] ?? null)) {
            $field = 'recovery_code';
            $valid = $this->totp->consumeRecoveryCode($user, $data['recovery_code']) !== null;
        } else {
            $field = 'code';
            $valid = filled($data['code'] ?? null) && $this->totp->verify($user, $data['code']);
        }

        if (! $valid) {
            $this->audit->record($user, 'auth.2fa.disable_failed', AuditLog::DENIED, 'user', $user->id, null, 'web');

            throw ValidationException::withMessages([$field => 'El código no es válido.']);
        }

        $user->forceFill([
            'two_factor_secret' => null,
            'two_factor_recovery_codes' => null,
            'two_factor_confirmed_at' => null,
            'two_factor_last_used_at' => null,
        ])->save();

        $this->audit->record($user, 'auth.2fa.disabled', AuditLog::OK, 'user', $user->id, null, 'web');

        return back()->with('status', 'Doble factor desactivado.');
    }

    /** Genera 8 códigos nuevos; los anteriores dejan de valer. Se muestran una sola vez. */
    public function regenerate(Request $request): RedirectResponse
    {
        $user = $request->user();

        if (! $user->hasTwoFactorEnabled()) {
            return back();
        }

        $codes = $this->totp->generateRecoveryCodes($user);
        $this->audit->record($user, 'auth.2fa.recovery_regenerated', AuditLog::OK, 'user', $user->id, null, 'web');

        return back()->with('recoveryCodes', $codes);
    }

    /** @return array{secret: string, qrSvg: string} */
    private function setup(Request $request, string $secret): array
    {
        return ['secret' => $secret, 'qrSvg' => $this->totp->qrSvg($request->user(), $secret)];
    }
}
