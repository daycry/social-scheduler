<?php

use App\Http\Controllers\Auth\AuthenticatedSessionController;
use App\Http\Controllers\Auth\ConfirmablePasswordController;
use App\Http\Controllers\Auth\EmailVerificationNotificationController;
use App\Http\Controllers\Auth\EmailVerificationPromptController;
use App\Http\Controllers\Auth\NewPasswordController;
use App\Http\Controllers\Auth\PasskeyLoginController;
use App\Http\Controllers\Auth\PasswordController;
use App\Http\Controllers\Auth\PasswordResetLinkController;
use App\Http\Controllers\Auth\RegisteredUserController;
use App\Http\Controllers\Auth\TwoFactorChallengeController;
use App\Http\Controllers\Auth\VerifyEmailController;
use App\Http\Controllers\Profile\PasskeyController;
use App\Http\Controllers\Profile\TwoFactorController;
use Illuminate\Support\Facades\Route;

Route::middleware('guest')->group(function () {
    if (config('app.registration_enabled', true)) {
        Route::get('register', [RegisteredUserController::class, 'create'])
            ->name('register');

        Route::post('register', [RegisteredUserController::class, 'store']);
    }

    Route::get('login', [AuthenticatedSessionController::class, 'create'])
        ->name('login');

    Route::post('login', [AuthenticatedSessionController::class, 'store']);

    Route::get('two-factor-challenge', [TwoFactorChallengeController::class, 'create'])
        ->name('two-factor.challenge');

    Route::post('two-factor-challenge', [TwoFactorChallengeController::class, 'store'])
        ->name('two-factor.challenge.store');

    // Login con passkey: sustituye a contrasena + 2FA. `block`: las peticiones de una misma sesion se serializan, para
    // que dos pestanas o el autofill y el boton no se pisen los retos pendientes al leer y escribir la sesion.
    // Login con passkey (cont.): Limitadores separados por IP: el GET de opciones (lo pide el
    // autofill en cada carga de Login) con holgura, y el envio de la assertion (10/min) para frenar la fuerza bruta.
    Route::get('passkeys/login/options', [PasskeyLoginController::class, 'options'])->middleware('throttle:passkey-login-options')->block(5, 5)->name('passkeys.login.options');
    Route::post('passkeys/login', [PasskeyLoginController::class, 'store'])->middleware('throttle:passkey-login')->block(5, 5)->name('passkeys.login');

    Route::get('forgot-password', [PasswordResetLinkController::class, 'create'])
        ->name('password.request');

    Route::post('forgot-password', [PasswordResetLinkController::class, 'store'])
        ->name('password.email');

    Route::get('reset-password/{token}', [NewPasswordController::class, 'create'])
        ->name('password.reset');

    Route::post('reset-password', [NewPasswordController::class, 'store'])
        ->name('password.store');
});

Route::middleware('auth')->group(function () {
    Route::get('verify-email', EmailVerificationPromptController::class)
        ->name('verification.notice');

    Route::get('verify-email/{id}/{hash}', VerifyEmailController::class)
        ->middleware(['signed', 'throttle:6,1'])
        ->name('verification.verify');

    Route::post('email/verification-notification', [EmailVerificationNotificationController::class, 'store'])
        ->middleware('throttle:6,1')
        ->name('verification.send');

    Route::get('confirm-password', [ConfirmablePasswordController::class, 'show'])
        ->name('password.confirm');

    Route::post('confirm-password', [ConfirmablePasswordController::class, 'store']);

    Route::put('password', [PasswordController::class, 'update'])->name('password.update');

    // Gestion del 2FA (perfil): siempre tras confirmar la contrasena recientemente.
    Route::middleware('password.confirm')->prefix('user/two-factor')->name('two-factor.')->group(function () {
        Route::post('/', [TwoFactorController::class, 'store'])->name('enable');
        Route::post('confirm', [TwoFactorController::class, 'confirm'])->middleware('throttle:two-factor-manage')->name('confirm');
        Route::delete('pending', [TwoFactorController::class, 'cancel'])->name('cancel');
        Route::delete('/', [TwoFactorController::class, 'destroy'])->middleware('throttle:two-factor-manage')->name('disable');
        Route::post('recovery-codes', [TwoFactorController::class, 'regenerate'])->middleware('throttle:two-factor-manage')->name('recovery-codes');
    });

    // Gestion de passkeys (perfil): tras confirmar la contrasena. {id} de 1 a 18 digitos (mas desborda int: 404, no 500). `id` (no `passkey`) para esquivar el binding global del paquete.
    Route::middleware(['password.confirm', 'throttle:passkey-manage'])->prefix('user/passkeys')->name('passkeys.')->group(function () {
        Route::get('confirm', [PasskeyController::class, 'confirm'])->name('confirm');
        Route::get('options', [PasskeyController::class, 'options'])->block(5, 5)->name('options');
        Route::post('/', [PasskeyController::class, 'store'])->block(5, 5)->name('store');
        Route::patch('{id}', [PasskeyController::class, 'update'])->where('id', '[0-9]{1,18}')->name('update');
        Route::delete('{id}', [PasskeyController::class, 'destroy'])->where('id', '[0-9]{1,18}')->name('destroy');
    });

    Route::post('logout', [AuthenticatedSessionController::class, 'destroy'])
        ->name('logout');
});
