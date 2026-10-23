<?php

use App\Models\User;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Auth\Notifications\VerifyEmail;

/** Los correos transaccionales de Laravel salen en español y firmados con el nombre de la app (lang/es.json). */
test('el correo de verificación está en español con el nombre de la app', function () {
    $user = User::factory()->unverified()->create();

    $mail = (new VerifyEmail)->toMail($user);
    $html = (string) $mail->render();

    expect($mail->subject)->toBe('Verifica tu correo electrónico')
        ->and($html)->toContain('Verificar correo electrónico')
        ->and($html)->toContain('¡Hola!')
        ->and($html)->toContain('Un saludo')
        ->and($html)->toContain(config('app.name'))
        ->and($html)->not->toContain('Verify Email Address')
        ->and($html)->not->toContain('Regards');
});

test('el correo de restablecer contraseña está en español', function () {
    $user = User::factory()->create();

    $mail = (new ResetPassword('token-de-prueba'))->toMail($user);
    $html = (string) $mail->render();

    expect($mail->subject)->toBe('Restablece tu contraseña')
        ->and($html)->toContain('Restablecer contraseña')
        ->and($html)->toContain('caducará en')
        ->and($html)->not->toContain('Reset Password')
        ->and($html)->not->toContain('You are receiving');
});
