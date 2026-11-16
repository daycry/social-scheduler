<?php

namespace App\Connectors\Support;

use App\Connectors\Data\PublishRequest;
use App\Connectors\Data\PublishResult;
use App\Connectors\Exceptions\PermanentPublishException;
use App\Connectors\Exceptions\TransientPublishException;
use App\Models\SocialAccount;

/**
 * Flujo de publicacion por contenedores (Instagram y Threads), con los ganchos de cada red en `ContainerNetwork`:
 *   contenedor -> espera de estado -> publicar.
 *
 * Referencia (`external_reference`) mientras el destino esta `procesando`:
 *   `carousel`   carrusel en construccion; el avance (hijos creados / listos, padre) vive en `progress` del destino
 *   `ID`         contenedor creado (unico o padre del carrusel); se publica cuando su estado es FINISHED
 * Cada ID se persiste EN EL ACTO (`PublishRequest::checkpoint`) antes del paso siguiente: un timeout al publicar o un worker caido
 * nunca pierde el contenedor ni obliga a recrearlo. Un carrusel se divide en pasos reanudables (crear hijos -> sondear hijos ->
 * crear padre -> publicar), cada uno acotado por `StepBudget` (< `$timeout` del job).
 *
 * Contenedor fallido (ERROR o EXPIRED), igual en todas las redes: se lanza `PermanentPublishException` con el codigo
 * `contenedor_error` o `contenedor_caducado` y el motivo traducido de la red. Asi el destino queda fallido con un codigo que
 * distingue la causa (y, en un carrusel, un hijo fallido tiene el mismo trato que el padre), en vez de un `rechazada` generico.
 */
class ContainerPublishFlow
{
    /** Referencia mientras un carrusel aun crea/espera a sus hijos (el avance vive en `progress`). */
    private const BUILDING = 'carousel';

    public function __construct(private readonly ContainerNetwork $network) {}

    /** Primer paso: cuota, contenedor (o carrusel) y avance. El publisher ya ha validado la peticion. */
    public function publish(SocialAccount $account, PublishRequest $request): PublishResult
    {
        $budget = $request->budget();

        if ($request->type === $this->network->carouselType()) {
            if ($request->progress === []) {
                $this->network->assertQuota($account);
                $budget->spent();
            }

            return $this->advanceCarousel($account, $request, $budget);
        }

        $this->network->assertQuota($account);
        $budget->spent();

        $containerId = $this->network->createContainer($account, $this->network->containerParams($request));
        $request->checkpoint($containerId);
        $budget->spent();

        return $this->advance($account, $request, $containerId, $budget);
    }

    public function poll(SocialAccount $account, PublishRequest $request): PublishResult
    {
        if ($request->reference === null) {
            throw new PermanentPublishException('Falta la referencia del contenedor de '.$this->network->label().'.', 'sin_referencia');
        }

        $budget = $request->budget();

        return $request->reference === self::BUILDING
            ? $this->advanceCarousel($account, $request, $budget)
            : $this->advance($account, $request, $request->reference, $budget);
    }

    public function findPublished(SocialAccount $account, PublishRequest $request): ?PublishResult
    {
        $container = $this->containerOf($request);
        $budget = $request->budget();

        // Un contenedor ya publicado (o a punto) se resuelve por su estado, sin crear otro.
        if ($container !== null) {
            $status = $this->network->containerStatus($account, $container);
            $budget->spent();

            if ($status['code'] === 'PUBLISHED') {
                return $this->resolvePublished($account, $request, $container, $budget) ?? PublishResult::processing($container, ['container_status' => 'PUBLISHED']);
            }

            if ($status['code'] === 'FINISHED' || $status['code'] === 'IN_PROGRESS') {
                // `not_published`: el contenedor existe pero nada salio aun (lo usa la reconciliacion tras una edicion).
                return PublishResult::processing($container, ['not_published' => true]);
            }
        }

        // Carrusel aun en construccion (sin padre): nada puede estar publicado; `publish` retoma desde `progress`.
        if ($request->type === $this->network->carouselType() && $container === null && $request->progress !== []) {
            return null;
        }

        // Sin presupuesto para otra consulta: NO se publica a ciegas (duplicado); el reintento vuelve a preguntar.
        if (! $budget->allows()) {
            throw new TransientPublishException('Sin tiempo para comprobar si '.$this->network->label().' ya publico el contenido; se reintenta.');
        }

        return $this->network->findRecent($account, $request, $budget);
    }

    /** Contenedor "vivo" del destino: el unico/padre, o null si el carrusel aun se construye. */
    private function containerOf(PublishRequest $request): ?string
    {
        if ($request->reference !== null && $request->reference !== self::BUILDING) {
            return $request->reference;
        }

        return isset($request->progress['parent']) ? (string) $request->progress['parent'] : null;
    }

