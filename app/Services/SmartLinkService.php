<?php

namespace App\Services;

use App\Enums\PostStatus;
use App\Exceptions\PostValidationException;
use App\Models\Brand;
use App\Models\MediaAsset;
use App\Models\PostTarget;
use App\Models\SmartLink;
use App\Models\SmartLinkBlock;
use App\Models\SmartLinkSubscriber;
use App\Models\User;
use App\Notifications\SmartLinkSubscribersFull;
use App\Scopes\UserScope;
use App\Support\SafeUrl;
use Illuminate\Database\QueryException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * SmartLinks (PA-07): alta/edicion del microsite de una marca y contadores diarios agregados (visitas y clics) sin
 * ningun dato personal (ni IP, ni agente, ni referer).
 */
class SmartLinkService
{
    public const MAX_BLOCKS = 30;

    public const MESSAGES = ['slug.regex' => 'Usa de 3 a 40 caracteres: minusculas, numeros y guiones (sin guion al principio ni al final).'];

    /**
     * Reglas de validacion de `save()` (sin la marca): las comparten la web y la API v1.
     *
     * @return array<string, mixed>
     */
    public static function rules(?int $ignoreId): array
    {
        return [
            'slug' => ['required', 'string', 'regex:/^'.SmartLink::SLUG_PATTERN.'$/', Rule::unique('smart_links', 'slug')->ignore($ignoreId)],
            'title' => ['required', 'string', 'max:100'],
            'bio' => ['nullable', 'string', 'max:300'],
            'avatar_media_id' => ['nullable', 'integer'],
            'theme_color' => ['nullable', 'regex:/^#[0-9a-fA-F]{6}$/'],
            'theme_mode' => ['nullable', 'in:light,dark'],
            'published' => ['nullable', 'boolean'],
            'blocks' => ['nullable', 'array', 'max:'.self::MAX_BLOCKS],
            'blocks.*.id' => ['nullable', 'integer'],
            'blocks.*.type' => ['required', 'in:'.implode(',', SmartLinkBlock::TYPES)],
            'blocks.*.label' => ['nullable', 'string', 'max:100'],
            'blocks.*.url' => ['nullable', 'string', 'max:2000'],
            'blocks.*.media_id' => ['nullable', 'integer'],
            'blocks.*.data' => ['nullable', 'array'],
            'blocks.*.data.items' => ['nullable', 'array', 'max:8'],
            'blocks.*.data.items.*.network' => ['nullable', 'string', 'max:20'],
            'blocks.*.data.items.*.url' => ['nullable', 'string', 'max:2000'],
            'blocks.*.data.cta' => ['nullable', 'string', 'max:30'],
            'blocks.*.data.consent' => ['nullable', 'string', 'max:200'],
            'blocks.*.data.count' => ['nullable', 'integer', 'min:1', 'max:50'],
            'blocks.*.visible_from' => ['nullable', 'string', 'max:40'],
            'blocks.*.visible_until' => ['nullable', 'string', 'max:40'],
        ];
    }

    /**
     * Representacion unica del microsite para la web y la API v1 (horas de visibilidad en la zona de la marca).
     *
     * @return array<string, mixed>
     */
    public function present(SmartLink $link, Brand $brand): array
    {
        $tz = $brand->timezone ?: 'UTC';

        return [
            'id' => $link->id,
            'slug' => $link->slug,
            'title' => $link->title,
            'bio' => $link->bio,
            'avatar_media_id' => $link->avatar_media_id,
            'theme_color' => $link->theme_color,
            'theme_mode' => $link->theme_mode,
            'published' => $link->published,
            'public_url' => $link->url(),
            'blocks' => $link->blocks->map(fn ($b) => [
                'id' => $b->id, 'type' => $b->type, 'label' => $b->label, 'url' => $b->url, 'media_id' => $b->media_id, 'data' => $b->data,
                'visible_from' => $b->visible_from?->setTimezone($tz)->format('Y-m-d\TH:i'),
                'visible_until' => $b->visible_until?->setTimezone($tz)->format('Y-m-d\TH:i'),
                'visibility' => $b->visible_from && $b->visible_from > now() ? 'scheduled' : ($b->visible_until && $b->visible_until <= now() ? 'expired' : 'visible'),
            ])->values()->all(),
        ];
    }

