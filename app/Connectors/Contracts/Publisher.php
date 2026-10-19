<?php

namespace App\Connectors\Contracts;

use App\Models\SocialAccount;

/**
 * Capacidad de publicar. La implementan los conectores en las fases 3-5 (T-21, T-22, T-26, T-27).
 */
interface Publisher
{
    /**
     * @param  array<string, mixed>  $payload  Contenido normalizado (texto, media, tipo, opciones).
     * @return array{external_id: ?string, status: string, raw?: array<string, mixed>}
     */
    public function publish(SocialAccount $account, array $payload): array;
}
