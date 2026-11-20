<?php

namespace App\Services\Posts;

use App\Models\MediaAsset;
use App\Models\Post;
use App\Models\PostTarget;
use App\Support\TargetMedia;

/**
 * Huella del contenido de una publicacion: lo que fija una aprobacion (texto, media comun y destinos con su configuracion; no la fecha).
 * La del post guardado y la del plan que se va a guardar se calculan con la misma forma, asi que son comparables.
 */
class PostSignature
{
    /** Huella del contenido actual del post (la que el aprobador debe haber visto). */
    public function ofPost(Post $post): string
    {
        return $this->hash(
            $post->text,
            $post->media()->pluck('media_assets.id')->map(fn ($id) => (int) $id)->all(),
            $post->targets()->get()->map(fn (PostTarget $t) => [
                'a' => (int) $t->social_account_id, 't' => $t->content_type->value, 'o' => $t->options ?? [], 'x' => $t->text, 'm' => TargetMedia::normalize($t->media),
                'f' => $t->first_comment, 'u' => (bool) $t->manual,
            ])->all(),
        );
    }

    /**
     * Huella del contenido que dejaria un plan de `PostPlanner::plan()`.
     *
     * @param  array<string, mixed>  $plan
     */
    public function ofPlan(array $plan): string
    {
        return $this->hash(
            $plan['text'],
            array_map(fn (MediaAsset $m) => (int) $m->id, $plan['media']),
            array_map(fn (array $t) => [
                'a' => (int) $t['account']->id, 't' => $t['type']->value, 'o' => $t['options'] ?? [], 'x' => $t['text'], 'm' => $t['media'],
                'f' => $t['first_comment'] ?? null, 'u' => (bool) ($t['manual'] ?? false),
            ], $plan['targets']),
        );
    }

    /**
     * @param  list<int>  $media
     * @param  list<array<string, mixed>>  $targets
     */
    private function hash(?string $text, array $media, array $targets): string
    {
        $norm = function (mixed $v) use (&$norm) {
            if (is_array($v)) {
                $v = array_map($norm, $v);
                if (! array_is_list($v)) {
                    ksort($v);
                }
            }

            return $v;
        };
        $targets = array_map($norm, $targets);
        usort($targets, fn ($a, $b) => [$a['a'], $a['t']] <=> [$b['a'], $b['t']]);

        return hash('sha256', json_encode([(string) $text, $media, $targets], JSON_UNESCAPED_UNICODE));
    }
}
