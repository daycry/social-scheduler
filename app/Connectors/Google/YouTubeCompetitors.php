<?php

namespace App\Connectors\Google;

use App\Connectors\Contracts\CompetitorSource;
use App\Connectors\Exceptions\PermanentPublishException;
use App\Connectors\Exceptions\QuotaExceededException;
use App\Connectors\Exceptions\ReconnectRequiredException;
use App\Connectors\Exceptions\TransientPublishException;
use App\Connectors\Meta\CompetitorNotFound;
use App\Connectors\Network;
use App\Models\SocialAccount;
use App\Support\SafeUrl;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;
use Throwable;

/**
 * Competidores de YouTube (T-25): datos PUBLICOS de canales ajenos con la YouTube Data API v3, leidos con el token de un canal PROPIO
 * conectado de la marca (la API no admite llamadas anonimas):
 *  - perfil: `channels.list` (`part=snippet,statistics`, por `forHandle` o `id`): 1 unidad; `subscriberCount` solo si el canal no lo oculta;
 *  - publicaciones: `playlistItems.list` de la lista de subidas (`UU` + id sin `UC`) y `videos.list` (`statistics`): 1 + 1 unidades.
 * Cuota: cada llamada se comprueba ANTES y se carga DESPUES en `YouTubeQuota` (cupo del proyecto, 10.000/dia) y en
 * `YouTubeCompetitorBudget` (tope diario global y por usuario, para no dejar sin cupo las subidas ni a otros usuarios). `quotaExceeded`/`dailyLimitExceeded` (403) y 429
 * son `QuotaExceededException` (con `Retry-After` si llega) y los reintenta `GuardsInsightsErrors` con espera creciente.
 */
class YouTubeCompetitors implements CompetitorSource
{
    private const API = 'https://www.googleapis.com/youtube/v3';

    public const HANDLE_PATTERN = '/^[\p{L}\p{N}._-]{3,30}$/u';

    public const CHANNEL_ID_PATTERN = '/^UC[A-Za-z0-9_-]{22}$/D';

    /** Cada lista (`channels`, `playlistItems`, `videos`) cuesta 1 unidad de cupo. */
    private const LIST_COST = 1;

    public function __construct(private readonly YouTubeQuota $quota = new YouTubeQuota, private readonly YouTubeCompetitorBudget $budget = new YouTubeCompetitorBudget) {}

    public function network(): Network
    {
        return Network::YouTube;
    }

    public function copy(): array
    {
        return [
            'username' => 'Usuario del canal de YouTube (@usuario) o su URL', 'placeholder' => '@canal', 'followers' => 'Suscriptores', 'media' => 'Videos',
            'note' => 'Solo se leen datos públicos: suscriptores y número de videos, una vez al día. Cada lectura gasta cuota de la API de YouTube, con un tope diario propio para no quitársela a tus subidas.',
        ];
    }

    public function needsAccount(): bool
    {
        return true;
    }

    public function normalize(string $input): string
    {
        $value = trim($input);
        // Acepta URLs de canal: youtube.com/@handle y youtube.com/channel/UC...
        if (preg_match('~^https?://(?:www\.|m\.)?youtube\.com/(?:@([^/?#\s]+)|channel/(UC[A-Za-z0-9_-]{22}))~i', $value, $m) === 1) {
            $value = ($m[1] ?? '') !== '' ? $m[1] : $m[2];
        }
        $value = ltrim($value, '@');

        if (preg_match(self::CHANNEL_ID_PATTERN, $value) === 1) {
            return $value;
        }
        if (preg_match(self::HANDLE_PATTERN, $value) !== 1) {
            throw new InvalidArgumentException('Escribe el @usuario del canal de YouTube (o su URL, o su id de canal que empieza por UC).');
        }

        return mb_strtolower($value);
    }

    public function lookup(?SocialAccount $account, string $username): array
    {
        $query = ['part' => 'snippet,statistics', 'maxResults' => 1]
            + (preg_match(self::CHANNEL_ID_PATTERN, $username) === 1 ? ['id' => $username] : ['forHandle' => '@'.ltrim($username, '@')]);
        $item = ((array) ($this->get($account, 'channels', $query)['items'] ?? []))[0] ?? null;

        if (! is_array($item) || empty($item['id'])) {
            throw new CompetitorNotFound('No encontramos ese canal de YouTube.');
        }

        $handle = isset($item['snippet']['customUrl']) ? mb_strtolower(ltrim((string) $item['snippet']['customUrl'], '@')) : '';
        $stats = (array) ($item['statistics'] ?? []);
        $thumb = $item['snippet']['thumbnails']['default']['url'] ?? null;

        return [
            'username' => $handle !== '' && preg_match(self::HANDLE_PATTERN, $handle) === 1 ? $handle : (string) $item['id'],
            'name' => isset($item['snippet']['title']) ? mb_substr((string) $item['snippet']['title'], 0, 255) : null,
            'profile_picture_url' => is_string($thumb) && SafeUrl::isSafe($thumb) ? mb_substr($thumb, 0, 1000) : null,
            // Un canal puede ocultar su numero de suscriptores: entonces es «no disponible», nunca 0.
            'followers_count' => empty($stats['hiddenSubscriberCount']) && isset($stats['subscriberCount']) ? (int) $stats['subscriberCount'] : null,
            'media_count' => isset($stats['videoCount']) ? (int) $stats['videoCount'] : null,
            'external_id' => (string) $item['id'],
        ];
    }

