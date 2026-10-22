<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\AuditLog;
use App\Services\TwoFactor\TotpService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Segundo paso del login con doble factor. La sesion solo guarda login.id / login.remember / login.at
 * hasta que el usuario demuestra el segundo factor; entonces se hace Auth::login. Nunca se registra el
 * codigo (ni TOTP ni de recuperacion) en log ni en auditoria.
 */
class TwoFactorChallengeController extends Controller
{
    private const MAX_ATTEMPTS = 5;

    public function __construct(private readonly TotpService $totp, private readonly AuditLog $audit) {}

    public function create(Request $request): Response|RedirectResponse
    {
        if ($this->pendingUser($request) === null) {
            return $this->expired($request);
        }

        return Inertia::render('Auth/TwoFactorChallenge');
    }

    public function store(Request $request): RedirectResponse
    {
        $user = $this->pendingUser($request);
        if ($user === null) {
            return $this->expired($request);
        }

        $data = $request->validate([
            'code' => ['nullable', 'string', 'max:32'],
            'recovery_code' => ['nullable', 'string', 'max:32'],
        ]);

        $usingRecovery = filled($data['recovery_code'] ?? null);
        $field = $usingRecovery ? 'recovery_code' : 'code';
        $input = (string) ($data[$field] ?? '');

        if ($input === '') {
            throw ValidationException::withMessages([$field => 'Escribe el código.']);
        }

        $key = $this->throttleKey($user, $request);

        if (RateLimiter::tooManyAttempts($key, self::MAX_ATTEMPTS)) {
            $this->audit->record($user, 'auth.2fa.lockout', AuditLog::DENIED, 'user', $user->id, null, 'web');

            $seconds = RateLimiter::availableIn($key);

            throw ValidationException::withMessages([
                $field => trans('auth.throttle', ['seconds' => $seconds, 'minutes' => ceil($seconds / 60)]),
            ]);
        }

        if ($usingRecovery) {
            $remaining = $this->totp->consumeRecoveryCode($user, $input);
            $valid = $remaining !== null;
        } else {
            $remaining = null;
            $valid = $this->totp->verify($user, $input);
        }

        if (! $valid) {
            RateLimiter::hit($key);
            $this->audit->record($user, 'auth.2fa.challenge_failed', AuditLog::DENIED, 'user', $user->id, $usingRecovery ? 'recovery' : 'totp', 'web');

            throw ValidationException::withMessages([
                $field => $usingRecovery ? 'El código de recuperación no es válido.' : 'El código no es válido o ya se ha usado.',
            ]);
        }

        RateLimiter::clear($key);
        $remember = (bool) $request->session()->get('login.remember', false);
        $request->session()->forget(['login.id', 'login.remember', 'login.at']);

        Auth::login($user, $remember);
        $request->session()->regenerate();

        if ($usingRecovery) {
            $this->audit->record($user, 'auth.2fa.recovery_used', AuditLog::OK, 'user', $user->id, 'restantes: '.$remaining, 'web');
        }
        $this->audit->record($user, 'auth.2fa.challenge_passed', AuditLog::OK, 'user', $user->id, $usingRecovery ? 'recovery' : 'totp', 'web');

        return redirect()->intended(route('dashboard', absolute: false));
    }

    /** El usuario del reto pendiente, o null si no hay reto, caducó (5 min) o el usuario ya no tiene 2FA. */
    private function pendingUser(Request $request): ?User
    {
        $id = $request->session()->get('login.id');
        $at = (int) $request->session()->get('login.at', 0);

        if (! $id || $at <= 0 || now()->timestamp - $at > (int) config('auth.two_factor.challenge_ttl', 300)) {
            return null;
        }

        $user = User::find($id);

        return $user !== null && $user->hasTwoFactorEnabled() ? $user : null;
    }

    private function expired(Request $request): RedirectResponse
    {
        $request->session()->forget(['login.id', 'login.remember', 'login.at']);

        return redirect()->route('login')->with('status', 'La verificación caducó. Vuelve a escribir tu correo y contraseña.');
    }

    private function throttleKey(User $user, Request $request): string
    {
        return '2fa:'.$user->id.'|'.$request->ip();
    }
}
