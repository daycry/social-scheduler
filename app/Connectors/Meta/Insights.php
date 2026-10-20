<?php

namespace App\Connectors\Meta;

use App\Analytics\Days;
use App\Analytics\Metric;
use App\Connectors\Contracts\RangeInsightsReader;
use App\Connectors\Exceptions\PermanentPublishException;
use App\Connectors\Network;
use App\Models\PostTarget;
use App\Models\SocialAccount;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\Log;

/**
 * Colector de insights de Meta (Facebook Pagina + Instagram) sobre Graph. Reutiliza GraphClient.
 *
 * Metricas verificadas contra la API real en el spike (spike-report.md, Graph v23.0):
 *  - FB Pagina: `page_media_view` (-> views), `page_post_engagements` (-> interactions), `page_follows`
 *    (-> followers). NO existen `page_impressions` ni `page_fans`.
 *  - IG cuenta: `views`, `reach`, `profile_views`, `accounts_engaged`, `total_interactions` (-> interactions, base del
 *    engagement de cuenta) con `metric_type=total_value`; `follower_count` como serie diaria (nuevos seguidores del dia
 *    -> followers_gained). No existe `impressions`. Si Meta rechaza `total_interactions`, queda `null`.
 *  - IG media: `views`, `reach`, `likes`, `comments`, `shares`, `saved`, `total_interactions`.
 *
 * Dia: Meta define el dia en el Pacifico y cada valor de una serie lleva `end_time` (FIN del periodo, medianoche del
 * Pacifico = 07:00/08:00 UTC). El valor del dia D es el cuyo `end_time` cae en la medianoche de D+1 del Pacifico, NO
 * `values[0]` (una consulta `since=D&until=D+1` puede traer mas de un valor). Facebook se lee por rangos de <= 30 dias en
 * el historico; Instagram (`total_value`) va dia a dia.
 *
 * Cada metrica se pide por separado: si Meta rechaza una (retirada, sin permiso, cuenta pequena) queda en `null`
 * ("no disponible") y las demas se guardan. Credenciales invalidas (ReconnectRequiredException) y fallos
 * transitorios, incluidos los limites de uso (TransientPublishException), SI se propagan para que el job reintente o
 * pida reconectar: un limite de uso nunca se guarda como "no disponible".
 */
class Insights implements RangeInsightsReader
{
    /** Los stocks (seguidores actuales) solo se leen para dias recientes: la API no da el valor de un dia pasado. */
    private const STOCK_MAX_AGE_DAYS = 2;

    /** Zona en la que Meta cierra el dia de las metricas. */
    private const ZONE = 'America/Los_Angeles';

    /** Maximo de dias por consulta `since/until` (limite de la API de insights de Instagram; se usa tambien en Facebook). */
    private const RANGE_DAYS = 30;

    public function __construct(private readonly GraphClient $graph) {}

    public function accountInsights(SocialAccount $account, CarbonInterface $day): array
    {
        return $account->network === Network::Facebook
            ? $this->facebookAccount($account, $day)
            : $this->instagramAccount($account, $day);
    }

    public function maxRangeDays(SocialAccount $account): int
    {
        return $account->network === Network::Facebook ? self::RANGE_DAYS : 0;
    }

    public function accountInsightsRange(SocialAccount $account, CarbonInterface $from, CarbonInterface $to): array
    {
        $out = [];

        for ($d = CarbonImmutable::instance($from)->startOfDay(); $d->lte($to); $d = $d->addDay()) {
            $out[$d->format('Y-m-d')] = Metric::blankAccount();
        }

        if ($account->network !== Network::Facebook) {
            return $out; // Instagram se lee dia a dia (maxRangeDays = 0)
        }

        $id = (string) ($account->meta['page_id'] ?? $account->external_id);
        $token = (string) $account->access_token;
        $range = ['since' => $from->format('Y-m-d'), 'until' => CarbonImmutable::instance($to)->startOfDay()->addDay()->format('Y-m-d')];

        foreach (['page_media_view' => Metric::VIEWS, 'page_post_engagements' => Metric::INTERACTIONS, 'page_follows' => Metric::FOLLOWERS] as $metric => $key) {
            $json = $this->safe(fn () => $this->graph->get("$id/insights", ['metric' => $metric, 'period' => 'day'] + $range, $token, true), $metric);

            foreach ($this->valuesByDay($json['data'][0]['values'] ?? []) as $date => $value) {
                if (isset($out[$date])) {
                    $out[$date][$key] = $value;
                }
            }
        }

        return $out;
    }

