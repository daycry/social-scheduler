<?php

namespace App\Connectors\Meta\Instagram;

use App\Connectors\Exceptions\ConnectorException;
use App\Connectors\Exceptions\PublishException;
use App\Connectors\Exceptions\ReconnectRequiredException;
use App\Connectors\Meta\GraphClient;
use App\Models\SocialAccount;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Lector de la cuadricula de Instagram (C-02): lo ya publicado en `/{ig}/media`, paginado. Solo lectura, sin los permisos de
 * publicar (basta `instagram_basic`). `media_url` y `thumbnail_url` son URLs del CDN de Meta que CADUCAN: no se guardan.
 * Nunca lanza hacia la pagina: un fallo de Graph vuelve como `error` (con lo leido hasta entonces).
 */
class InstagramGrid
{
    public const FIELDS = 'id,media_type,media_url,thumbnail_url,permalink,timestamp,is_shared_to_feed,children{id,media_type,media_url,thumbnail_url}';

    public function __construct(private readonly GraphClient $graph = new GraphClient) {}

    /**
     * @return array{items: list<array{id: string, type: string, image_url: ?string, video_url: ?string, permalink: ?string, timestamp: ?string}>, error: ?string}
     */
    public function read(SocialAccount $account, int $perPage = 24, int $maxPages = 3, ?int $limit = null): array
    {
        $items = [];
        $after = null;

        try {
            for ($page = 0; $page < max(1, $maxPages); $page++) {
                $query = ['fields' => self::FIELDS, 'limit' => $perPage] + ($after !== null ? ['after' => $after] : []);
                $data = $this->graph->get($account->external_id.'/media', $query, (string) $account->access_token, true);

                foreach ($data['data'] ?? [] as $row) {
                    // Un Reel con «compartir en el feed» desactivado no sale en la cuadricula del perfil.
                    if (isset($row['id']) && ($row['is_shared_to_feed'] ?? true) !== false) {
                        $items[] = $this->normalize($row);
                    }
                }

                if ($limit !== null && count($items) >= $limit) {
                    $items = array_slice($items, 0, $limit);
                    break;
                }

                $after = ($data['paging']['next'] ?? null) !== null ? ($data['paging']['cursors']['after'] ?? null) : null;

                if ($after === null) {
                    break;
                }
            }
        } catch (ConnectorException $e) {
            Log::notice('instagram.grid_read_failed', ['account_id' => $account->id, 'exception' => $e::class]);

            return ['items' => $items, 'error' => self::readMessage($e)];
        }

        return ['items' => $items, 'error' => null];
    }

    /** Mensaje de LECTURA: los de `GraphClient` hablan de publicar, y esta pagina solo lee. */
    private static function readMessage(ConnectorException $e): string
    {
        if ($e instanceof ReconnectRequiredException) {
            return 'Meta rechazo las credenciales de la cuenta. Reconecta la cuenta.';
        }

        if ($e instanceof PublishException && $e->errorCode === 'permisos') {
            return 'Faltan permisos en Meta para leer las publicaciones de la cuenta. Revisa los permisos concedidos y vuelve a conectarla.';
        }

        if ($e instanceof PublishException && in_array($e->errorCode, ['rate_limit', 'meta_no_disponible'], true)) {
            return 'Meta no responde ahora mismo o limito las peticiones. Intentalo de nuevo en unos minutos.';
        }

        return 'Meta no pudo devolver las publicaciones de la cuenta. Intentalo de nuevo mas tarde.';
    }

    /** ISO-8601 con dos puntos en la zona (Graph da `+0000`, que Safari/WebKit no siempre entiende en `new Date`). */
    private static function isoTimestamp(mixed $value): ?string
    {
        if (! is_string($value) || $value === '') {
            return null;
        }

        try {
            return CarbonImmutable::parse($value)->toIso8601String();
        } catch (Throwable) {
            return null;
        }
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
            'timestamp' => self::isoTimestamp($row['timestamp'] ?? null),
        ];
    }
}
