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

    /** La respuesta presentada al login no es una assertion (p. ej. una attestation). */
    public const INVALID_RESPONSE = 'invalid_response';

    /** Origen o RP ID que no son los de esta app (passkey de otro dominio). */
    public const INVALID_DOMAIN = 'invalid_domain';

    /** Error inesperado del servidor (configuracion, BD...): no es culpa del dispositivo ni del usuario. */
    public const SERVER_ERROR = 'server_error';

    public function __construct(public readonly string $reason, string $message, public readonly ?int $userId = null)
    {
        parent::__construct($message);
    }

    /** 500 solo para errores del servidor; el resto son rechazos de cliente (422). */
    public function status(): int
    {
        return $this->reason === self::SERVER_ERROR ? 500 : 422;
    }

    /** Cuerpo JSON para el cliente: mensaje en español y un codigo estable (la UI distingue «otro dominio»). */
    public function payload(): array
    {
        $body = ['message' => $this->getMessage()];

        if (in_array($this->reason, [self::INVALID_DOMAIN, self::SERVER_ERROR], true)) {
            $body['code'] = $this->reason;
        }

        return $body;
    }
}
