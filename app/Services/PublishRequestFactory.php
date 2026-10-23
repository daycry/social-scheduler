<?php

namespace App\Services;

use App\Connectors\Data\PublishMedia;
use App\Connectors\Data\PublishRequest;
use App\Models\MediaAsset;
use App\Models\Post;
use App\Models\PostTarget;
use Carbon\CarbonImmutable;

/** Construye la peticion normalizada de un destino (texto, media con URL firmada, opciones). */
class PublishRequestFactory
{
    public function make(PostTarget $target): PublishRequest
    {
        $post = Post::withoutUserScope()->withTrashed()->findOrFail($target->post_id);
        $ttl = (int) config('publishing.media_url_ttl_minutes', 720);

        $media = $post->media()->get()->map(fn (MediaAsset $m) => new PublishMedia(
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
        ))->all();

        return new PublishRequest(
            targetId: $target->id,
            type: $target->content_type,
            text: $post->text,
            media: $media,
            options: $target->options ?? [],
            reference: $target->external_reference,
            // Margen de 5 min antes de la hora programada: desde ahi se busca lo ya publicado al reintentar.
            since: $post->scheduled_at?->copy()->subMinutes(5),
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
            since: isset($reconcile['since']) ? CarbonImmutable::parse($reconcile['since']) : $base->since,
            progress: (array) ($reconcile['progress'] ?? []),
            budget: $base->budget(),
        );
    }
}