    /**
     * Crea (o actualiza, si la marca ya tiene uno) el microsite. Los bloques con `id` de este microsite se actualizan
     * (conservan sus clics); los nuevos se crean y los que no llegan se borran.
     *
     * @param  array{slug: string, title: string, bio?: ?string, avatar_media_id?: ?int, theme_color?: ?string, theme_mode?: ?string, published?: ?bool, blocks?: list<array{id?: ?int, type: string, label?: ?string, url?: ?string, media_id?: ?int, data?: ?array, visible_from?: ?string, visible_until?: ?string}>}  $data
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
        $prepared = [];
        $emails = 0;
        foreach ($blocks as $i => $b) {
            [$attrs, $blockErrors] = $this->prepareBlock($b, $user, $brand);
            foreach ($blockErrors as $field => $messages) {
                $errors["blocks.$i.$field"] = $messages;
            }
            $emails += ($attrs['type'] ?? null) === SmartLinkBlock::TYPE_EMAIL ? 1 : 0;
            $prepared[$i] = $attrs;
        }
        if ($emails > 1) {
            $errors['blocks'][] = 'Solo puede haber un formulario de email.';
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
            $errors['slug'][] = 'Ese enlace ya está en uso.';
        }

        if ($errors !== []) {
            throw new PostValidationException($errors);
        }

        try {
            return DB::transaction(function () use ($user, $brand, $data, $blocks, $prepared, $existing, $avatarId) {
                $link = $existing ?? new SmartLink;
                $link->fill([
                    'slug' => $data['slug'],
                    'title' => trim($data['title']),
                    'bio' => isset($data['bio']) ? trim((string) $data['bio']) ?: null : null,
                    'avatar_media_id' => $avatarId,
                    'theme_color' => $data['theme_color'] ?? '#4f46e5',
                    'theme_mode' => $data['theme_mode'] ?? 'light',
                    // Sin `published` nunca se publica por defecto (CWE-1188): un microsite nuevo nace sin publicar y uno existente conserva su estado.
                    'published' => (bool) ($data['published'] ?? $existing?->published ?? false),
                ]);
                $link->user_id = $user->id;
                $link->brand_id = $brand->id;
                $link->save();

                $current = $link->blocks()->get()->keyBy('id');
                $kept = [];
                foreach ($blocks as $position => $b) {
                    $attrs = ['position' => $position] + $prepared[$position];
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
        } catch (UniqueConstraintViolationException $e) {
            // Carrera por el slug unico (entre la comprobacion y el INSERT); cualquier otro fallo de BD sube tal cual.
            if (! str_contains($e->getMessage(), 'slug')) {
                throw $e;
            }
            throw new PostValidationException(['slug' => ['Ese enlace ya está en uso.']]);
        }
    }

    /** Texto de usuario saneado: sin etiquetas, sin caracteres de control o formato Unicode y con espacios colapsados. */
    public static function clean(mixed $value, int $max = 100): string
    {
        $text = (string) $value; // sin strip_tags: se escapa al pintar (Blade/Vue) y 1 <2 y 3> 0 es texto legitimo
        $text = (string) preg_replace('/[\p{Cc}\p{Cf}\p{Zl}\p{Zp}]+/u', ' ', $text);

        return mb_substr(trim((string) preg_replace('/\s+/u', ' ', $text)), 0, $max);
    }

    /**
     * ID de video de una URL de YouTube (watch, youtu.be, shorts, embed) o null. Solo hosts de YouTube exactos y un ID de 11
     * caracteres: no hay ninguna consulta de red (sin oEmbed ni previsualizacion), asi que no hay superficie SSRF.
     */
    public static function youtubeId(?string $url): ?string
    {
        if (! SafeUrl::isSafe($url)) {
            return null;
        }
        $parts = parse_url((string) $url);
        $host = strtolower((string) ($parts['host'] ?? ''));
        $path = (string) ($parts['path'] ?? '');
        $id = null;
        if (in_array($host, ['youtube.com', 'www.youtube.com', 'm.youtube.com', 'music.youtube.com'], true)) {
            if ($path === '/watch') {
                parse_str((string) ($parts['query'] ?? ''), $q);
                $id = is_string($q['v'] ?? null) ? $q['v'] : null;
            } elseif (preg_match('#^/(?:shorts|embed|live)/([^/?]+)/?$#', $path, $m) === 1) {
                $id = $m[1];
            }
        } elseif ($host === 'youtu.be' && preg_match('#^/([^/?]+)/?$#', $path, $m) === 1) {
            $id = $m[1];
        }

        return $id !== null && preg_match('/^[A-Za-z0-9_-]{11}$/', $id) === 1 ? $id : null;
    }

