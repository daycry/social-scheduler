<?php

namespace App\Connectors\TikTok;

use App\Analytics\Days;
use App\Analytics\Metric;
use App\Connectors\Contracts\InsightsReader;
use App\Connectors\Exceptions\PermanentPublishException;
use App\Connectors\Exceptions\ReconnectRequiredException;
use App\Connectors\Exceptions\TransientPublishException;
use App\Models\PostTarget;
use App\Models\SocialAccount;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Colector de TikTok (Display API v2). Decision G1: la analitica se limita a lo que concedan los scopes
 * `user.info.stats` (seguidores y numero de videos) y `video.list` (vistas, me gusta, comentarios y compartidos
 * de un video). Esos scopes requieren la auditoria de TikTok y NO estan en `social.tiktok.scopes`: una cuenta sin
 * ellos devuelve todo `null` ("no disponible") SIN llamar a la API. TikTok no ofrece flujos diarios de cuenta ni
 * historico: solo se guarda el stock de seguidores/videos cada dia.
 *
 * Un `publish_id` de borrador (progress.external_id_kind = publish_id) NO es un ID de video: esos destinos no se leen.
 */
class Display implements InsightsReader
{
    private const API = 'https://open.tiktokapis.com';

    public const SCOPE_STATS = 'user.info.stats';

    public const SCOPE_VIDEOS = 'video.list';

    public function accountInsights(SocialAccount $account, CarbonInterface $day): array
    {
        $out = Metric::blankAccount();

        if (! $this->hasScope($account, self::SCOPE_STATS) || CarbonImmutable::createFromFormat('!Y-m-d', $day->format('Y-m-d'), 'UTC')->lt(Days::closed($account->network))) {
            return $out; // stock: no existe el valor de un dia pasado
        }

        $json = $this->call($account, 'get', '/v2/user/info/', ['fields' => 'follower_count,video_count']);
        $user = $json['data']['user'] ?? [];
        $out[Metric::FOLLOWERS] = is_numeric($user['follower_count'] ?? null) ? $user['follower_count'] + 0 : null;
        $out[Metric::VIDEO_COUNT] = is_numeric($user['video_count'] ?? null) ? $user['video_count'] + 0 : null;

        return $out;
    }

    public function canReadPost(PostTarget $target): bool
    {
        return $target->external_id !== null
            && ($target->progress['external_id_kind'] ?? null) === 'video_id'
            && $target->account !== null
            && $this->hasScope($target->account, self::SCOPE_VIDEOS);
    }

    public function postInsights(SocialAccount $account, string $externalId): array
    {
        $out = Metric::blankPost();

        if (! $this->hasScope($account, self::SCOPE_VIDEOS)) {
            return $out;
        }

        $json = $this->call($account, 'post', '/v2/video/query/?fields=id,view_count,like_count,comment_count,share_count', [
            'filters' => ['video_ids' => [$externalId]],
        ]);

        foreach ($json['data']['videos'] ?? [] as $video) {
            if ((string) ($video['id'] ?? '') !== $externalId) {
                continue;
            }

            $out[Metric::VIEWS] = $this->num($video['view_count'] ?? null);
            $out[Metric::LIKES] = $this->num($video['like_count'] ?? null);
            $out[Metric::COMMENTS] = $this->num($video['comment_count'] ?? null);
            $out[Metric::SHARES] = $this->num($video['share_count'] ?? null);
            $parts = array_filter([$out[Metric::LIKES], $out[Metric::COMMENTS], $out[Metric::SHARES]], fn ($v) => $v !== null);
            $out[Metric::INTERACTIONS] = $parts === [] ? null : array_sum($parts);
        }

        return $out;
    }

    private function hasScope(SocialAccount $account, string $scope): bool
    {
        return in_array($scope, (array) $account->scopes, true);
    }

    /** @return array<string, mixed> */
    private function call(SocialAccount $account, string $method, string $path, array $data): array
    {
        try {
            $http = Http::timeout((int) config('social.http_timeout', 15))->acceptJson()->withToken((string) $account->access_token);
            $response = $method === 'get' ? $http->get(self::API.$path, $data) : $http->post(self::API.$path, $data);
        } catch (ConnectionException) {
            throw new TransientPublishException('No se pudo contactar con TikTok (tiempo agotado o red).', 'red');
        }

        $code = (string) ($response->json('error.code') ?? ($response->successful() ? 'ok' : (string) $response->status()));

        if ($response->successful() && $code === 'ok') {
            return $response->json() ?? [];
        }

        Log::warning('tiktok.display_error', ['status' => $response->status(), 'code' => $code]);

        if ($code === 'access_token_invalid' || $response->status() === 401) {
            throw new ReconnectRequiredException('TikTok rechazo las credenciales. Reconecta la cuenta.');
        }

        if ($response->serverError() || $code === 'rate_limit_exceeded' || $response->status() === 429) {
            throw new TransientPublishException('TikTok no esta disponible temporalmente ('.$code.').', 'tiktok_no_disponible');
        }

        // scope_not_authorized, scope_permission_missed...: las metricas quedan "no disponibles" (decision G1).
        throw new PermanentPublishException('TikTok rechazo la consulta de metricas ('.$code.').', $code);
    }

    private function num(mixed $v): int|float|null
    {
        return is_numeric($v) ? $v + 0 : null;
    }
}
