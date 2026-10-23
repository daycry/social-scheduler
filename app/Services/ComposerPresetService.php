<?php

namespace App\Services;

use App\Models\Brand;
use App\Models\ComposerPreset;
use App\Models\PostTarget;
use App\Models\User;

/**
 * Interruptores persistentes del compositor por (marca, red) (C-04, decision del usuario 2026-10-23):
 *  - Solo recuerdan INTERRUPTORES booleanos; titulo, tags, enlace, categoria y visibilidad no persisten.
 *  - Los escribe SOLO el compositor web (`remember`, desde `PostController`); el MCP nunca los escribe.
 *  - `brand_content_toggle` (colaboracion de pago de TikTok) NO se recuerda: la divulgacion comercial debe ser una decision
 *    explicita en cada publicacion, no un valor heredado.
 *  - Una marca de otro usuario ni se lee ni se escribe.
 */
class ComposerPresetService
{
    /** Interruptores recordables por red (la clave de cada opcion es la misma que viaja en `targets.*.options`). */
    public const REMEMBERED = [
        'instagram' => ['share_to_feed'],
        'youtube' => ['shorts_hashtag', 'made_for_kids', 'notify_subscribers', 'contains_synthetic_media'],
        'tiktok' => ['disable_comment', 'disable_duet', 'disable_stitch', 'brand_organic_toggle', 'is_aigc'],
    ];

    /**
     * Presets de la marca para los valores iniciales: `{red: {interruptor: bool}}`.
     *
     * @return array<string, array<string, bool>>
     */
    public function forBrand(User $user, Brand $brand): array
    {
        if (! $this->owns($user, $brand)) {
            return [];
        }

        $out = [];
        foreach (ComposerPreset::withoutUserScope()->where('user_id', $user->id)->where('brand_id', $brand->id)->get() as $preset) {
            $clean = $this->clean((string) $preset->network, (array) $preset->options);

            if ($clean !== []) {
                $out[(string) $preset->network] = $clean;
            }
        }

        return $out;
    }

    /**
     * Guarda los interruptores de los destinos de un post guardado desde la web. Mezcla con lo ya recordado de esa red
     * (solo cambian los interruptores que el post trae).
     *
     * @param  iterable<PostTarget>  $targets  destinos del post ya guardado
     */
    public function remember(User $user, Brand $brand, iterable $targets): void
    {
        if (! $this->owns($user, $brand)) {
            return;
        }

        foreach ($targets as $target) {
            $network = $target->network;
            $clean = $this->clean($network->value, (array) ($target->options ?? []));

            if ($clean === []) {
                continue;
            }

            $preset = ComposerPreset::withoutUserScope()->where('brand_id', $brand->id)->where('network', $network->value)->first() ?? new ComposerPreset(['network' => $network->value]);
            $preset->brand_id = $brand->id;
            $preset->user_id = $user->id;
            $preset->options = array_merge((array) $preset->options, $clean);
            $preset->save();
        }
    }

    private function owns(User $user, Brand $brand): bool
    {
        return (int) $brand->user_id === (int) $user->id;
    }

    /**
     * Solo los interruptores recordables de la red y solo booleanos reales.
     *
     * @param  array<string, mixed>  $options
     * @return array<string, bool>
     */
    private function clean(string $network, array $options): array
    {
        $out = [];
        foreach (self::REMEMBERED[$network] ?? [] as $key) {
            $value = array_key_exists($key, $options) && ! in_array($options[$key], ['', null], true)
                ? filter_var($options[$key], FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) : null;

            if ($value !== null) {
                $out[$key] = $value;
            }
        }

        return $out;
    }
}
