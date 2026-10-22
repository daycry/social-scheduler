<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Services\AuditLog;
use Illuminate\Console\Command;

/**
 * Salida de soporte cuando un usuario pierde el movil y los codigos de recuperacion: desactiva su 2FA.
 * Queda auditado (auth.2fa.reset_by_operator). No toca la contrasena ni las API keys del MCP.
 */
class UserTwoFactorReset extends Command
{
    protected $signature = 'user:two-factor-reset {email : Correo del usuario} {--force : No pedir confirmacion}';

    protected $description = 'Desactiva el doble factor (TOTP) de un usuario y lo audita';

    public function handle(AuditLog $audit): int
    {
        $user = User::where('email', $this->argument('email'))->first();

        if ($user === null) {
            $this->error('No existe ningún usuario con ese correo.');

            return self::FAILURE;
        }

        if (! $user->hasTwoFactorEnabled() && $user->two_factor_secret === null) {
            $this->info('Ese usuario no tiene el doble factor activo; no hay nada que hacer.');

            return self::SUCCESS;
        }

        if (! $this->option('force') && ! $this->confirm("¿Desactivar el doble factor de {$user->email}?")) {
            $this->warn('Cancelado.');

            return self::FAILURE;
        }

        $user->forceFill([
            'two_factor_secret' => null,
            'two_factor_recovery_codes' => null,
            'two_factor_confirmed_at' => null,
            'two_factor_last_used_at' => null,
        ])->save();

        $audit->record($user, 'auth.2fa.reset_by_operator', AuditLog::OK, 'user', $user->id, 'cli', 'web');

        $this->info("Doble factor desactivado para {$user->email}.");

        return self::SUCCESS;
    }
}
