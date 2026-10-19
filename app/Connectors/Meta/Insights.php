<?php

namespace App\Connectors\Meta;

use App\Analytics\Metric;
use App\Connectors\Contracts\InsightsReader;
use App\Connectors\Exceptions\PermanentPublishException;
use App\Connectors\Network;
use App\Models\PostTarget;
use App\Models\SocialAccount;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\Log;

/**
 * Colector de insights de Meta (Facebook Pagina + Instagram) sobre Graph. Reutiliza GraphClient sin modificarlo.
 *
 * Metricas verificadas contra la API real en el spike (spike-report.md, Graph v23.0):
 *  - FB Pagina: `page_media_view` (-> views), `page_post_engagements` (-> interactions), `page_follows`
 *    (-> followers). NO existen `page_impressions` ni `page_fans`.
 *  - IG cuenta: `views`, `reach`, `profile_views`, `accounts_engaged` con `metric_type=total_value`;
 *    `follower_count` como serie diaria (nuevos seguidores del dia -> followers_gained). No existe `impressions`.
 *  - IG media: `views`, `reach`, `likes`, `comments`, `shares`, `saved`, `total_interactions`.
 *
 * Cada metrica se pide por separado: si Meta rechaza una (retirada, sin permiso, cuenta pequena) queda en `null`
 * ("no disponible") y las demas se guardan. Credenciales invalidas (ReconnectRequiredException) y fallos
 * transitorios (TransientPublishException) SI se propagan para que el job reintente o pida reconectar.
 */
class Insights implements InsightsReader
{
    /** Los stocks (seguidores actuales) solo se leen para dias recientes: la API no da el valor de un dia pasado. */
    private const STOCK_MAX_AGE_DAYS = 2;

    public function __construct(private readonly GraphClient $graph) {}

    public function accountInsights(SocialAccount $account, CarbonInterface $day): array
    {
        return $account->network === Network::Facebook
            ? $this->facebookAccount($account, $day)
            : $this->instagramAccount($account, $day);
    }

    public function canReadPost(PostTarget $target): bool
    {
        return $target->external_id !== null && $target->external_id !== '';
    }

    public function postInsights(SocialAccount $account, string $externalId): array
    {
        return $account->network === Network::Facebook
            ? $this->facebookPost($account, $externalId)
            : $this->instagramMedia($account, $externalId);
    }

    /** @return array<string, int|float|null> */
    private function facebookAccount(SocialAccount $account, CarbonInterface $day): array
    {
        $out = Metric::blankAccount();
        $id = (string) ($account->meta['page_id'] ?? $account->external_id);
        $token = (string) $account->access_token;
        $range = $this->range($day);

        $out[Metric::VIEWS] = $this->seriesValue($id, 'page_media_view', $range, $token);
        $out[Metric::INTERACTIONS] = $this->seriesValue($id, 'page_post_engagements', $range, $token);
        $out[Metric::FOLLOWERS] = $this->seriesValue($id, 'page_follows', $range, $token);

        if ($out[Metric::FOLLOWERS] === null && $this->isRecent($day)) {
            $json = $this->safe(fn () => $this->graph->get($id, ['fields' => 'followers_count'], $token), 'followers_count');
            $out[Metric::FOLLOWERS] = $this->numberOrNull($json['followers_count'] ?? null);
        }

        return $out;
    }

    /** @return array<string, int|float|null> */
    private function instagramAccount(SocialAccount $account, CarbonInterface $day): array
    {
        $out = Metric::blankAccount();
        $id = $account->external_id;
        $token = (string) $account->access_token;
        $range = $this->range($day);

        foreach ([
            'views' => Metric::VIEWS,
            'reach' => Metric::REACH,
            'profile_views' => Metric::PROFILE_VIEWS,
            'accounts_engaged' => Metric::ACCOUNTS_ENGAGED,
        ] as $metric => $key) {
            $json = $this->safe(fn () => $this->graph->get("$id/insights", ['metric' => $metric, 'metric_type' => 'total_value', 'period' => 'day'] + $range, $token), $metric);
            $out[$key] = $this->numberOrNull($json['data'][0]['total_value']['value'] ?? null);
        }

        // follower_count NO admite total_value: serie diaria de nuevos seguidores.
        $out[Metric::FOLLOWERS_GAINED] = $this->seriesValue($id, 'follower_count', $range, $token);

        if ($this->isRecent($day)) {
            $json = $this->safe(fn () => $this->graph->get($id, ['fields' => 'followers_count'], $token), 'followers_count');
            $out[Metric::FOLLOWERS] = $this->numberOrNull($json['followers_count'] ?? null);
        }

        return $out;
    }

