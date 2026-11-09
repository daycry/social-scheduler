<?php

namespace App\Services;

use App\Connectors\Data\PublishMedia;
use App\Connectors\Data\PublishRequest;
use App\Models\MediaAsset;
use App\Models\Post;
use App\Models\PostTarget;
use App\Models\PublishAttempt;
use App\Support\TargetMedia;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

/** Construye la peticion normalizada de un destino (texto, media con URL firmada, opciones). */
class PublishRequestFactory
{
    public function make(PostTarget $target): PublishRequest
    {
        $post = Post::withoutUserScope()->withTrashed()->findOrFail($target->post_id);
        $ttl = (int) config('publishing.media_url_ttl_minutes', 720);
        $target->setRelation('post', $post); // el texto efectivo usa este post (tambien si esta en la papelera)

        $media = $this->effectiveMedia($target, $post, $ttl);

        return new PublishRequest(
            targetId: $target->id,
            type: $target->content_type,
            // Texto EFECTIVO del destino (ADR-005): el propio si lo tiene y, si no, el de la plantilla. Es lo que se publica Y lo que
            // `findPublished` compara al reintentar.
            text: $target->effectiveText(),
            media: $media,
            options: $target->options ?? [],
            reference: $target->external_reference,
            // Margen de 5 min antes de la hora programada Y del primer intento de este destino: desde ahi se busca lo ya publicado
            // al reintentar. Si la fecha se movio despues (p. ej. «Publicar ahora»), lo publicado en un intento ambiguo anterior
            // sigue dentro de la ventana y no se duplica.
            since: $this->searchWindowStart($target, $post->scheduled_at),
            progress: array_diff_key((array) ($target->progress ?? []), ['reconcile' => 1]),
            checkpoint: function (?string $reference, ?array $progress) use ($target): void {
                $changes = array_filter(['external_reference' => $reference, 'progress' => $progress], fn ($v) => $v !== null);
                if ($changes !== []) {
                    // Escritura directa y minima: sin cambio de estado, para sobrevivir a un fallo del paso siguiente.
                    $json = isset($changes['progress']) ? ['progress' => json_encode($changes['progress'])] : [];
                    PostTarget::withoutUserScope()->whereKey($target->id)->update($json + $changes + ['updated_at' => now()]);
                }
            },
        );
    }

    /**
     * Media EFECTIVA del destino (T-17): la propia (mismos ids, orden, alt y portada guardados) o la comun del post. Es la unica
     * forma de resolver la media que se publica; la validacion usa la misma fuente (`TargetMedia::effective`).
     *
     * @return list<PublishMedia>
     */
    private function effectiveMedia(PostTarget $target, Post $post, int $ttl): array
    {
        $commonIds = $target->media === null ? $post->media()->pluck('media_assets.id')->map(fn ($id) => (int) $id)->all() : [];
        $items = TargetMedia::effective($target, $commonIds);
        $ids = array_values(array_unique(array_filter(array_merge(array_column($items, 'id'), array_column($items, 'cover_id')))));
        $assets = MediaAsset::withoutUserScope()->whereIn('id', $ids)->get()->keyBy('id');

        $out = [];
        foreach ($items as $item) {
            $asset = $assets->get($item['id']);
            if ($asset === null) {
                continue;
            }
            $cover = $item['cover_id'] !== null ? $assets->get($item['cover_id']) : null;
            $out[] = $this->publishMedia($asset, $ttl, $item['alt'], $cover !== null ? $this->publishMedia($cover, $ttl) : null);
        }

        return $out;
    }

    private function publishMedia(MediaAsset $m, int $ttl, ?string $alt = null, ?PublishMedia $cover = null): PublishMedia
    {
        return new PublishMedia(
            id: $m->id,
            kind: $m->kind,
            mime: $m->mime,
            url: $m->publicUrl($ttl),
            width: $m->width,
            height: $m->height,
            duration: $m->duration,
            fps: $m->fps,
            size: $m->size,
            disk: $m->disk,
            path: $m->path,
            source: $m->source,
            alt: $alt,
            cover: $cover,
        );
    }

    /** Inicio de la ventana donde se busca lo ya publicado: 5 min antes de la hora programada o del primer intento, el que sea anterior. */
    public function searchWindowStart(PostTarget $target, ?CarbonInterface $scheduledAt): ?CarbonInterface
    {
        $first = PublishAttempt::withoutUserScope()->where('post_target_id', $target->id)->where('attempt_no', 1)->latest('id')->value('started_at');
        $first = $first !== null ? CarbonImmutable::parse($first) : null;
        $start = $scheduledAt !== null && $first !== null ? ($first->lt($scheduledAt) ? $first : $scheduledAt) : ($scheduledAt ?? $first);

        return $start?->copy()->subMinutes(5);
    }

    private function earliest(?CarbonInterface $a, ?CarbonInterface $b): ?CarbonInterface
    {
        return $a !== null && $b !== null ? ($a->lt($b) ? $a : $b) : ($a ?? $b);
    }

    /**
     * Peticion para reconciliar un intento anterior a una edicion: lleva la referencia, el progreso, el texto y la ventana
     * del contenido VIEJO (guardados en `progress.reconcile`), y comparte el presupuesto de tiempo de `$base`.
     *
     * @param  array<string, mixed>  $reconcile
     */
    public function makeReconcile(PublishRequest $base, array $reconcile): PublishRequest
    {
        return new PublishRequest(
            targetId: $base->targetId,
            type: $base->type,
            text: $reconcile['text'] ?? $base->text,
            media: $base->media,
            options: $base->options,
            reference: $reconcile['reference'] ?? null,
            // La ventana del contenido viejo nunca empieza DESPUES del primer intento del destino (`$base->since` ya lo incluye): un `since`
            // guardado con una fecha reprogramada despues de un intento ambiguo perderia lo publicado entonces (revision F2 i2, N1).
            since: $this->earliest(isset($reconcile['since']) ? CarbonImmutable::parse($reconcile['since']) : null, $base->since),
            progress: (array) ($reconcile['progress'] ?? []),
            budget: $base->budget(),
        );
    }
}