    public function canReadPost(PostTarget $target): bool
    {
        // Un contenedor de Instagram (media aun sin publicar) no tiene metricas: solo una media publicada las tiene.
        if (($target->progress['external_id_kind'] ?? null) === 'container') {
            return false;
        }

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

        $out[Metric::VIEWS] = $this->seriesValue($id, 'page_media_view', $range, $token, $day);
        $out[Metric::INTERACTIONS] = $this->seriesValue($id, 'page_post_engagements', $range, $token, $day);
        $out[Metric::FOLLOWERS] = $this->seriesValue($id, 'page_follows', $range, $token, $day);

        if ($out[Metric::FOLLOWERS] === null && $this->isRecent($account, $day)) {
            $json = $this->safe(fn () => $this->graph->get($id, ['fields' => 'followers_count'], $token, true), 'followers_count');
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
            'total_interactions' => Metric::INTERACTIONS,
        ] as $metric => $key) {
            $json = $this->safe(fn () => $this->graph->get("$id/insights", ['metric' => $metric, 'metric_type' => 'total_value', 'period' => 'day'] + $range, $token, true), $metric);
            $out[$key] = $this->numberOrNull($json['data'][0]['total_value']['value'] ?? null);
        }

        // follower_count NO admite total_value: serie diaria de nuevos seguidores.
        $out[Metric::FOLLOWERS_GAINED] = $this->seriesValue($id, 'follower_count', $range, $token, $day);

        if ($this->isRecent($account, $day)) {
            $json = $this->safe(fn () => $this->graph->get($id, ['fields' => 'followers_count'], $token, true), 'followers_count');
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
            $json = $this->graph->get("$mediaId/insights", ['metric' => implode(',', array_keys($map))], $token, true);
        } catch (PermanentPublishException) {
            // Un tipo de media (p. ej. story) puede no admitir alguna: se pide una a una y lo rechazado queda `null`.
            foreach ($map as $metric => $key) {
                $one = $this->safe(fn () => $this->graph->get("$mediaId/insights", ['metric' => $metric], $token, true), $metric);
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

        $fields = $this->safe(fn () => $this->graph->get($postId, ['fields' => 'reactions.summary(true).limit(0),comments.summary(true).limit(0),shares'], $token, true), 'post_fields');

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

    /**
     * Valor de $day de una metrica `period=day`: el de la serie cuyo `end_time` cierra ese dia del Pacifico (no
     * `values[0]`). Sin `end_time` en ningun valor solo se acepta una serie de una unica entrada.
     */
    private function seriesValue(string $id, string $metric, array $range, string $token, CarbonInterface $day): int|float|null
    {
        $json = $this->safe(fn () => $this->graph->get("$id/insights", ['metric' => $metric, 'period' => 'day'] + $range, $token, true), $metric);
        $values = $json['data'][0]['values'] ?? [];
        $byDay = $this->valuesByDay($values);

        if ($byDay === [] && count($values) === 1) {
            return $this->numberOrNull($values[0]['value'] ?? null);
        }

        return $byDay[$day->format('Y-m-d')] ?? null;
    }

    /**
     * Valores de una serie diaria indexados por dia del Pacifico: `end_time` es el FIN del periodo, asi que el dia al que
     * pertenece el valor es el del instante inmediatamente anterior.
     *
     * @param  array<int, array<string, mixed>>  $values
     * @return array<string, int|float|null> [Y-m-d => valor]
     */
    private function valuesByDay(array $values): array
    {
        $out = [];

        foreach ($values as $v) {
            $end = $v['end_time'] ?? null;

            if (! is_string($end)) {
                continue;
            }

            try {
                $day = CarbonImmutable::parse($end)->setTimezone(self::ZONE)->subSecond()->format('Y-m-d');
            } catch (\Throwable) {
                continue;
            }

            $out[$day] = $this->numberOrNull($v['value'] ?? null);
        }

        return $out;
    }

    private function lifetimeValue(string $id, string $metric, string $token): int|float|null
    {
        $json = $this->safe(fn () => $this->graph->get("$id/insights", ['metric' => $metric], $token, true), $metric);

        return $this->numberOrNull($json['data'][0]['values'][0]['value'] ?? null);
    }

    /**
     * Ejecuta una lectura; un rechazo PERMANENTE de la metrica (retirada, sin permiso) = `[]` ("no disponible").
     * Los transitorios (red, 5xx, limites de uso 4/17/32/613/800xx) NO se tragan: suben al job.
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

    /** El stock (seguidores) solo vale para el ultimo dia cerrado de la red: el de un dia anterior seria el valor de HOY. */
    private function isRecent(SocialAccount $account, CarbonInterface $day): bool
    {
        return CarbonImmutable::createFromFormat('!Y-m-d', $day->format('Y-m-d'), 'UTC')->gte(Days::closed($account->network));
    }

    private function numberOrNull(mixed $v): int|float|null
    {
        return is_numeric($v) ? $v + 0 : null;
    }
}
