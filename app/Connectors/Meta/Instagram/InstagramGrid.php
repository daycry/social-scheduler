<?php

namespace App\Connectors\Meta\Instagram;

use App\Connectors\Exceptions\ConnectorException;
use App\Connectors\Meta\GraphClient;
use App\Models\SocialAccount;
use Illuminate\Support\Facades\Log;

/**
 * Lector de la cuadricula de Instagram (C-02): lo ya publicado en `/{ig}/media`, paginado. Solo lectura, sin los permisos de
 * publicar (basta `instagram_basic`). `media_url` y `thumbnail_url` son URLs del CDN de Meta que CADUCAN: no se guardan.
 * Nunca lanza hacia la pagina: un fallo de Graph vuelve como `error` (con lo leido hasta entonces).
 */
class InstagramGrid
{
    public const FIELDS = 'id,media_type,media_url,thumbnail_url,permalink,timestamp,children{id,media_type,media_url,thumbnail_url}';

    public function __construct(private readonly GraphClient $graph = new GraphClient) {}

    /**
     * @return array{items: list<array{id: string, type: string, image_url: ?string, video_url: ?string, permalink: ?string, timestamp: ?string}>, error: ?string}
     */
    public function read(SocialAccount $account, int $perPage = 24, int $maxPages = 3): array
    {
        $items = [];
        $after = null;

        try {
            for ($page = 0; $page < max(1, $maxPages); $page++) {
                $query = ['fields' => self::FIELDS, 'limit' => $perPage] + ($after !== null ? ['after' => $after] : []);
                $data = $this->graph->get($account->external_id.'/media', $query, (string) $account->access_token, true);

                foreach ($data['data'] ?? [] as $row) {
                    if (isset($row['id'])) {
                        $items[] = $this->normalize($row);
                    }
                }

                $after = ($data['paging']['next'] ?? null) !== null ? ($data['paging']['cursors']['after'] ?? null) : null;

                if ($after === null) {
                    break;
                }
            }
        } catch (ConnectorException $e) {
            Log::notice('instagram.grid_read_failed', ['account_id' => $account->id, 'exception' => $e::class]);

            return ['items' => $items, 'error' => $e->getMessage()];
        }

        return ['items' => $items, 'error' => null];
    }

    /** @return array{id: string, type: string, image_url: ?string, video_url: ?string, permalink: ?string, timestamp: ?string} */
    private function normalize(array $row): array
    {
        $type = (string) ($row['media_type'] ?? 'IMAGE');
        // Carrusel: se muestra su primera pieza (la que ve el perfil).
        $piece = $type === 'CAROUSEL_ALBUM' ? ($row['children']['data'][0] ?? $row) : $row;
        $pieceType = (string) ($piece['media_type'] ?? $type);
        $url = $piece['media_url'] ?? ($piece === $row ? null : ($row['media_url'] ?? null));
        $thumb = $piece['thumbnail_url'] ?? null;
        $isVideo = $pieceType === 'VIDEO';

        return [
            'id' => (string) $row['id'],
            'type' => $type,
            'image_url' => $isVideo ? $thumb : ($url ?? $thumb),
            'video_url' => $isVideo && $thumb === null ? $url : null,
            'permalink' => $row['permalink'] ?? null,
            'timestamp' => $row['timestamp'] ?? null,
        ];
    }
}
