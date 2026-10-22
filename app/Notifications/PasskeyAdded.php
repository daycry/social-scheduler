<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Avisa por correo de que se ha dado de alta una passkey: es una credencial que sustituye a contrasena + 2FA, asi que
 * si la sesion estaba robada la persona afectada tiene que enterarse y poder borrarla. Solo lleva el nombre que puso
 * quien la creo, la fecha y una IP aproximada (nunca material de la credencial).
 *
 * Por cola y solo `mail`: un fallo del SMTP no puede romper el alta. Guarda datos escalares.
 */
class PasskeyAdded extends Notification implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $backoff = 120;

    public readonly string $maskedIp;

    public function __construct(public readonly string $passkeyName, public readonly string $at, ?string $ip)
    {
        $this->maskedIp = self::maskIp($ip);
    }

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        // El nombre lo escribe el usuario: sin marcado Markdown/HTML en el correo.
        $name = trim(str_replace(['[', ']', '(', ')', '<', '>', '*', '_', '`', '#', '|', '\\'], '', $this->passkeyName)) ?: 'sin nombre';

        return (new MailMessage)
            ->subject('Se ha añadido una passkey a tu cuenta')
            ->line("Se ha añadido la passkey «{$name}» a tu cuenta el {$this->at} desde una IP aproximada {$this->maskedIp}.")
            ->line('Con una passkey se puede entrar sin contraseña ni código del doble factor.')
            ->line('Si has sido tú, no tienes que hacer nada. Si no, elimínala desde tu perfil y cambia tu contraseña cuanto antes.')
            ->action('Revisar mis passkeys', route('profile.edit'));
    }

    /** IP aproximada: se oculta el ultimo octeto (IPv4) o se conservan los 4 primeros grupos (IPv6). */
    public static function maskIp(?string $ip): string
    {
        if ($ip === null || $ip === '' || filter_var($ip, FILTER_VALIDATE_IP) === false) {
            return 'desconocida';
        }
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false) {
            return preg_replace('/\.\d+$/', '.x', $ip);
        }

        return implode(':', array_slice(explode(':', $ip), 0, 4)).'::x';
    }
}
