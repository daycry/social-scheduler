<?php

namespace App\Connectors\Contracts;

use App\Connectors\Data\PublishRequest;
use App\Connectors\Data\PublishResult;
use App\Connectors\Exceptions\PermanentPublishException;
use App\Connectors\Exceptions\QuotaExceededException;
use App\Connectors\Exceptions\ReconnectRequiredException;
use App\Connectors\Exceptions\TransientPublishException;
use App\Models\SocialAccount;

/**
 * Capacidad de publicar. La implementan los conectores en las fases 3-5 (T-21, T-22, T-26, T-27).
 *
 * Contrato de idempotencia: la cola puede llamar a `publish` mas de una vez para el mismo destino
 * (timeout tras enviar, caida del VPS). Antes de CUALQUIER reintento llama a `findPublished`, que
 * debe devolver el resultado si la red ya publico el contenido (o `null` si no hay constancia).
 */
interface Publisher
{
    /**
     * Publica (o inicia la publicacion asincrona).
     *
     * @throws TransientPublishException fallo pasajero.
     * @throws PermanentPublishException contenido/permiso rechazado definitivamente.
     * @throws QuotaExceededException cuota agotada.
     * @throws ReconnectRequiredException credenciales invalidas.
     */
    public function publish(SocialAccount $account, PublishRequest $request): PublishResult;

    /**
     * ¿Ya esta publicado (o en proceso) este destino? Consulta el estado externo sin crear nada nuevo.
     * Devuelve `null` si no hay constancia (o si la red no permite comprobarlo).
     */
    public function findPublished(SocialAccount $account, PublishRequest $request): ?PublishResult;
}
