<?php

namespace App\Connectors\Pinterest;

use App\Analytics\Days;
use App\Analytics\Metric;
use App\Connectors\Contracts\RangeInsightsReader;
use App\Connectors\Exceptions\PermanentPublishException;
use App\Connectors\Network;
use App\Models\PostTarget;
use App\Models\SocialAccount;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

/**
 * Metricas de Pinterest (T-21). Contrato leido en el OpenAPI oficial `pinterest/api-description` v5.28.0 (2026-11-12): ambas respuestas son
 * `{ "ALL": { daily_metrics: [{date, data_status, metrics}], summary_metrics, lifetime_metrics? } }` (con `split_field` omitido el grupo es `ALL`).
 *
 *  - Cuenta (`GET /v5/user_account/analytics`, scope `user_accounts:read`, `start_date`/`end_date` UTC, hasta 90 dias atras y 90 de rango):
 *    IMPRESSION -> VIEWS, ENGAGEMENT -> INTERACTIONS, OUTBOUND_CLICK -> CLICKS. Solo vale un dia con `data_status` READY; PROCESSING/BEFORE_* o
 *    ausente es `null` (se reintenta), nunca 0. FOLLOWERS y POST_COUNT salen de `GET /v5/user_account` (`follower_count`, `pin_count`) y solo son
 *    validos para el ultimo dia cerrado (la API no da el valor de un dia pasado).
 *  - Pin (`GET /v5/pins/{id}/analytics`, scopes `pins:read` + `boards:read`): `summary_metrics` de los ultimos 89 dias (la API no admite mas) con
 *    IMPRESSION -> VIEWS, SAVE -> SAVED; `lifetime_metrics` TOTAL_REACTIONS -> LIKES y TOTAL_COMMENTS -> COMMENTS; INTERACTIONS = SAVE + PIN_CLICK +
 *    OUTBOUND_CLICK + reacciones + comentarios (los presentes). Pinterest no da alcance ni comparticiones: `null`.
 *
 * Ambos endpoints estan `x-sandbox: disabled`: con `PINTEREST_API_HOST` apuntando al sandbox responden error. Un rechazo permanente de la analitica
 * NO impide leer el perfil (seguidores y pines, `x-sandbox: enabled`) y deja en `meta.analytics_unavailable` la razon (`sandbox` si el host es el
 * sandbox, `access` si no: falta de permiso o app sin acceso estandar) para que `MetricCoverage` la explique en vez de un `no_data` eterno; se
 * borra en cuanto la analitica responde.
 */
class PinterestInsightsReader implements RangeInsightsReader
{
    private const ACCOUNT_METRICS = 'IMPRESSION,ENGAGEMENT,OUTBOUND_CLICK';

    private const PIN_METRICS = 'IMPRESSION,SAVE,PIN_CLICK,OUTBOUND_CLICK,TOTAL_COMMENTS,TOTAL_REACTIONS';

    /** La API rechaza `start_date` a mas de 90 dias de hoy: se pide con un dia de margen. */
    private const PIN_WINDOW_DAYS = 89;

    private const MAX_RANGE_DAYS = 30;

    public function __construct(private readonly PinterestClient $client = new PinterestClient) {}

    public function maxRangeDays(SocialAccount $account): int
    {
        return self::MAX_RANGE_DAYS;
    }

    public function accountInsights(SocialAccount $account, CarbonInterface $day): array
    {
        $label = $day->format('Y-m-d');
        $daily = $this->guardedDaily($account, $label, $label);
        $out = $this->accountDay($daily[$label] ?? null);

        if (CarbonImmutable::createFromFormat('!Y-m-d', $label, 'UTC')->gte(Days::closed(Network::Pinterest))) {
            $profile = $this->profile($account);
            $out[Metric::FOLLOWERS] = $this->count($profile['follower_count'] ?? null);
            $out[Metric::POST_COUNT] = $this->count($profile['pin_count'] ?? null);
        }

        return $out;
    }

    public function accountInsightsRange(SocialAccount $account, CarbonInterface $from, CarbonInterface $to): array
    {
        $daily = $this->guardedDaily($account, $from->format('Y-m-d'), $to->format('Y-m-d'));
        $out = [];

        for ($d = CarbonImmutable::instance($from)->startOfDay(); $d->lte($to); $d = $d->addDay()) {
            $out[$d->format('Y-m-d')] = $this->accountDay($daily[$d->format('Y-m-d')] ?? null);
        }

        return $out;
    }

    public function canReadPost(PostTarget $target): bool
    {
        return $target->external_id !== null && preg_match('/^\d{1,30}$/D', (string) $target->external_id) === 1;
    }

