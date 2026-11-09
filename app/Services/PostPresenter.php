<?php

namespace App\Services;

use App\Models\Post;
use App\Models\PostTarget;
use App\Support\TargetMedia;

/** Representacion unica de un post para la API REST y el MCP (misma forma en ambos canales, T-17). */
class PostPresenter
{
    /** @return array<string, mixed> */
    public function present(Post $post): array
    {
        $post->loadMissing(['brand', 'campaign', 'targets', 'media']);
        $tz = $post->brand?->timezone ?: 'UTC';
        $targets = $post->targets;

        return [
            'id' => $post->id,
            'brand_id' => $post->brand_id,
            'brand_name' => $post->brand?->name,
            'text' => $post->text,
            'notes' => $post->notes, // internas: nunca se publican
            'campaign_id' => $post->campaign_id,
            'campaign' => $post->campaign?->name,
            'status' => $post->status->value,
            'scheduled_at_utc' => $post->scheduled_at?->copy()->utc()->toIso8601String(),
            'scheduled_at_local' => $post->scheduled_at?->copy()->setTimezone($tz)->format('Y-m-d\TH:i'),
            'timezone' => $tz,
            'editable' => ! $targets->contains(fn (PostTarget $t) => $t->status->isInFlight() || $t->status->isFinal()),
            'media_ids' => $post->media->pluck('id')->values()->all(),
            'targets' => $targets->map(fn (PostTarget $t) => [
                'account_id' => $t->social_account_id,
                'network' => $t->network->value,
                'content_type' => $t->content_type->value,
                // `text` null = vinculado a la plantilla; `effective_text` es lo que realmente se publica en este destino.
                'text' => $t->text,
                'effective_text' => $t->text ?? $post->text,
                // Primer comentario (C-01b): su estado es independiente del estado de publicacion del destino.
                'first_comment' => $t->first_comment,
                'first_comment_status' => $t->first_comment_status?->value,
                'first_comment_error' => $t->first_comment_error,
                'first_comment_may_exist' => $t->first_comment_sent_at !== null, // se intento enviar: puede existir en la red aunque el estado sea fallido
                // Media del destino (T-17): `media` null = hereda la comun; `effective_media` es lo que realmente se publica (ids en orden con su alt y portada).
                'media' => $t->media,
                'effective_media' => TargetMedia::effective($t, $post->media->pluck('id')->all()),
                'options' => $t->options ?? [],
                'status' => $t->status->value,
                'error_message' => $t->error_message,
                'status_note' => $t->status_note,
                'external_url' => $t->external_url,
            ])->values()->all(),
        ];
    }
}
