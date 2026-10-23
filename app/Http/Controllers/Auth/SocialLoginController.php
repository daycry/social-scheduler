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
use Illuminate\Support\Facades\Log;
use Laravel\Socialite\Contracts\Provider;
use Laravel\Socialite\Contracts\User as SocialUser;
use Laravel\Socialite\Facades\Socialite;
use Laravel\Socialite\Two\InvalidStateException;
use Symfony\Component\HttpFoundation\RedirectResponse as SymfonyRedirect;
use Throwable;

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

        $response = $this->handleCallback($request, $provider);

        // Facebook anade el fragmento `#_=_` a la URL de retorno y el navegador lo hereda en las redirecciones sin
        // fragmento propio: un `#` vacio lo sustituye y la URL final queda limpia.
        if ($provider === SocialIdentity::FACEBOOK && ! str_contains($response->getTargetUrl(), '#')) {
            $response->setTargetUrl($response->getTargetUrl().'#');
        }

        return $response;
    }

    private function handleCallback(Request $request, string $provider): RedirectResponse
    {
        $request->session()->pull('social.intent', 'login');

        // Ya con sesion (p. ej. una pestana vieja): no hay nada que iniciar.
        if (Auth::check()) {
            return redirect()->intended(route('dashboard', absolute: false));
        }

        $social = $this->fetchUser($request, $provider);
        if ($social instanceof RedirectResponse) {
            return $social;
        }

        $resolution = $this->resolver->resolve($provider, $social);

        if (! $resolution->accepted()) {
            $this->audit->record(null, 'auth.social.login', AuditLog::DENIED, 'social_identity', null, 'provider='.$provider.';motivo='.$resolution->reason, 'web');

            return $this->rejected($provider);
        }

        $user = $resolution->user;
        $this->audit->record($user, 'auth.social.login', AuditLog::OK, 'social_identity', $resolution->identity?->id, 'provider='.$provider.($user->hasTwoFactorEnabled() ? ';2fa=pendiente' : ''), 'web');

        return $this->loginFlow->complete($request, $user, false, $provider);
    }

    /**
     * Canjea el code por el usuario del proveedor. Cada fallo conocido (el usuario cancela, `state` caducado o
     * invalido, proveedor caido) vuelve a /login con un mensaje legible y sin 500. Del error solo se registra la
     * clase: el mensaje de Guzzle incluye la URL con el code.
     */
    private function fetchUser(Request $request, string $provider): SocialUser|RedirectResponse
    {
        $name = $this->name($provider);

        if ($request->query('error') !== null || $request->query('error_reason') !== null) {
            return $this->failed($provider, 'cancelled', "Cancelaste el inicio de sesión con {$name}.");
        }

        try {
            return $this->driver($provider)->user();
        } catch (InvalidStateException) {
            return $this->failed($provider, 'invalid_state', "La sesión con {$name} caducó. Vuelve a intentarlo.");
        } catch (Throwable $e) {
            Log::warning('social.login.provider_error', ['provider' => $provider, 'exception' => $e::class]);

            return $this->failed($provider, 'provider_error', "No hemos podido hablar con {$name}. Inténtalo de nuevo en unos minutos.");
        }
    }

    private function failed(string $provider, string $reason, string $message): RedirectResponse
    {
        $this->audit->record(null, 'auth.social.login', AuditLog::DENIED, 'social_identity', null, 'provider='.$provider.';motivo='.$reason, 'web');

        return redirect()->route('login')->withErrors(['email' => $message]);
    }

    private function name(string $provider): string
    {
        return $provider === SocialIdentity::GOOGLE ? 'Google' : 'Facebook';
    }

    /** Mismo mensaje para todo rechazo: no revela si el email existe ni por que se rechazo. */
    private function rejected(string $provider): RedirectResponse
    {
        $name = $this->name($provider);

        return redirect()->route('login')->withErrors([
            'email' => "No hemos podido iniciar sesión con {$name}. Entra con tu contraseña y vincula {$name} desde tu perfil.",
        ]);
    }

    private function driver(string $provider): Provider
    {
        return Socialite::driver($provider.'-login');
    }
}
