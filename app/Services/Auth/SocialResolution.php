<?php

namespace App\Services\Auth;

use App\Models\SocialIdentity;
use App\Models\User;

/** Resultado de resolver una identidad social: el usuario que entra o el motivo (solo para auditoria) del rechazo. */
final class SocialResolution
{
    private function __construct(
        public readonly ?User $user,
        public readonly string $reason,
        public readonly ?SocialIdentity $identity = null,
    ) {}

    public static function accept(User $user, SocialIdentity $identity, string $reason = 'identity'): self
    {
        return new self($user, $reason, $identity);
    }

    public static function reject(string $reason): self
    {
        return new self(null, $reason);
    }

    public function accepted(): bool
    {
        return $this->user !== null;
    }
}