    /** Estado -> publicar: comprueba el contenedor y, si esta listo (y cabe en el presupuesto), lo publica. */
    private function advance(SocialAccount $account, PublishRequest $request, string $containerId, StepBudget $budget): PublishResult
    {
        // Tras FINISHED vienen SIEMPRE la publicacion y el enlace sin volver a preguntar: se reservan las 2 llamadas
        // (peor caso: estado + publicar + enlace <= presupuesto < $timeout del job).
        if (! $budget->allows(after: 2)) {
            return PublishResult::processing($containerId);
        }

        $status = $this->network->containerStatus($account, $containerId);
        $budget->spent();

        return match ($status['code']) {
            'FINISHED' => $this->publishContainer($account, $containerId, $budget),
            // PUBLISHED es concluyente: el destino se da por publicado aunque no se localice la publicacion concreta.
            'PUBLISHED' => $this->resolvePublished($account, $request, $containerId, $budget) ?? PublishResult::processing($containerId),
            'ERROR', 'EXPIRED' => throw $this->containerFailure($status),
            default => PublishResult::processing($containerId), // IN_PROGRESS u otro estado intermedio
        };
    }

    /**
     * Carrusel por pasos reanudables: 1) crear los hijos que falten, 2) esperar a que todos esten FINISHED, 3) crear el padre,
     * 4) publicar. El avance se guarda tras cada llamada con `checkpoint`.
     */
    private function advanceCarousel(SocialAccount $account, PublishRequest $request, StepBudget $budget): PublishResult
    {
        $parent = $this->containerOf($request);

        if ($parent !== null) {
            return $this->advance($account, $request, $parent, $budget);
        }

        $children = array_values((array) ($request->progress['children'] ?? []));
        $ready = array_values((array) ($request->progress['ready'] ?? []));

        $building = function () use (&$children, &$ready): PublishResult {
            return PublishResult::processing(self::BUILDING, [], ['children' => $children, 'ready' => $ready]);
        };

        // 1) Hijos que faltan (cada ID se guarda al crearlo: un fallo posterior no los pierde).
        while (count($children) < count($request->media)) {
            if (! $budget->allows()) {
                return $building();
            }

            $children[] = $this->network->createContainer($account, $this->network->childParams($request->media[count($children)]));
            $budget->spent();
            $request->checkpoint(self::BUILDING, ['children' => $children, 'ready' => $ready]);
        }

        // 2) Esperar a que cada hijo termine de procesarse.
        foreach ($children as $id) {
            if (in_array($id, $ready, true)) {
                continue;
            }

            if (! $budget->allows()) {
                return $building();
            }

            $status = $this->network->containerStatus($account, $id);
            $budget->spent();

            if (in_array($status['code'], ['ERROR', 'EXPIRED'], true)) {
                throw $this->containerFailure($status);
            }

            if ($status['code'] !== 'FINISHED') {
                return $building();
            }

            $ready[] = $id;
            $request->checkpoint(self::BUILDING, ['children' => $children, 'ready' => $ready]);
        }

        // 3) Contenedor padre (su ID sustituye a `carousel` como referencia).
        if (! $budget->allows()) {
            return $building();
        }

        $parent = $this->network->createContainer($account, $this->network->parentParams($request, $children));
        $budget->spent();
        $progress = ['children' => $children, 'ready' => $ready, 'parent' => $parent];
        $request->checkpoint($parent, $progress);

        // 4) Estado del padre y, si esta listo, publicar.
        $result = $this->advance($account, new PublishRequest($request->targetId, $request->type, $request->text, $request->media, $request->options, $parent, $request->since, $progress, null), $parent, $budget);

        return $result->isProcessing() ? PublishResult::processing($parent, [], $progress) : $result;
    }

    private function publishContainer(SocialAccount $account, string $containerId, StepBudget $budget): PublishResult
    {
        $id = $this->network->publishContainer($account, $containerId);
        $budget->spent();

        // El enlace es accesorio: sin presupuesto para otra llamada se omite (la publicacion ya existe).
        return PublishResult::published($id, $budget->allows() ? $this->network->permalink($account, $id) : null, ['container' => $containerId]);
    }

    /**
     * Un contenedor `PUBLISHED` es CONCLUYENTE: si no se puede identificar la publicacion concreta, el destino se da igualmente por
     * publicado (con el ID del contenedor y una nota) y nunca por fallido, para que reprogramarlo no duplique. Devuelve null solo si
     * no queda presupuesto para consultar (el siguiente sondeo lo reintenta).
     */
    private function resolvePublished(SocialAccount $account, PublishRequest $request, string $containerId, StepBudget $budget): ?PublishResult
    {
        if (! $budget->allows()) {
            return null;
        }

        return $this->network->locatePublished($account, $request, $containerId, $budget) ?? PublishResult::published(
            $containerId,
            null,
            ['container' => $containerId, 'external_id_kind' => 'container'],
            'Se publico en '.$this->network->label().' pero no se pudo obtener el enlace ni el ID de la publicacion.',
            ['external_id_kind' => 'container'],
        );
    }

    /** @param array{code: string, detail: ?string} $status */
    private function containerFailure(array $status): PermanentPublishException
    {
        return new PermanentPublishException($this->network->failureMessage($status), $status['code'] === 'EXPIRED' ? 'contenedor_caducado' : 'contenedor_error');
    }
}
