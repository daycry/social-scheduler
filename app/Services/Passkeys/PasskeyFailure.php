<?php

namespace App\Services\Passkeys;

use RuntimeException;

/**
 * Fallo de una ceremonia de passkeys, ya traducido a un motivo estable y a un mensaje que no revela nada de la
 * cuenta ni de la credencial. `reason` y `userId` son solo para la auditoria del servidor (nunca viajan al cliente).
 */
class PasskeyFailure extends RuntimeException
{
    public const SESSION_EXPIRED = 'session_expired';

    public const INVALID_FORMAT = 'invalid_format';

    public const UNKNOWN_CREDENTIAL = 'unknown_credential';

    public const VERIFICATION_FAILED = 'verification_failed';

    public const SIGN_COUNT_REGRESSION = 'sign_count_regression';

    public const REGISTRATION_REJECTED = 'registration_rejected';

    public function __construct(public readonly string $reason, string $message, public readonly ?int $userId = null)
    {
        parent::__construct($message);
    }
}
