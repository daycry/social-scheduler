<?php

namespace App\Analytics;

use App\Connectors\ConnectorRegistry;
use App\Connectors\Contracts\RangeInsightsReader;
use App\Connectors\Exceptions\PermanentPublishException;
use App\Enums\PostStatus;
use App\Models\AccountSnapshot;
use App\Models\PostMetricSnapshot;
use App\Models\PostTarget;
use App\Models\SocialAccount;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\Log;

/**
 * Convierte lo que devuelve el lector de metricas de cada red en snapshots idempotentes:
 *  - una fila por cuenta y dia (`account_snapshots`, upsert por cuenta+fecha);
 *  - una fila por destino y dia (`post_metric_snapshots`, upsert por destino+fecha).
 *
 * Al reescribir un snapshot, una metrica que ahora llega `null` NO pisa un valor ya guardado (un fallo puntual de
 * la API no borra historico); una que llega con valor SI lo actualiza (los datos recientes se consolidan).
 * Un snapshot de cuenta queda "asentado" (`settled_at`) cuando esta COMPLETO (todos los flujos diarios esperados de la
 * red; los stocks no cuentan) o cuando su dia sale de la ventana de reintentos (`settle_days`). Opera sin usuario
 * autenticado (jobs): siempre fuera del UserScope y con `user_id` explicito.
 */
class SnapshotCollector
{
    public function __construct(private readonly ConnectorRegistry $connectors) {}

    /** Guarda el snapshot de la cuenta para $day; `null` si la red no tiene lector. */
    public function collectAccount(SocialAccount $account, CarbonInterface $day): ?AccountSnapshot
    {
        $reader = $this->connectors->insightsFor($account->network);

        if ($reader === null) {
            return null;
        }

        try {
            $fresh = Metric::clean($reader->accountInsights($account, $day), Metric::ACCOUNT);
        } catch (PermanentPublishException $e) {
            // La red rechaza las metricas de esta cuenta (p. ej. scope no concedido): "no disponible", no es un error del job.
            Log::notice('analytics.account_unavailable', ['account_id' => $account->id, 'network' => $account->network->value, 'code' => $e->errorCode]);
            $fresh = Metric::blankAccount();
        }

        return $this->store($account, $day, $fresh);
    }

    /**
     * Historico por rango: UNA lectura (o pocas) para varios dias, en vez de una por dia. Solo escribe los dias que aun
     * necesitan datos. Los stocks no se rellenan (la API no da el valor de un dia pasado).
     *
     * @return int Dias guardados.
     */
    public function collectRange(SocialAccount $account, CarbonInterface $from, CarbonInterface $to): int
    {
        $reader = $this->rangeReader($account);

        if ($reader === null) {
            return 0;
        }

        $pending = [];

        for ($d = CarbonImmutable::instance($from)->startOfDay(); $d->lte($to); $d = $d->addDay()) {
            if ($this->needsCollection($account, $d)) {
                $pending[] = $d;
            }
        }

        if ($pending === []) {
            return 0;
        }

        try {
            $byDay = $reader->accountInsightsRange($account, $pending[0], end($pending));
        } catch (PermanentPublishException $e) {
            Log::notice('analytics.account_unavailable', ['account_id' => $account->id, 'network' => $account->network->value, 'code' => $e->errorCode]);
            $byDay = [];
        }

        foreach ($pending as $day) {
            $this->store($account, $day, Metric::clean($byDay[$day->format('Y-m-d')] ?? [], Metric::ACCOUNT));
        }

        return count($pending);
    }

    /** Lector con soporte de rango para esta cuenta, o `null` (se leera dia a dia). */
    public function rangeReader(SocialAccount $account): ?RangeInsightsReader
    {
        $reader = $this->connectors->insightsFor($account->network);

        return $reader instanceof RangeInsightsReader && $reader->maxRangeDays($account) > 1 ? $reader : null;
    }

    /**
     * ¿Hay que (volver a) leer ese dia? Si no hay snapshot o aun no esta asentado. Un snapshot con solo stocks
     * (seguidores) NO esta completo: los flujos que falten se reintentan hasta que salga de la ventana.
     */
    public function needsCollection(SocialAccount $account, CarbonInterface $day): bool
    {
        $row = AccountSnapshot::withoutUserScope()->where('social_account_id', $account->id)->where('date', $day->format('Y-m-d'))->first();

        return $row === null || $row->settled_at === null;
    }

