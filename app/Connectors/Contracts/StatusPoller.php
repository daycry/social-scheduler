<?php

namespace App\Connectors\Contracts;

use App\Models\SocialAccount;

/**
 * Capacidad de consultar el estado de una publicacion asincrona (contenedores de IG, publish_id de TikTok).
 */
interface StatusPoller
{
    /** @return array{status: string, external_id: ?string, raw?: array<string, mixed>} */
    public function poll(SocialAccount $account, string $reference): array;
}
