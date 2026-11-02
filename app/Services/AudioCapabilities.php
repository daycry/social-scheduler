<?php

namespace App\Services;

use App\Connectors\Meta\Instagram\InstagramAudio;
use App\Connectors\Network;
use App\Connectors\TikTok\TikTokPublisher;
use App\Enums\ContentType;
use App\Models\SocialAccount;

/** Describes this connection's publishing capabilities, not the native social app's full editor. */
class AudioCapabilities
{
    public static function forAccount(SocialAccount $account): array
    {
        $embedded = array_values(array_map(fn (ContentType $type) => $type->value, array_filter(
            ContentType::forNetwork($account->network),
            fn (ContentType $type) => $type->isOffered() && in_array('video', config('media_specs.types.'.$type->specKey('video').'.media.kinds', []), true),
        )));
        $catalog = $account->network === Network::Instagram && InstagramAudio::unavailableReason($account) === null;
        $recommended = $account->network === Network::TikTok && $account->isActive() && TikTokPublisher::directPost($account) && ContentType::TikTokPhoto->isOffered();

        return [
            'embedded_video_types' => $embedded,
            'catalog_types' => $catalog ? ['instagram_reel'] : [],
            'recommended_music_types' => $recommended ? ['tiktok_photo'] : [],
            'note' => match ($account->network) {
                Network::Instagram => 'El vídeo conserva su sonido. Puedes elegir una pista del catálogo de Meta para Reels con una conexión elegible.',
                Network::TikTok => 'El vídeo conserva su sonido. En fotos y carruseles con publicación directa puedes pedir que TikTok añada música recomendada; TikTok elige la canción.',
                default => 'Se publicará el sonido que ya lleve el vídeo adjunto. Esta conexión no ofrece selección de canciones de catálogo.',
            },
        ];
    }
}
