<?php

namespace App\Connectors\Data;

use Carbon\CarbonInterface;

/** Credenciales renovadas. `refreshToken` null = conservar el actual. */
final class RefreshResult
{
    public function __construct(
        public readonly string $accessToken,
        public readonly ?string $refreshToken = null,
        public readonly ?CarbonInterface $tokenExpiresAt = null,
        public readonly ?CarbonInterface $refreshExpiresAt = null,
        public readonly ?CarbonInterface $dataAccessExpiresAt = null,
    ) {}
}