    /**
     * Normaliza y valida UN bloque segun su tipo.
     *
     * @return array{0: array<string, mixed>, 1: array<string, list<string>>} atributos a guardar y errores por campo
     */
    private function prepareBlock(array $b, User $user, Brand $brand): array
    {
        $type = $b['type'] ?? null;
        $errors = [];
        if (! in_array($type, SmartLinkBlock::TYPES, true)) {
            return [['type' => null], ['type' => ['Tipo de bloque no valido.']]];
        }

        $label = self::clean($b['label'] ?? '');
        if ($label === '' && ! in_array($type, [SmartLinkBlock::TYPE_DIVIDER, SmartLinkBlock::TYPE_SOCIAL, SmartLinkBlock::TYPE_POSTS], true)) {
            $errors['label'][] = 'El texto del bloque es obligatorio.';
        }

        $url = null;
        $mediaId = null;
        $data = null;
        $raw = (array) ($b['data'] ?? []);

        switch ($type) {
            case SmartLinkBlock::TYPE_LINK:
                $url = $b['url'] ?? null;
                if (! SafeUrl::isSafe($url)) {
                    $errors['url'][] = 'La URL debe empezar por http:// o https://.';
                }
                break;

            case SmartLinkBlock::TYPE_IMAGE:
                $url = $b['url'] ?? null;
                if (! SafeUrl::isSafe($url)) {
                    $errors['url'][] = 'La URL debe empezar por http:// o https://.';
                }
                $mediaId = isset($b['media_id']) ? (int) $b['media_id'] : null;
                $owned = $mediaId && MediaAsset::withoutUserScope()->where('user_id', $user->id)->where('kind', MediaAsset::KIND_IMAGE)->whereKey($mediaId)->exists();
                if (! $owned) {
                    $errors['media_id'][] = 'Elige una imagen tuya.';
                }
                break;

            case SmartLinkBlock::TYPE_YOUTUBE:
                $id = self::youtubeId($b['url'] ?? null);
                if ($id === null) {
                    $errors['url'][] = 'Usa la URL de un video de YouTube (youtube.com/watch?v=…, youtu.be/… o /shorts/…).';
                } else {
                    $url = 'https://www.youtube.com/watch?v='.$id;
                    $data = ['video_id' => $id];
                }
                break;

            case SmartLinkBlock::TYPE_SOCIAL:
                $items = [];
                foreach (array_slice((array) ($raw['items'] ?? []), 0, 8) as $k => $item) {
                    $network = $item['network'] ?? null;
                    if (! in_array($network, SmartLinkBlock::SOCIAL_NETWORKS, true) || ! SafeUrl::isSafe($item['url'] ?? null)) {
                        $errors['data'][] = 'Cada icono necesita una red válida y una URL http(s) (icono '.($k + 1).').';

                        continue;
                    }
                    $items[] = ['network' => $network, 'url' => $item['url']];
                }
                if ($items === [] && $errors === []) {
                    $errors['data'][] = 'Añade al menos un icono social.';
                }
                $data = ['items' => $items];
                break;

            case SmartLinkBlock::TYPE_EMAIL:
                $data = [
                    'cta' => self::clean($raw['cta'] ?? '', 30) ?: 'Suscribirme',
                    'consent' => self::clean($raw['consent'] ?? '', 200) ?: 'Guardamos tu email solo para enviarte novedades de esta página. No enviamos correo de confirmación.',
                ];
                break;

            case SmartLinkBlock::TYPE_POSTS:
                $data = ['count' => max(1, min(6, (int) ($raw['count'] ?? 3)))];
                break;
        }

        // Visibilidad programada: fechas locales de la marca -> UTC.
        $tz = $brand->timezone ?: 'UTC';
        $from = $this->parseLocal($b['visible_from'] ?? null, $tz, $errors, 'visible_from');
        $until = $this->parseLocal($b['visible_until'] ?? null, $tz, $errors, 'visible_until');
        if ($from && $until && $until <= $from) {
            $errors['visible_until'][] = 'El fin de la visibilidad debe ser posterior al inicio.';
        }

        return [[
            'type' => $type, 'label' => $label, 'url' => $url, 'media_id' => $mediaId, 'data' => $data, 'visible_from' => $from, 'visible_until' => $until,
        ], $errors];
    }

    /** @param array<string, list<string>> $errors */
    private function parseLocal(mixed $value, string $tz, array &$errors, string $field): ?Carbon
    {
        if ($value === null || $value === '') {
            return null;
        }
        try {
            $date = Carbon::parse((string) $value, $tz)->utc();
        } catch (\Throwable) {
            $errors[$field][] = 'Fecha no válida.';

            return null;
        }
        // Rango razonable: entre hace un año y diez años desde hoy, y siempre antes de 2038 (limite de TIMESTAMP en MySQL).
        if ($date < now()->subYear() || $date > now()->addYears(10) || $date >= Carbon::parse('2038-01-01 00:00:00', 'UTC')) {
            $errors[$field][] = 'La fecha debe estar entre hace un año y diez años desde hoy, y antes de 2038.';

            return null;
        }

        return $date;
    }

