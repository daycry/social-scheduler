<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\LoginRequest;
use App\Services\Auth\LoginFlow;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;
use Inertia\Response;

class AuthenticatedSessionController extends Controller
{
    /**
     * Display the login view.
     */
    public function create(): Response
    {
        return Inertia::render('Auth/Login', [
            'canResetPassword' => Route::has('password.request'),
            'status' => session('status'),
            // Login social (ADR-004): solo los proveedores con credenciales; sin ellas no hay boton (CA-10).
            'socialProviders' => array_values(array_filter(SocialLoginController::PROVIDERS, SocialLoginController::enabled(...))),
        ]);
    }

    /**
     * Handle an incoming authentication request.
     */
    public function store(LoginRequest $request, LoginFlow $loginFlow): RedirectResponse
    {
        $user = $request->validateCredentials();

        return $loginFlow->complete($request, $user, $request->boolean('remember'));
    }

    /**
     * Destroy an authenticated session.
     */
    public function destroy(Request $request): RedirectResponse
    {
        Auth::guard('web')->logout();

        $request->session()->invalidate();

        $request->session()->regenerateToken();

        // Que el navegador descarte el historial cifrado (puede haber llevado secretos / codigos).
        Inertia::clearHistory();

        return redirect('/');
    }
}