    public function postInsights(SocialAccount $account, string $externalId): array
    {
        $out = Metric::blankPost();
        $today = CarbonImmutable::now('UTC');
        $json = $this->client->api('GET', 'v5/pins/'.rawurlencode($externalId).'/analytics', (string) $account->access_token, query: [
            'start_date' => $today->subDays(self::PIN_WINDOW_DAYS)->format('Y-m-d'),
            'end_date' => $today->subDay()->format('Y-m-d'),
            'metric_types' => self::PIN_METRICS,
        ], read: true)->json();

        $group = $this->group($json);
        $summary = (array) ($group['summary_metrics'] ?? []);
        $lifetime = (array) ($group['lifetime_metrics'] ?? []);

        $out[Metric::VIEWS] = $this->count($summary['IMPRESSION'] ?? null);
        $out[Metric::SAVED] = $this->count($summary['SAVE'] ?? null);
        $out[Metric::LIKES] = $this->count($lifetime['TOTAL_REACTIONS'] ?? null);
        $out[Metric::COMMENTS] = $this->count($lifetime['TOTAL_COMMENTS'] ?? null);
        $out[Metric::INTERACTIONS] = $this->sum([
            $out[Metric::SAVED], $this->count($summary['PIN_CLICK'] ?? null), $this->count($summary['OUTBOUND_CLICK'] ?? null),
            $out[Metric::LIKES], $out[Metric::COMMENTS],
        ]);

        return $out;
    }

    // ------------------------------------------------------------------ lectura

    /**
     * Registros diarios READY por fecha.
     *
     * @return array<string, array<string, mixed>>
     */
    private function accountDaily(SocialAccount $account, string $from, string $to): array
    {
        $json = $this->client->api('GET', 'v5/user_account/analytics', (string) $account->access_token, query: [
            'start_date' => $from, 'end_date' => $to, 'metric_types' => self::ACCOUNT_METRICS,
        ], read: true)->json();

        $byDate = [];
        foreach ((array) ($this->group($json)['daily_metrics'] ?? []) as $row) {
            if (is_array($row) && is_string($row['date'] ?? null) && ($row['data_status'] ?? null) === 'READY') {
                $byDate[$row['date']] = (array) ($row['metrics'] ?? []);
            }
        }

        return $byDate;
    }

    /** Lee la analitica de cuenta; un rechazo permanente no es un error: queda anotado en la cuenta y los dias salen en blanco. */
    private function guardedDaily(SocialAccount $account, string $from, string $to): array
    {
        try {
            $daily = $this->accountDaily($account, $from, $to);
        } catch (PermanentPublishException) {
            $this->flagUnavailable($account, str_contains(strtolower((string) config('social.pinterest.api_host')), 'sandbox') ? 'sandbox' : 'access');

            return [];
        }

        $this->flagUnavailable($account, null);

        return $daily;
    }

    private function flagUnavailable(SocialAccount $account, ?string $reason): void
    {
        $meta = (array) ($account->meta ?? []);
        $current = $meta['analytics_unavailable']['reason'] ?? null;

        if ($current === $reason) {
            return;
        }

        if ($reason === null) {
            unset($meta['analytics_unavailable']);
        } else {
            $meta['analytics_unavailable'] = ['reason' => $reason, 'metrics' => [Metric::VIEWS, Metric::INTERACTIONS, Metric::CLICKS], 'since' => now()->toIso8601String()];
        }

        $account->forceFill(['meta' => $meta])->save();
    }

    /** @return array<string, int|float|null> */
    private function accountDay(?array $metrics): array
    {
        $out = Metric::blankAccount();

        if ($metrics === null) {
            return $out;
        }

        $out[Metric::VIEWS] = $this->count($metrics['IMPRESSION'] ?? null);
        $out[Metric::INTERACTIONS] = $this->count($metrics['ENGAGEMENT'] ?? null);
        $out[Metric::CLICKS] = $this->count($metrics['OUTBOUND_CLICK'] ?? null);

        return $out;
    }

    /** Perfil con los contadores actuales; un rechazo permanente (sin permiso) lo deja vacio sin romper la lectura de flujos. */
    private function profile(SocialAccount $account): array
    {
        try {
            return (array) ($this->client->api('GET', 'v5/user_account', (string) $account->access_token, read: true)->json() ?? []);
        } catch (PermanentPublishException) {
            return [];
        }
    }

    /** Grupo `ALL` de la respuesta (o el primero si Pinterest lo nombrara de otro modo). */
    private function group(mixed $json): array
    {
        if (! is_array($json)) {
            return [];
        }

        $group = $json['ALL'] ?? (is_array(reset($json)) ? reset($json) : []);

        return is_array($group) ? $group : [];
    }

    private function sum(array $values): int|float|null
    {
        $present = array_filter($values, fn ($v) => $v !== null);

        return $present === [] ? null : array_sum($present);
    }

    private function count(mixed $v): int|float|null
    {
        return is_numeric($v) ? $v + 0 : null;
    }
}
