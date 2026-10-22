<?php

namespace App\Http\Controllers;

use App\Http\Requests\ProfileUpdateRequest;
use App\Services\TwoFactor\TotpService;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
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

        // El historial del navegador guarda las props cifradas (secreto QR / codigos de recuperacion).
        Inertia::encryptHistory();

        return Inertia::render('Profile/Edit', [
            'mustVerifyEmail' => $request->user() instanceof MustVerifyEmail,
            'status' => session('status'),
            // Solo estado: el secreto y los codigos nunca van en props normales.
            'twoFactor' => [
                'enabled' => $request->user()->hasTwoFactorEnabled(),
                'pending' => $pending,
                'recoveryRemaining' => count($request->user()->two_factor_recovery_codes ?? []),
            ],
            // Activacion a medias: el secreto sale del propio usuario (cifrado en BD), nunca de un flash de sesion.
            'twoFactorSetup' => $pending ? [
                'secret' => $user->two_factor_secret,
                'qrSvg' => app(TotpService::class)->qrSvg($user, $user->two_factor_secret),
            ] : null,
            // Los codigos en claro viajan una sola vez (flash).
            'recoveryCodes' => session('recoveryCodes'),
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
