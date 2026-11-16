<?php

namespace App\Connectors\Support;

use App\Connectors\Data\PublishMedia;
use App\Connectors\Data\PublishRequest;
use App\Connectors\Data\PublishResult;
use App\Enums\ContentType;
use App\Models\SocialAccount;

/**
 * Lo que cambia de una red a otra en el flujo de contenedores (Instagram, Threads): endpoints, parametros, lectura del estado y
 * busqueda de la publicacion. La maquina de estados (crear -> esperar -> publicar, carrusel reanudable, presupuesto) vive en
 * `ContainerPublishFlow` y no se repite.
 */
interface ContainerNetwork
{
    /** Nombre de la red para los mensajes al usuario («Instagram», «Threads»). */
    public function label(): string;

    /** Tipo de contenido que se publica como carrusel. */
    public function carouselType(): ContentType;

    /** Limite de publicaciones (24 h): lanza `QuotaExceededException` si esta agotado; si no se puede consultar, no hace nada. */
    public function assertQuota(SocialAccount $account): void;

    /**
     * Parametros del contenedor de una publicacion que no es carrusel.
     *
     * @return array<string, string>
     */
    public function containerParams(PublishRequest $request): array;

    /**
     * Parametros del contenedor hijo de un carrusel.
     *
     * @return array<string, string>
     */
    public function childParams(PublishMedia $media): array;

    /**
     * Parametros del contenedor padre de un carrusel.
     *
     * @param  list<string>  $children
     * @return array<string, string>
     */
    public function parentParams(PublishRequest $request, array $children): array;

    /**
     * Crea un contenedor y devuelve su ID.
     *
     * @param  array<string, string>  $params
     */
    public function createContainer(SocialAccount $account, array $params): string;

    /**
     * Estado del contenedor: `code` normalizado (IN_PROGRESS, FINISHED, ERROR, EXPIRED o PUBLISHED) y el `detail` de la red.
     *
     * @return array{code: string, detail: ?string}
     */
    public function containerStatus(SocialAccount $account, string $containerId): array;

    /**
     * Mensaje para el usuario de un contenedor en ERROR o EXPIRED.
     *
     * @param  array{code: string, detail: ?string}  $status
     */
    public function failureMessage(array $status): string;

    /** Publica un contenedor listo y devuelve el ID de la publicacion (lanza `PermanentPublishException` si no llega). */
    public function publishContainer(SocialAccount $account, string $containerId): string;

    /** Enlace de la publicacion; accesorio: null si no se pudo obtener. */
    public function permalink(SocialAccount $account, string $mediaId): ?string;

    /** Busca en la media reciente una publicacion con el mismo texto y posterior al inicio del intento. */
    public function findRecent(SocialAccount $account, PublishRequest $request, StepBudget $budget): ?PublishResult;

    /** Localiza la publicacion de un contenedor ya `PUBLISHED` (por defecto, la media reciente; Instagram sabe buscar historias). */
    public function locatePublished(SocialAccount $account, PublishRequest $request, string $containerId, StepBudget $budget): ?PublishResult;
}
