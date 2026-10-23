<?php

namespace App\Http\Controllers\Profile;

use App\Http\Controllers\Auth\SocialLoginController;
use App\Http\Controllers\Controller;
use App\Services\Auth\SocialIdentityResolver;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Laravel\Socialite\Facades\Socialite;
use Symfony\Component\HttpFoundation\Response;

/**
 * Vincular y desvincular cuentas de Google y Facebook desde el perfil. Ambas rutas van tras `auth` +
 * `password.confirm` (routes/auth.php). Vincular es POST (con CSRF) y deja `social.intent=link` en sesion; el
 * callback (SocialLoginController) hace la vinculacion con la sesion iniciada. Desvincular bloquea la identidad.
 */
class SocialIdentityController extends Controller
{
    public function __construct(private readonly SocialIdentityResolver $resolver) {}

    public function link(Request $request, string $provider): Response
    {
        abort_unless(SocialLoginController::enabled($provider), 404);

        $request->session()->put('social.intent', 'link');

        $redirect = Socialite::driver($provider.'-login')->redirect();

        // Inertia no sigue una redireccion a otro origen por XHR: location fuerza la navegacion completa.
        return $request->header('X-Inertia') ? Inertia::location($redirect->getTargetUrl()) : $redirect;
    }

    public function unlink(Request $request, string $provider): RedirectResponse
    {
        $name = $provider === 'google' ? 'Google' : 'Facebook';

        if (! $this->resolver->unlink($request->user(), $provider)) {
            return back()->withErrors(['social' => "No tienes {$name} vinculado."]);
        }

        return back()->with('status', "{$name} desvinculado.");
    }
}
