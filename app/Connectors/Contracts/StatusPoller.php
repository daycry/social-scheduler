<?php

namespace App\Connectors\Contracts;

use App\Connectors\Data\PublishRequest;
use App\Connectors\Data\PublishResult;
use App\Models\SocialAccount;

/**
 * Capacidad de consultar el estado de una publicacion asincrona (contenedores de IG, publish_id de TikTok).
 * `$request->reference` lleva la referencia devuelta por `publish` (estado `procesando`).
 */
interface StatusPoller
{
    /** @return PublishResult publicada, aun en proceso o fallida definitivamente. */
    public function poll(SocialAccount $account, PublishRequest $request): PublishResult;
}