    public function media(?SocialAccount $account, string $username, ?string $externalId, int $limit): array
    {
        $channel = $externalId ?: $this->lookup($account, $username)['external_id'];
        if (preg_match(self::CHANNEL_ID_PATTERN, (string) $channel) !== 1) {
            throw new CompetitorNotFound('No encontramos ese canal de YouTube.');
        }

        $items = (array) ($this->get($account, 'playlistItems', ['part' => 'snippet', 'playlistId' => 'UU'.substr((string) $channel, 2), 'maxResults' => max(1, min(50, $limit))])['items'] ?? []);
        $rows = [];
        foreach ($items as $item) {
            $id = (string) ($item['snippet']['resourceId']['videoId'] ?? '');
            if (preg_match('/^[A-Za-z0-9_-]{6,20}$/', $id) === 1) {
                $rows[$id] = ['title' => (string) ($item['snippet']['title'] ?? ''), 'at' => $item['snippet']['publishedAt'] ?? null];
            }
        }
        if ($rows === []) {
            return [];
        }

        $stats = [];
        foreach ((array) ($this->get($account, 'videos', ['part' => 'statistics', 'id' => implode(',', array_keys($rows)), 'maxResults' => count($rows)])['items'] ?? []) as $video) {
            $stats[(string) ($video['id'] ?? '')] = (array) ($video['statistics'] ?? []);
        }

        $out = [];
        foreach ($rows as $id => $row) {
            $s = $stats[$id] ?? [];
            $out[] = [
                'external_id' => $id,
                'caption' => $row['title'] !== '' ? mb_substr($row['title'], 0, 300) : null,
                'media_type' => 'VIDEO',
                'permalink' => 'https://www.youtube.com/watch?v='.$id,
                // `likeCount` falta si el video oculta los me gusta, y `commentCount` si los comentarios estan cerrados: null, no 0.
                'likes_count' => isset($s['likeCount']) ? (int) $s['likeCount'] : null,
                'comments_count' => isset($s['commentCount']) ? (int) $s['commentCount'] : null,
                'posted_at' => $this->date($row['at']),
            ];
        }

        return $out;
    }

    /**
     * @param  array<string, mixed>  $query
     * @return array<string, mixed>
     */
    private function get(?SocialAccount $account, string $resource, array $query): array
    {
        if (! $account) {
            throw new ReconnectRequiredException('Conecta un canal de YouTube en la marca para consultar competidores.');
        }

        $this->quota->assertAvailable(self::LIST_COST);
        $this->budget->assertAllows(self::LIST_COST, (int) $account->user_id);

        try {
            $response = Http::timeout((int) config('social.http_timeout', 15))->acceptJson()->withToken((string) $account->access_token)->get(self::API.'/'.$resource, $query);
        } catch (ConnectionException) {
            throw new TransientPublishException('No se pudo contactar con YouTube (tiempo agotado o red).', 'red');
        }

        // Toda llamada que llega a la API gasta cupo (tambien las que fallan con 4xx), salvo que Google diga que ya esta agotado.
        $this->quota->charge(self::LIST_COST);
        $this->budget->charge(self::LIST_COST, (int) $account->user_id);

        if (! $response->successful()) {
            throw $this->exceptionFor($response);
        }

        return $response->json() ?? [];
    }

    private function exceptionFor(Response $response): Throwable
    {
        $status = $response->status();
        $reason = (string) ($response->json('error.errors.0.reason') ?? $response->json('error.status') ?? '');
        Log::warning('youtube.competitor_error', ['status' => $status, 'reason' => $reason]);

        // 401: el access token pudo caducar entre renovaciones. Como en `Analytics`, es transitorio: se reintenta tras el refresco programado
        // de tokens y SOLO si ese refresco falla se marca el canal para reconectar (marcarlo aqui bloquearia sus subidas programadas).
        if ($status === 401) {
            return new TransientPublishException('YouTube rechazo el token (puede haber caducado); se reintentara tras renovarlo.', 'youtube_token_caducado');
        }

        if (in_array($reason, ['quotaExceeded', 'dailyLimitExceeded', 'RESOURCE_EXHAUSTED'], true)) {
            $this->quota->markExhausted();

            return new QuotaExceededException('YouTube informa de que se agoto el cupo de la API ('.$reason.').', $this->quota->resetsAt());
        }

        // 429 sin motivo de cupo diario: ritmo por usuario o proyecto; se reintenta con la espera que pida Google.
        if ($status === 429 || in_array($reason, ['rateLimitExceeded', 'userRateLimitExceeded'], true)) {
            $after = (int) $response->header('Retry-After');

            return new QuotaExceededException('YouTube limito temporalmente el ritmo de peticiones; se reintentara.', $after > 0 ? now()->addSeconds($after) : null, 'youtube_limite_ritmo');
        }

        if ($status === 404 || $reason === 'channelNotFound' || $reason === 'playlistNotFound') {
            return new CompetitorNotFound('No encontramos ese canal de YouTube o no tiene videos publicos.');
        }

        if ($status >= 500) {
            return new TransientPublishException('YouTube no esta disponible temporalmente ('.($reason ?: $status).').', 'youtube_no_disponible');
        }

        if ($status === 403) {
            return new PermanentPublishException('YouTube no permite consultar canales con esta cuenta ('.($reason ?: 'prohibido').'). Revisa los permisos y vuelve a conectar el canal.', 'permisos');
        }

        return new PermanentPublishException('YouTube rechazo la consulta: '.mb_substr((string) $response->json('error.message'), 0, 200), 'rechazada');
    }

    private function date(mixed $value): ?CarbonImmutable
    {
        try {
            return $value ? CarbonImmutable::parse((string) $value)->utc() : null;
        } catch (Throwable) {
            return null;
        }
    }
}
