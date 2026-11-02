<?php

namespace App\Connectors\Meta;

/**
 * Tablas de clasificacion de errores de Graph que comparten GraphClient (Facebook e Instagram) y ThreadsClient. Solo los codigos de
 * credencial y de limite de uso; los subcodigos de Instagram se quedan en GraphClient.
 */
final class GraphErrors
{
    /** Codigos de error de Graph que significan "credencial invalida/revocada/caducada". */
    public const REAUTH_CODES = [102, 190, 458, 459, 460, 463, 464, 467];

    /**
     * Codigos de limite de uso: 4 (app), 17 (usuario), 32 (pagina), 613 (llamadas) y la familia 800xx de los limites por
     * caso de uso de negocio (80000 IG, 80001 paginas, 80002 ... 80014): transitorios, nunca "metrica no disponible".
     */
    public const RATE_LIMIT_CODES = [4, 17, 32, 613, 80000, 80001, 80002, 80003, 80004, 80005, 80006, 80008, 80009, 80014];

    public static function needsReconnect(int $code): bool
    {
        return in_array($code, self::REAUTH_CODES, true);
    }

    public static function isRateLimit(int $code): bool
    {
        return in_array($code, self::RATE_LIMIT_CODES, true);
    }
}
