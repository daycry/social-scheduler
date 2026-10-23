<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\SocialIdentity;
use App\Services\AuditLog;
use App\Services\Auth\LoginFlow;
use App\Services\Auth\SocialIdentityResolver;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Laravel\Socialite\Contracts\Provider;
use Laravel\Socialite\Facades\Socialite;
use Symfony\Component\HttpFoundation\RedirectResponse as SymfonyRedirect;

/**
 * Login social (Google y Facebook) solo para cuentas existentes (ADR-004). Sustituye a la contrasena, no al 2FA:
 * el cierre lo hace LoginFlow, que con 2FA deja el reto pendiente. Nunca crea usuarios. Con credenciales vacias
 * el proveedor esta desactivado (404). Un unico callback sin `guest` que se bifurca por `social.intent`.
 */
class SocialLoginController extends Controller
{
    public const PROVIDERS = [SocialIdentity::GOOGLE, SocialIdentity::FACEBOOK];

    public function __construct(
        private readonly SocialIdentityResolver $resolver,
        private readonly LoginFlow $loginFlow,
        private readonly AuditLog $audit,
    ) {}

    /** Tiene credenciales el proveedor de login? Vacias = desactivado (boton oculto, ruta 404). */
    public static function enabled(string $provider): bool
    {
        $config = config('services.'.$provider.'_login');

        return in_array($provider, self::PROVIDERS, true)
            && is_array($config)
            && filled($config['client_id'] ?? null)
            && filled($config['client_secret'] ?? null);
    }

    public function redirect(Request $request, string $provider): SymfonyRedirect
    {
        abort_unless(self::enabled($provider), 404);

        $request->session()->put('social.intent', 'login');

        return $this->driver($provider)->redirect();
    }

    public function callback(Request $request, string $provider): RedirectResponse
    {
        abort_unless(self::enabled($provider), 404);

        $request->session()->pull('social.intent', 'login');

        // Ya con sesion (p. ej. una pestana vieja): no hay nada que iniciar.
        if (Auth::check()) {
            return redirect()->intended(route('dashboard', absolute: false));
        }

        $social = $this->driver($provider)->user();

        $resolution = $this->resolver->resolve($provider, $social);

        if (! $resolution->accepted()) {
            $this->audit->record(null, 'auth.social.login', AuditLog::DENIED, 'social_identity', null, 'provider='.$provider.';motivo='.$resolution->reason, 'web');

            return $this->rejected($provider);
        }

        $user = $resolution->user;
        $this->audit->record($user, 'auth.social.login', AuditLog::OK, 'social_identity', $resolution->identity?->id, 'provider='.$provider.($user->hasTwoFactorEnabled() ? ';2fa=pendiente' : ''), 'web');

        return $this->loginFlow->complete($request, $user, false);
    }

    /** Mismo mensaje para todo rechazo: no revela si el email existe ni por que se rechazo. */
    private function rejected(string $provider): RedirectResponse
    {
        $name = $provider === SocialIdentity::GOOGLE ? 'Google' : 'Facebook';

        return redirect()->route('login')->withErrors([
            'email' => "No hemos podido iniciar sesión con {$name}. Entra con tu contraseña y vincula {$name} desde tu perfil.",
        ]);
    }

    private function driver(string $provider): Provider
    {
        return Socialite::driver($provider.'-login');
    }
}
