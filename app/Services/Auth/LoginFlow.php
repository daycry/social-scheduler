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
    public function complete(Request $request, User $user, bool $remember): RedirectResponse
    {
        if ($user->hasTwoFactorEnabled()) {
            // Credenciales correctas pero sin sesion: queda un reto 2FA pendiente (TTL en config/auth.php).
            $request->session()->regenerate();
            $request->session()->put([
                'login.id' => $user->getKey(),
                'login.remember' => $remember,
                'login.at' => now()->timestamp,
            ]);

            return redirect()->route('two-factor.challenge');
        }

        Auth::login($user, $remember);

        $request->session()->regenerate();

        return redirect()->intended(route('dashboard', absolute: false));
    }
}
