<?php

namespace App\Listeners;

use App\Models\User;
use App\Services\AuditLog;
use Laravel\Passkeys\Events\PasskeyDeleted;
use Laravel\Passkeys\Events\PasskeyRegistered;
use Laravel\Passkeys\Events\PasskeyVerified;

/**
 * Auditoria de los eventos del paquete `laravel/passkeys`. Solo guarda el id de la passkey: nunca el credential_id,
 * la clave publica ni nada de la credencial. (El nombre lo pone el usuario y tampoco se registra.)
 */
class AuditPasskeyEvents
{
    public function __construct(private readonly AuditLog $audit) {}

    public function registered(PasskeyRegistered $event): void
    {
        $this->record($event->user, 'auth.passkey.registered', $event->passkey->id);
    }

    public function verified(PasskeyVerified $event): void
    {
        $this->record($event->user, 'auth.passkey.login', $event->passkey->id);
    }

    public function deleted(PasskeyDeleted $event): void
    {
        $this->record($event->user, 'auth.passkey.deleted', $event->passkey->id);
    }

    private function record(mixed $user, string $action, int $passkeyId): void
    {
        $this->audit->record($user instanceof User ? $user : null, $action, AuditLog::OK, 'passkey', $passkeyId, null, 'web');
    }
}
