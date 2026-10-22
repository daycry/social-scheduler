<?php

namespace App\Http\Controllers;

use App\Http\Requests\ProfileUpdateRequest;
use App\Services\TwoFactor\TotpService;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\Redirect;
use Inertia\Inertia;
use Inertia\Response;

class ProfileController extends Controller
{
    /**
     * Display the user's profile form.
     */
    public function edit(Request $request): Response
    {
        $user = $request->user();
        $pending = $user->two_factor_secret !== null && ! $user->hasTwoFactorEnabled();
        // El secreto pendiente solo se sirve con la contrasena confirmada hace poco (como las rutas de gestion, bajo
        // `password.confirm`): una sesion robada o abierta en otro dispositivo no puede leer la semilla de la activacion.
        $passwordConfirmed = Date::now()->unix() - (int) $request->session()->get('auth.password_confirmed_at', 0)
            < (int) config('auth.password_timeout', 10800);
        $revealSetup = $pending && $passwordConfirmed;

        // El historial del navegador guarda las props cifradas (secreto QR / codigos). El cifrado usa crypto.subtle, que
        // solo existe en contexto seguro: en http (salvo localhost) Inertia no podria escribir el historial y la pagina
        // se quedaria colgada, asi que alli no se activa (y entonces no hay secreto que proteger en transito tampoco).
        if ($request->secure() || in_array($request->getHost(), ['localhost', '127.0.0.1', '[::1]'], true)) {
            Inertia::encryptHistory();
        }

        return Inertia::render('Profile/Edit', [
            'mustVerifyEmail' => $request->user() instanceof MustVerifyEmail,
            'status' => session('status'),
            // Solo estado: el secreto y los codigos nunca van en props normales.
            'twoFactor' => [
                'enabled' => $request->user()->hasTwoFactorEnabled(),
                'pending' => $pending,
                // Pendiente pero sin confirmacion reciente: la UI pide la contrasena para volver a mostrar el QR.
                'setupLocked' => $pending && ! $revealSetup,
                'recoveryRemaining' => count($request->user()->two_factor_recovery_codes ?? []),
            ],
            // Activacion a medias: el secreto sale del propio usuario (cifrado en BD), nunca de un flash de sesion.
            'twoFactorSetup' => $revealSetup ? [
                'secret' => $user->two_factor_secret,
                'qrSvg' => app(TotpService::class)->qrSvg($user, $user->two_factor_secret),
            ] : null,
            // Los codigos en claro viajan una sola vez (flash).
            'recoveryCodes' => session('recoveryCodes'),
            // Passkeys: solo lo que pinta la lista (nada de credential_id, clave publica ni contador). `confirmed` dice si
            // el alta/renombrado/borrado pasan ya por `password.confirm` o hay que pedir la contrasena antes (sin 423 visible).
            'passkeys' => [
                'confirmed' => $passwordConfirmed,
                'items' => $user->passkeys()->orderBy('created_at')->orderBy('id')->get(['id', 'name', 'created_at', 'last_used_at'])
                    ->map(fn ($p) => [
                        'id' => $p->id,
                        'name' => $p->name,
                        'created_at' => $p->created_at?->toIso8601String(),
                        'last_used_at' => $p->last_used_at?->toIso8601String(),
                    ])->all(),
            ],
        ]);
    }

    /**
     * Update the user's profile information.
     */
    public function update(ProfileUpdateRequest $request): RedirectResponse
    {
        $request->user()->fill($request->validated());

        if ($request->user()->isDirty('email')) {
            $request->user()->email_verified_at = null;
        }

        $request->user()->save();

        return Redirect::route('profile.edit');
    }

    /**
     * Delete the user's account.
     */
    public function destroy(Request $request): RedirectResponse
    {
        $request->validate([
            'password' => ['required', 'current_password'],
        ]);

        $user = $request->user();

        Auth::logout();

        $user->delete();

        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return Redirect::to('/');
    }
}