    /** Bloques que ven los visitantes ahora (visibilidad programada y destinos seguros). @return Collection<int, SmartLinkBlock> */
    public function publicBlocks(SmartLink $link): Collection
    {
        $now = now();

        return $link->blocks->filter(fn (SmartLinkBlock $b) => $b->isVisibleAt($now) && match ($b->type) {
            SmartLinkBlock::TYPE_LINK, SmartLinkBlock::TYPE_IMAGE => SafeUrl::isSafe($b->url),
            SmartLinkBlock::TYPE_YOUTUBE => self::youtubeId($b->url) !== null,
            SmartLinkBlock::TYPE_SOCIAL => $b->targets() !== [],
            default => true,
        })->values();
    }

    /**
     * Ultimas publicaciones PUBLICADAS de la marca con enlace https propio de la red (texto recortado y saneado).
     *
     * @return list<array{text: string, url: string, network: string}>
     */
    public function recentPosts(SmartLink $link, int $count): array
    {
        return PostTarget::withoutUserScope()->where('user_id', $link->user_id)->where('status', PostStatus::Published->value)->whereNotNull('external_url')
            ->whereHas('post', fn ($q) => $q->withoutGlobalScope(UserScope::class)->where('brand_id', $link->brand_id))
            ->with(['post' => fn ($q) => $q->withoutGlobalScope(UserScope::class)->select('id', 'text')])
            ->orderByDesc('published_at')->orderByDesc('id')->limit($count * 3)->get()
            ->filter(fn (PostTarget $t) => str_starts_with((string) $t->external_url, 'https://') && SafeUrl::isSafe($t->external_url))
            ->take($count)->map(fn (PostTarget $t) => [
                'text' => self::clean($t->text ?? $t->post?->text ?? '', 120) ?: 'Ver publicación',
                'url' => (string) $t->external_url, 'network' => $t->network->value,
            ])->values()->all();
    }

    /**
     * Destino de un clic: SIEMPRE una URL guardada en el propio bloque (la del boton o el icono `$index`). Nunca viene de la peticion.
     */
    public function clickTarget(SmartLinkBlock $block, int $index = 0): ?string
    {
        // El indice solo elige entre varios iconos; el resto de bloques tiene un unico destino.
        $url = $block->targets()[$block->type === SmartLinkBlock::TYPE_SOCIAL ? $index : 0] ?? null;

        return SafeUrl::isSafe($url) ? $url : null;
    }

    /**
     * Alta en el formulario de email: uniforme exista o no (no revela suscriptores), con tope por bloque. Devuelve false si
     * el email no es valido.
     */
    public function subscribe(SmartLink $link, SmartLinkBlock $block, string $email): bool
    {
        $email = mb_strtolower(trim($email));
        if (mb_strlen($email) > 254 || filter_var($email, FILTER_VALIDATE_EMAIL) === false || preg_match('/[\p{Cc}\p{Cf}]/u', $email) === 1) {
            return false;
        }
        $max = (int) config('social.smartlinks.max_subscribers', 5000);
        if (SmartLinkSubscriber::query()->where('smart_link_block_id', $block->id)->count() >= $max) {
            $this->notifyFull($link, $block, $max);

            return true; // tope alcanzado: se responde igual, no se guarda
        }
        try {
            SmartLinkSubscriber::query()->create(['smart_link_id' => $link->id, 'smart_link_block_id' => $block->id, 'email' => $email]);
        } catch (QueryException) {
            // ya suscrito: respuesta identica
        }
        if (SmartLinkSubscriber::query()->where('smart_link_block_id', $block->id)->count() >= $max) {
            $this->notifyFull($link, $block, $max);
        }

        return true;
    }

    /** Avisa al propietario UNA sola vez por formulario (en la base de datos) cuando se llena. */
    private function notifyFull(SmartLink $link, SmartLinkBlock $block, int $max): void
    {
        $owner = User::query()->find($link->user_id);
        if (! $owner || $owner->notifications()->where('type', SmartLinkSubscribersFull::class)->where('data->block_id', $block->id)->exists()) {
            return;
        }
        $owner->notify(new SmartLinkSubscribersFull($link->id, $block->id, $link->slug, $max));
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
     * @return array{views: int, clicks: int, by_block: array<int, int>, by_block_7d: array<int, int>, subscribers: int, daily: list<array{date: string, views: int, clicks: int}>}
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
            'by_block_7d' => $clicks->where('date', '>=', now('UTC')->subDays(6)->toDateString())->groupBy('smart_link_block_id')->map(fn ($rows) => (int) $rows->sum('count'))->all(),
            'subscribers' => DB::table('smart_link_subscribers')->where('smart_link_id', $link->id)->count(),
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
