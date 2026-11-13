<?php

namespace App\Services;

use App\Connectors\LinkedIn\LinkedInPostOptions;
use App\Connectors\Meta\Instagram\InstagramAudio;
use App\Connectors\Meta\Instagram\InstagramPostOptions;
use App\Connectors\Network;
use App\Connectors\Pinterest\PinterestPostOptions;
use App\Enums\ContentType;
use App\Models\SocialAccount;

/** Representacion unica de una cuenta conectada para la API v1 y el MCP: sus capacidades y los tipos de contenido que admite. Nunca expone tokens. */
class AccountPresenter
{
    /** @return array<string, mixed> */
    public function present(SocialAccount $a): array
    {
        $network = $a->network instanceof Network ? $a->network : Network::from($a->network);

        return [
            'id' => $a->id,
            'network' => $a->network->value,
            'name' => $a->name,
            'username' => $a->username,
            'status' => $a->status,
            'audio' => AudioCapabilities::forAccount($a),
            'instagram_presets' => $a->network === Network::Instagram ? InstagramPostOptions::capabilities() : null,
            'linkedin' => $a->network === Network::LinkedIn ? LinkedInPostOptions::capabilities($a) : null,
            'pinterest' => $a->network === Network::Pinterest ? PinterestPostOptions::capabilities($a) : null,
            'instagram_audio' => $a->network === Network::Instagram ? [
                'available' => InstagramAudio::unavailableReason($a) === null,
                'reason' => InstagramAudio::unavailableReason($a),
                'content_types' => ['instagram_reel'],
            ] : null,
            // false = red apagada en esta instalacion (BLUESKY_ENABLED=false): sin tipos de contenido y create_post la rechaza.
            'available' => $network->isAvailable(),
            'content_types' => array_values(array_map(
                fn (ContentType $t) => $t->value,
                array_filter(ContentType::forNetwork($network), fn (ContentType $t) => $t->isOffered()),
            )),
        ];
    }
}
