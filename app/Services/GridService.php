<?php

namespace App\Services;

use App\Connectors\Meta\Instagram\InstagramGrid;
use App\Connectors\Network;
use App\Enums\ContentType;
use App\Enums\PostStatus;
use App\Models\Brand;
use App\Models\MediaAsset;
use App\Models\PostTarget;
use App\Models\SocialAccount;
use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Throwable;

/**
 * Cuadricula de Instagram (C-02): arriba lo PROGRAMADO (la fecha mas tardia primero) y debajo lo YA PUBLICADO (lectura en vivo de
 * `/{ig}/media`, mas reciente primero), que es el orden en que quedara el perfil. Lo publicado se cachea `CACHE_MINUTES` por cuenta
 * y NO se persiste (las URLs del CDN de Meta caducan); lo programado sale siempre de la base de datos. Las Stories no entran, y un
 * Reel solo con «compartir en el feed». Un fallo de Graph no se cachea: se devuelve como `error` junto con lo programado.
 */
class GridService
{
    public const CACHE_MINUTES = 10;

    public function __construct(private readonly InstagramGrid $reader) {}

    /** Cuentas de Instagram de la marca para el selector. @return list<array{id: int, name: string, username: ?string}> */
    public function accounts(Brand $brand): array
    {
        return SocialAccount::withoutUserScope()->where('brand_id', $brand->id)->where('user_id', $brand->user_id)
            ->where('network', Network::Instagram->value)->orderBy('name')->orderBy('id')->get()
            ->map(fn (SocialAccount $a) => ['id' => $a->id, 'name' => $a->name, 'username' => $a->username])->all();
    }

    /**
     * @return array{items: list<array<string, mixed>>, error: ?string, cached: bool}
     */
    public function build(User $user, SocialAccount $account): array
    {
        $scheduled = $this->scheduled($user, $account);
        [$published, $error, $cached] = $this->published($account);

        return ['items' => array_merge($scheduled, $published), 'error' => $error, 'cached' => $cached];
    }

    /** @return list<array<string, mixed>> */
    private function scheduled(User $user, SocialAccount $account): array
    {
        $targets = PostTarget::withoutUserScope()->where('user_id', $user->id)
            ->where('social_account_id', $account->id)->where('network', Network::Instagram->value)
            ->where('status', PostStatus::Scheduled->value)
            ->where('content_type', '!=', ContentType::InstagramStory->value)
            ->with(['post' => fn ($q) => $q->withoutGlobalScopes()->with(['media' => fn ($m) => $m->withoutGlobalScopes()])])
            ->get()
            ->filter(fn (PostTarget $t) => $t->post !== null && $t->post->scheduled_at !== null
                && ($t->content_type !== ContentType::InstagramReel || ($t->options['share_to_feed'] ?? true) !== false))
            ->sortByDesc(fn (PostTarget $t) => [$t->post->scheduled_at->getTimestamp(), $t->id])
            ->values();

        return $targets->map(fn (PostTarget $t) => $this->scheduledItem($t))->all();
    }

    /** @return array<string, mixed> */
    private function scheduledItem(PostTarget $t): array
    {
        /** @var MediaAsset|null $media */
        $media = $t->post->media->first();
        $isVideo = $media?->isVideo() ?? false;
        $url = null;

        if ($media !== null) {
            try {
                $url = $media->publicUrl(self::CACHE_MINUTES * 6);
            } catch (Throwable) {
                $url = null; // el disco no firma URLs: la tesela sale sin miniatura
            }
        }

        return [
            'kind' => 'scheduled',
            'id' => 'target-'.$t->id,
            'target_id' => $t->id,
            'post_id' => $t->post_id,
            'type' => $t->content_type === ContentType::InstagramCarousel ? 'CAROUSEL_ALBUM' : ($isVideo ? 'VIDEO' : 'IMAGE'),
            'image_url' => $isVideo ? null : $url,
            'video_url' => $isVideo ? $url : null,
            'permalink' => null,
            'timestamp' => $t->post->scheduled_at->toIso8601String(),
        ];
    }

    /** @return array{0: list<array<string, mixed>>, 1: ?string, 2: bool} */
    private function published(SocialAccount $account): array
    {
        $key = 'ig-grid:'.$account->id;
        $hit = Cache::get($key);

        if (is_array($hit)) {
            return [$hit, null, true];
        }

        $read = $this->reader->read($account);
        $items = array_map(fn (array $i) => $i + ['kind' => 'published', 'target_id' => null, 'post_id' => null], $read['items']);

        if ($read['error'] === null) {
            Cache::put($key, $items, now()->addMinutes(self::CACHE_MINUTES));
        }

        return [$items, $read['error'], false];
    }
}
