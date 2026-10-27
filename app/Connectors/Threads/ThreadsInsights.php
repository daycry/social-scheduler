<?php

namespace App\Connectors\Threads;

use App\Analytics\Days;
use App\Analytics\Metric;
use App\Connectors\Contracts\RangeInsightsReader;
use App\Connectors\Exceptions\PermanentPublishException;
use App\Connectors\Network;
use App\Models\PostTarget;
use App\Models\SocialAccount;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * Metricas de Threads (documentacion verificada el 2026-10-27, T-54). Cada metrica se mapea a las claves canonicas de `Metric`; lo que la
 * API no da queda `null` («no disponible», nunca 0).
 *
 *  - Cuenta (`GET /{user}/threads_insights`, con `since`/`until` Unix desde 2024-04-13): `views` son las VISITAS AL PERFIL (serie diaria)
 *    -> PROFILE_VIEWS (no VIEWS: Threads no da visualizaciones de cuenta); `likes` -> LIKES; `replies` -> COMMENTS; `reposts + quotes`
 *    -> SHARES; `clicks` (por enlace) -> CLICKS (suma); INTERACTIONS = LIKES + COMMENTS + SHARES. `followers_count` -> FOLLOWERS, sin
 *    rango: solo para el ultimo dia cerrado. Los flujos son totales del dia: se piden dia a dia (`maxRangeDays` = 0).
 *  - Post (`GET /{id}/insights`, `lifetime`): `views` -> VIEWS, `likes`, `replies` -> COMMENTS, `reposts + quotes + shares` -> SHARES e
 *    INTERACTIONS = la suma. `views` y `shares` figuran «en desarrollo» en la documentacion: si la API los rechaza o no los da, `null`.
 *
 * Una metrica rechazada de forma PERMANENTE (sin permiso, retirada) queda `null`; las credenciales invalidas y los fallos pasajeros
 * (red, 5xx, limites de uso) suben al job.
 */
class ThreadsInsights implements RangeInsightsReader
{
    /** Primer dia con datos de cuenta (Unix 1712991600 = 2024-04-13). */
    private const FIRST_DAY = '2024-04-13';

    /** Horas que se recuerda que Threads rechazo una metrica (una metrica «en desarrollo» puede activarse mas tarde). */
    private const REJECTED_TTL_HOURS = 6;

    private const USER_METRICS = ['views', 'likes', 'replies', 'reposts', 'quotes', 'clicks'];

    private const POST_METRICS = ['views', 'likes', 'replies', 'reposts', 'quotes', 'shares'];

    public function __construct(private readonly ThreadsClient $client = new ThreadsClient) {}

    public function maxRangeDays(SocialAccount $account): int
    {
        return 0; // los totales del dia (likes, respuestas...) no se reparten por dia en una consulta de rango
    }

    public function accountInsights(SocialAccount $account, CarbonInterface $day): array
    {
        $out = Metric::blankAccount();
        $label = CarbonImmutable::createFromFormat('!Y-m-d', $day->format('Y-m-d'), 'UTC');

        if ($label->lt(CarbonImmutable::createFromFormat('!Y-m-d', self::FIRST_DAY, 'UTC'))) {
            return $out; // la API rechaza fechas anteriores al 13-04-2024
        }

        $zone = Days::zone(Network::Threads);
        $start = CarbonImmutable::createFromFormat('!Y-m-d', $day->format('Y-m-d'), $zone);
        $token = (string) $account->access_token;
        $id = $account->external_id;
        $range = ['since' => $start->getTimestamp(), 'until' => $start->addDay()->getTimestamp()];

        $rows = $this->rows($id.'/threads_insights', self::USER_METRICS, $range, $token);
        $byName = [];
        foreach ($rows as $row) {
            $byName[(string) ($row['name'] ?? '')] = $row;
        }

        $out[Metric::PROFILE_VIEWS] = $this->seriesValue($byName['views'] ?? null, $day, $zone);
        $out[Metric::LIKES] = $this->totalValue($byName['likes'] ?? null);
        $out[Metric::COMMENTS] = $this->totalValue($byName['replies'] ?? null);
        $out[Metric::SHARES] = $this->sum([$this->totalValue($byName['reposts'] ?? null), $this->totalValue($byName['quotes'] ?? null)]);
        $out[Metric::CLICKS] = $this->linkTotal($byName['clicks'] ?? null);
        $out[Metric::INTERACTIONS] = $this->sum([$out[Metric::LIKES], $out[Metric::COMMENTS], $out[Metric::SHARES]]);

        // `followers_count` no admite since/until: es el valor de HOY, solo valido para el ultimo dia cerrado.
        if ($label->gte(Days::closed(Network::Threads))) {
            $followers = $this->rows($id.'/threads_insights', ['followers_count'], [], $token);
            $out[Metric::FOLLOWERS] = $this->totalValue($followers[0] ?? null);
        }

        return $out;
    }

    public function accountInsightsRange(SocialAccount $account, CarbonInterface $from, CarbonInterface $to): array
    {
        $out = [];

        for ($d = CarbonImmutable::instance($from)->startOfDay(); $d->lte($to); $d = $d->addDay()) {
            $out[$d->format('Y-m-d')] = $this->accountInsights($account, $d);
        }

        return $out;
    }