    /** @param array<string, int|float|null> $fresh */
    private function store(SocialAccount $account, CarbonInterface $day, array $fresh): AccountSnapshot
    {
        $date = $day->format('Y-m-d');
        $existing = AccountSnapshot::withoutUserScope()->where('social_account_id', $account->id)->where('date', $date)->first();
        $metrics = self::merge($existing?->metrics ?? [], $fresh, Metric::ACCOUNT);

        // Asentado = completo (todos los flujos esperados de la red) o fuera de la ventana de reintentos.
        $settled = Metric::isComplete($account->network->value, $metrics) || Days::isHistoric($account->network, $day);

        return AccountSnapshot::withoutUserScope()->updateOrCreate(
            ['social_account_id' => $account->id, 'date' => $date],
            [
                'user_id' => $account->user_id,
                'brand_id' => $account->brand_id,
                'network' => $account->network->value,
                'metrics' => $metrics,
                'collected_at' => now(),
                'settled_at' => $settled ? ($existing?->settled_at ?? now()) : null,
            ],
        );
    }

    /**
     * Snapshot (fechado hoy, UTC) de las publicaciones de la cuenta publicadas en las ultimas $withinHours horas.
     * Sin limite de tiempo (usos fuera de cola); los jobs usan `collectPostsBatch`.
     *
     * @return int Numero de destinos guardados.
     */
    public function collectPosts(SocialAccount $account, int $withinHours): int
    {
        $saved = 0;
        $offset = 0;

        do {
            $r = $this->collectPostsBatch($account, $withinHours, $offset, PHP_FLOAT_MAX);
            $saved += $r['saved'];
            $offset = $r['next'];
        } while ($offset !== null);

        return $saved;
    }

    /**
     * Un bloque de publicaciones con presupuesto de tiempo: lee desde $offset (orden estable por fecha de publicacion)
     * mientras quepan; si no caben todas devuelve el desplazamiento por donde continuar (`next`).
     *
     * @return array{saved: int, next: int|null} `next` = null cuando no queda nada por leer.
     */
    public function collectPostsBatch(SocialAccount $account, int $withinHours, int $offset, float $budgetSeconds): array
    {
        $reader = $this->connectors->insightsFor($account->network);

        if ($reader === null) {
            return ['saved' => 0, 'next' => null];
        }

        $max = max(0, (int) config('social.analytics.max_posts_per_account', 50));
        $size = min(25, $max - $offset);

        if ($size <= 0) {
            return ['saved' => 0, 'next' => null];
        }

        $targets = PostTarget::withoutUserScope()
            ->with(['account' => fn ($q) => $q->withoutGlobalScopes()])
            ->where('social_account_id', $account->id)
            ->where('status', PostStatus::Published->value)
            ->whereNotNull('external_id')
            ->where('published_at', '>=', now()->subHours($withinHours))
            ->orderByDesc('published_at')->orderByDesc('id')
            ->offset($offset)->limit($size)
            ->get();

        $saved = 0;
        $done = 0;
        $started = microtime(true);
        $slowest = 0.0;
        $today = now()->utc()->format('Y-m-d');

        foreach ($targets as $target) {
            // Siempre se lee al menos una por pasada; despues solo si cabe otra de la duracion de la mas lenta.
            if ($done > 0 && (microtime(true) - $started) + $slowest > $budgetSeconds) {
                return ['saved' => $saved, 'next' => $offset + $done];
            }

            $t0 = microtime(true);
            $done++;

            if (! $reader->canReadPost($target)) {
                continue;
            }

            try {
                $fresh = Metric::clean($reader->postInsights($account, (string) $target->external_id), Metric::POST);
            } catch (PermanentPublishException $e) {
                Log::notice('analytics.post_unavailable', ['target_id' => $target->id, 'code' => $e->errorCode]);
                $fresh = Metric::blankPost();
            }

            $existing = PostMetricSnapshot::withoutUserScope()->where('post_target_id', $target->id)->where('date', $today)->first();

            PostMetricSnapshot::withoutUserScope()->updateOrCreate(
                ['post_target_id' => $target->id, 'date' => $today],
                [
                    'user_id' => $target->user_id,
                    'social_account_id' => $account->id,
                    'network' => $account->network->value,
                    'metrics' => self::merge($existing?->metrics ?? [], $fresh, Metric::POST),
                    'collected_at' => now(),
                ],
            );
            $saved++;
            $slowest = max($slowest, microtime(true) - $t0);
        }

        // Bloque completo y quedan publicaciones por debajo del tope: sigue el siguiente bloque.
        $more = $targets->count() === $size && $offset + $size < $max;

        return ['saved' => $saved, 'next' => $more ? $offset + $size : null];
    }

    /**
     * @param  array<string, mixed>  $old
     * @param  array<string, int|float|null>  $fresh
     * @param  list<string>  $keys
     * @return array<string, int|float|null>
     */
    private static function merge(array $old, array $fresh, array $keys): array
    {
        $out = [];

        foreach ($keys as $key) {
            $out[$key] = $fresh[$key] ?? (is_numeric($old[$key] ?? null) ? $old[$key] : null);
        }

        return $out;
    }
}