    /** @return array<string, int|float|null> */
    private function instagramMedia(SocialAccount $account, string $mediaId): array
    {
        $out = Metric::blankPost();
        $token = (string) $account->access_token;
        $map = ['views' => Metric::VIEWS, 'reach' => Metric::REACH, 'likes' => Metric::LIKES, 'comments' => Metric::COMMENTS,
            'shares' => Metric::SHARES, 'saved' => Metric::SAVED, 'total_interactions' => Metric::INTERACTIONS];

        try {
            $json = $this->graph->get("$mediaId/insights", ['metric' => implode(',', array_keys($map))], $token);
        } catch (PermanentPublishException) {
            // Un tipo de media (p. ej. story) puede no admitir alguna: se pide una a una y lo rechazado queda `null`.
            foreach ($map as $metric => $key) {
                $one = $this->safe(fn () => $this->graph->get("$mediaId/insights", ['metric' => $metric], $token), $metric);
                $out[$key] = $this->numberOrNull($one['data'][0]['values'][0]['value'] ?? $one['data'][0]['total_value']['value'] ?? null);
            }

            return $out;
        }

        foreach ($json['data'] ?? [] as $row) {
            $key = $map[$row['name'] ?? ''] ?? null;

            if ($key !== null) {
                $out[$key] = $this->numberOrNull($row['values'][0]['value'] ?? $row['total_value']['value'] ?? null);
            }
        }

        return $out;
    }

    /** @return array<string, int|float|null> */
    private function facebookPost(SocialAccount $account, string $postId): array
    {
        $out = Metric::blankPost();
        $token = (string) $account->access_token;

        $fields = $this->safe(fn () => $this->graph->get($postId, ['fields' => 'reactions.summary(true).limit(0),comments.summary(true).limit(0),shares'], $token), 'post_fields');

        if (isset($fields['reactions']['summary']['total_count'])) {
            $out[Metric::LIKES] = (int) $fields['reactions']['summary']['total_count'];
            $out[Metric::COMMENTS] = (int) ($fields['comments']['summary']['total_count'] ?? 0);
            // Facebook omite `shares` cuando no hay ninguno: con la lectura correcta, ausente = 0.
            $out[Metric::SHARES] = (int) ($fields['shares']['count'] ?? 0);
            $out[Metric::INTERACTIONS] = $out[Metric::LIKES] + $out[Metric::COMMENTS] + $out[Metric::SHARES];
        }

        // ATENCION: `post_media_view` / `post_total_media_view_unique` (sustitutas de post_impressions*) NO se verificaron
        // en el spike: si Meta las rechaza quedan en `null` (ver pendientes manuales de T-32).
        $out[Metric::VIEWS] = $this->lifetimeValue($postId, 'post_media_view', $token);
        $out[Metric::REACH] = $this->lifetimeValue($postId, 'post_total_media_view_unique', $token);

        return $out;
    }

    /** Valor de un dia de una metrica `period=day` (la primera entrada de la serie). */
    private function seriesValue(string $id, string $metric, array $range, string $token): int|float|null
    {
        $json = $this->safe(fn () => $this->graph->get("$id/insights", ['metric' => $metric, 'period' => 'day'] + $range, $token), $metric);

        return $this->numberOrNull($json['data'][0]['values'][0]['value'] ?? null);
    }

    private function lifetimeValue(string $id, string $metric, string $token): int|float|null
    {
        $json = $this->safe(fn () => $this->graph->get("$id/insights", ['metric' => $metric], $token), $metric);

        return $this->numberOrNull($json['data'][0]['values'][0]['value'] ?? null);
    }

    /**
     * Ejecuta una lectura; un rechazo PERMANENTE de la metrica (retirada, sin permiso) = `[]` ("no disponible").
     *
     * @param  \Closure(): array<string, mixed>  $call
     * @return array<string, mixed>
     */
    private function safe(\Closure $call, string $metric): array
    {
        try {
            return $call();
        } catch (PermanentPublishException $e) {
            Log::notice('meta.insight_unavailable', ['metric' => $metric, 'code' => $e->errorCode]);

            return [];
        }
    }

    /** @return array{since: string, until: string} */
    private function range(CarbonInterface $day): array
    {
        $start = $day->copy()->startOfDay();

        return ['since' => $start->format('Y-m-d'), 'until' => $start->copy()->addDay()->format('Y-m-d')];
    }

    private function isRecent(CarbonInterface $day): bool
    {
        return $day->copy()->startOfDay()->gte(now()->startOfDay()->subDays(self::STOCK_MAX_AGE_DAYS));
    }

    private function numberOrNull(mixed $v): int|float|null
    {
        return is_numeric($v) ? $v + 0 : null;
    }
}