    public function canReadPost(PostTarget $target): bool
    {
        // El ID de un contenedor (publicacion no localizada) no tiene metricas: solo una publicacion real las tiene.
        if (($target->progress['external_id_kind'] ?? null) === 'container') {
            return false;
        }

        return $target->external_id !== null && $target->external_id !== '';
    }

    public function postInsights(SocialAccount $account, string $externalId): array
    {
        $out = Metric::blankPost();
        $by = [];

        foreach ($this->rows($externalId.'/insights', self::POST_METRICS, [], (string) $account->access_token) as $row) {
            $by[(string) ($row['name'] ?? '')] = $this->lifetime($row);
        }

        $out[Metric::VIEWS] = $by['views'] ?? null;
        $out[Metric::LIKES] = $by['likes'] ?? null;
        $out[Metric::COMMENTS] = $by['replies'] ?? null;
        $out[Metric::SHARES] = $this->sum([$by['reposts'] ?? null, $by['quotes'] ?? null, $by['shares'] ?? null]);
        $out[Metric::INTERACTIONS] = $this->sum([$out[Metric::LIKES], $out[Metric::COMMENTS], $out[Metric::SHARES]]);

        return $out;
    }

    // ------------------------------------------------------------------ lectura

    /**
     * Pide las metricas juntas; si Threads rechaza el conjunto de forma permanente (una metrica en desarrollo o sin permiso) las pide
     * una a una y deja `null` las rechazadas, que recuerda en cache (`REJECTED_TTL_HOURS`) para no repetir el intento en cada lectura.
     *
     * @param  list<string>  $metrics
     * @param  array<string, int|string>  $range
     * @return list<array<string, mixed>>
     */
    private function rows(string $path, array $metrics, array $range, string $token): array
    {
        // Recuerda (por tipo de lectura y conjunto de metricas, TTL) cuales rechazo Threads: la siguiente lectura pide solo las demas, en UNA
        // peticion, en vez de repetir el conjunto fallido y las N individuales en cada post.
        $key = 'threads:insights:rejected:'.(str_contains($path, 'threads_insights') ? 'account' : 'post').':'.md5(implode(',', $metrics));
        $known = (array) Cache::get($key, []);
        $ask = array_values(array_diff($metrics, $known));

        if ($ask === []) {
            return [];
        }

        try {
            return (array) ($this->client->get($path, ['metric' => implode(',', $ask)] + $range, $token, true)['data'] ?? []);
        } catch (PermanentPublishException $e) {
            Log::debug('threads.insights_set_rejected', ['code' => $e->errorCode]);
        }

        $rows = [];
        $rejected = [];
        foreach ($ask as $metric) {
            try {
                $one = $this->client->get($path, ['metric' => $metric] + $range, $token, true);
            } catch (PermanentPublishException $e) {
                Log::notice('threads.insight_unavailable', ['metric' => $metric, 'code' => $e->errorCode]);
                $rejected[] = $metric;

                continue;
            }
            array_push($rows, ...(array) ($one['data'] ?? []));
        }

        if ($rejected !== []) {
            Cache::put($key, array_values(array_unique([...$known, ...$rejected])), now()->addHours(self::REJECTED_TTL_HOURS));
        }

        return $rows;
    }

    /** Valor del dia de una serie `period=day`: el cuyo `end_time` cierra ese dia en la zona de la red; con un unico valor sin hora, ese. */
    private function seriesValue(?array $row, CarbonInterface $day, string $zone): int|float|null
    {
        $values = (array) ($row['values'] ?? []);
        $byDay = [];

        foreach ($values as $v) {
            $end = $v['end_time'] ?? null;
            if (! is_string($end)) {
                continue;
            }
            try {
                $byDay[CarbonImmutable::parse($end)->setTimezone($zone)->subSecond()->format('Y-m-d')] = $this->number($v['value'] ?? null);
            } catch (\Throwable) {
                continue;
            }
        }

        if ($byDay === [] && count($values) === 1) {
            return $this->number($values[0]['value'] ?? null);
        }

        return $byDay[$day->format('Y-m-d')] ?? null;
    }

    private function totalValue(?array $row): int|float|null
    {
        return $row === null ? null : $this->number($row['total_value']['value'] ?? $row['values'][0]['value'] ?? null);
    }

    /** `clicks` llega por enlace (`link_total_values`): se suman. Una respuesta sin enlaces pulsados es 0, no «no disponible». */
    private function linkTotal(?array $row): int|float|null
    {
        if ($row === null) {
            return null;
        }

        if (isset($row['link_total_values']) && is_array($row['link_total_values'])) {
            return $this->sum(array_map(fn ($l) => $this->number($l['value'] ?? null), $row['link_total_values'])) ?? 0;
        }

        return $this->totalValue($row);
    }

    private function lifetime(array $row): int|float|null
    {
        return $this->number($row['values'][0]['value'] ?? $row['total_value']['value'] ?? null);
    }

    /** Suma de los valores presentes; `null` si no hay ninguno. */
    private function sum(array $values): int|float|null
    {
        $present = array_filter($values, fn ($v) => $v !== null);

        return $present === [] ? null : array_sum($present);
    }

    private function number(mixed $v): int|float|null
    {
        return is_numeric($v) ? $v + 0 : null;
    }
}
