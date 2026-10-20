<?php

namespace App\Services;

use App\Exceptions\PostValidationException;
use App\Models\Brand;
use App\Models\MediaAsset;
use App\Models\SmartLink;
use App\Models\SmartLinkBlock;
use App\Models\User;
use App\Support\SafeUrl;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/**
 * SmartLinks (PA-07): alta/edicion del microsite de una marca y contadores diarios agregados (visitas y clics) sin
 * ningun dato personal (ni IP, ni agente, ni referer).
 */
class SmartLinkService
{
    public const MAX_BLOCKS = 30;

    /**
     * Crea (o actualiza, si la marca ya tiene uno) el microsite. Los bloques con `id` de este microsite se actualizan
     * (conservan sus clics); los nuevos se crean y los que no llegan se borran.
     *
     * @param  array{slug: string, title: string, bio?: ?string, avatar_media_id?: ?int, theme_color?: ?string, theme_mode?: ?string, published?: ?bool, blocks?: list<array{id?: ?int, type: string, label: string, url?: ?string}>}  $data
     */
    public function save(User $user, Brand $brand, array $data): SmartLink
    {
        if ($brand->user_id !== $user->id) {
            throw new PostValidationException(['brand' => ['La marca no pertenece al usuario.']]);
        }

        $blocks = array_values((array) ($data['blocks'] ?? []));
        $errors = [];

        if (count($blocks) > self::MAX_BLOCKS) {
            $errors['blocks'][] = 'Maximo '.self::MAX_BLOCKS.' bloques.';
        }
        foreach ($blocks as $i => $b) {
            $type = $b['type'] ?? null;
            if (! in_array($type, [SmartLinkBlock::TYPE_LINK, SmartLinkBlock::TYPE_HEADER], true)) {
                $errors["blocks.$i.type"][] = 'Tipo de bloque no valido.';
            } elseif ($type === SmartLinkBlock::TYPE_LINK && ! SafeUrl::isSafe($b['url'] ?? null)) {
                $errors["blocks.$i.url"][] = 'La URL debe empezar por http:// o https://.';
            }
            if (trim((string) ($b['label'] ?? '')) === '') {
                $errors["blocks.$i.label"][] = 'El texto del bloque es obligatorio.';
            }
        }

        $avatarId = $data['avatar_media_id'] ?? null;
        if ($avatarId !== null) {
            $ok = MediaAsset::withoutUserScope()->where('user_id', $user->id)->where('kind', MediaAsset::KIND_IMAGE)->whereKey($avatarId)->exists();
            if (! $ok) {
                $errors['avatar_media_id'][] = 'El avatar debe ser una imagen tuya.';
            }
        }

        $existing = SmartLink::withoutUserScope()->where('user_id', $user->id)->where('brand_id', $brand->id)->first();
        $slugTaken = SmartLink::withoutUserScope()->where('slug', $data['slug'])->when($existing, fn ($q) => $q->whereKeyNot($existing->id))->exists();
        if ($slugTaken) {
            $errors['slug'][] = 'Ese enlace ya esta en uso.';
        }

        if ($errors !== []) {
            throw new PostValidationException($errors);
        }

        try {
            return DB::transaction(function () use ($user, $brand, $data, $blocks, $existing, $avatarId) {
                $link = $existing ?? new SmartLink;
                $link->fill([
                    'slug' => $data['slug'],
                    'title' => trim($data['title']),
                    'bio' => isset($data['bio']) ? trim((string) $data['bio']) ?: null : null,
                    'avatar_media_id' => $avatarId,
                    'theme_color' => $data['theme_color'] ?? '#4f46e5',
                    'theme_mode' => $data['theme_mode'] ?? 'light',
                    'published' => (bool) ($data['published'] ?? true),
                ]);
                $link->user_id = $user->id;
                $link->brand_id = $brand->id;
                $link->save();

                $current = $link->blocks()->get()->keyBy('id');
                $kept = [];
                foreach ($blocks as $position => $b) {
                    $attrs = [
                        'position' => $position,
                        'type' => $b['type'],
                        'label' => trim($b['label']),
                        'url' => $b['type'] === SmartLinkBlock::TYPE_LINK ? $b['url'] : null,
                    ];
                    $block = isset($b['id']) ? $current->get((int) $b['id']) : null;
                    if ($block) {
                        $block->update($attrs);
                        $kept[$block->id] = true;
                    } else {
                        $new = $link->blocks()->create($attrs);
                        $kept[$new->id] = true;
                    }
                }
                $link->blocks()->whereNotIn('id', array_keys($kept))->delete();

                return $link->load('blocks');
            });
        } catch (QueryException) {
            // Carrera por el slug unico (entre la comprobacion y el INSERT).
            throw new PostValidationException(['slug' => ['Ese enlace ya esta en uso.']]);
        }
    }

    public function recordView(SmartLink $link): void
    {
        $this->bump('smart_link_views', ['smart_link_id' => $link->id, 'date' => now('UTC')->toDateString()]);
    }

    public function recordClick(SmartLink $link, SmartLinkBlock $block): void
    {
        $this->bump('smart_link_clicks', ['smart_link_id' => $link->id, 'smart_link_block_id' => $block->id, 'date' => now('UTC')->toDateString()]);
    }

    /**
     * Estadisticas de los ultimos `$days` dias.
     *
     * @return array{views: int, clicks: int, by_block: array<int, int>, daily: list<array{date: string, views: int, clicks: int}>}
     */
    public function stats(SmartLink $link, int $days = 30): array
    {
        $from = now('UTC')->subDays($days - 1)->toDateString();
        $views = DB::table('smart_link_views')->where('smart_link_id', $link->id)->where('date', '>=', $from)->pluck('count', 'date');
        $clicks = DB::table('smart_link_clicks')->where('smart_link_id', $link->id)->where('date', '>=', $from)->get();

        $daily = [];
        for ($i = $days - 1; $i >= 0; $i--) {
            $d = now('UTC')->subDays($i)->toDateString();
            $daily[] = ['date' => $d, 'views' => (int) ($views[$d] ?? 0), 'clicks' => (int) $clicks->where('date', $d)->sum('count')];
        }

        return [
            'views' => (int) $views->sum(),
            'clicks' => (int) $clicks->sum('count'),
            'by_block' => $clicks->groupBy('smart_link_block_id')->map(fn ($rows) => (int) $rows->sum('count'))->all(),
            'daily' => $daily,
        ];
    }

    /** @param array<string, mixed> $key */
    private function bump(string $table, array $key): void
    {
        $query = fn () => DB::table($table)->where($key);

        if ($query()->increment('count') > 0) {
            return;
        }

        try {
            DB::table($table)->insert($key + ['count' => 1]);
        } catch (QueryException) {
            // Otra peticion creo la fila entre medias: se suma a ella.
            $query()->increment('count');
        }
    }
}
