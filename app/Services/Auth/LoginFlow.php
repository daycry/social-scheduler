<?php

namespace App\Services\Auth;

use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * Cierre del primer factor de login (ADR-003): con 2FA deja un reto pendiente en sesion y redirige a el;
 * sin 2FA abre la sesion. Lo comparten la contrasena y, mas adelante, el login social.
 */
class LoginFlow
{
    public const VIA_PASSWORD = 'password';

    /** Primer factor con el que se entro (solo para la auditoria del reto 2FA): password, google, facebook. */
    public function complete(Request $request, User $user, bool $remember, string $via = self::VIA_PASSWORD): RedirectResponse
    {
        if ($user->hasTwoFactorEnabled()) {
            // Credenciales correctas pero sin sesion: queda un reto 2FA pendiente (TTL en config/auth.php).
            $request->session()->regenerate();
            $request->session()->put([
                'login.id' => $user->getKey(),
                'login.remember' => $remember,
                'login.at' => now()->timestamp,
                'login.via' => $via,
            ]);

            return redirect()->route('two-factor.challenge');
        }

        // Un reto 2FA abandonado antes de este login no debe sobrevivir: se olvida todo `login.*`.
        $request->session()->forget(['login.id', 'login.remember', 'login.at', 'login.via']);

        Auth::login($user, $remember);

        $request->session()->regenerate();

        return redirect()->intended(route('dashboard', absolute: false));
    }
}
