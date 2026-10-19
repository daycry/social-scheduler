<?php

namespace App\Analytics;

use App\Connectors\ConnectorRegistry;
use App\Connectors\Exceptions\PermanentPublishException;
use App\Enums\PostStatus;
use App\Models\AccountSnapshot;
use App\Models\PostMetricSnapshot;
use App\Models\PostTarget;
use App\Models\SocialAccount;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\Log;

/**
 * Convierte lo que devuelve el lector de metricas de cada red en snapshots idempotentes:
 *  - una fila por cuenta y dia (`account_snapshots`, upsert por cuenta+fecha);
 *  - una fila por destino y dia (`post_metric_snapshots`, upsert por destino+fecha).
 *
 * Al reescribir un snapshot, una metrica que ahora llega `null` NO pisa un valor ya guardado (un fallo puntual de
 * la API no borra historico); una que llega con valor SI lo actualiza (los datos recientes se consolidan).
 * Opera sin usuario autenticado (jobs): siempre fuera del UserScope y con `user_id` explicito.
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

        $date = $day->format('Y-m-d');
        $existing = AccountSnapshot::withoutUserScope()->where('social_account_id', $account->id)->where('date', $date)->first();

        return AccountSnapshot::withoutUserScope()->updateOrCreate(
            ['social_account_id' => $account->id, 'date' => $date],
            [
                'user_id' => $account->user_id,
                'brand_id' => $account->brand_id,
                'network' => $account->network->value,
                'metrics' => self::merge($existing?->metrics ?? [], $fresh, Metric::ACCOUNT),
                'collected_at' => now(),
            ],
        );
    }

    /** ¿Ya hay un snapshot de la cuenta ese dia con algun dato? (para no reintentar lo ya recogido) */
    public function hasData(SocialAccount $account, CarbonInterface $day): bool
    {
        $row = AccountSnapshot::withoutUserScope()->where('social_account_id', $account->id)->where('date', $day->format('Y-m-d'))->first();

        return $row !== null && ! Metric::isEmpty($row->metrics ?? []);
    }

    /**
     * Snapshot (fechado hoy, UTC) de las publicaciones de la cuenta publicadas en las ultimas $withinHours horas.
     *
     * @return int Numero de destinos guardados.
     */
    public function collectPosts(SocialAccount $account, int $withinHours): int
    {
        $reader = $this->connectors->insightsFor($account->network);

        if ($reader === null) {
            return 0;
        }

        $targets = PostTarget::withoutUserScope()
            ->with(['account' => fn ($q) => $q->withoutGlobalScopes()])
            ->where('social_account_id', $account->id)
            ->where('status', PostStatus::Published->value)
            ->whereNotNull('external_id')
            ->where('published_at', '>=', now()->subHours($withinHours))
            ->orderByDesc('published_at')
            ->limit((int) config('social.analytics.max_posts_per_account', 50))
            ->get();

        $saved = 0;
        $today = now()->utc()->format('Y-m-d');

        foreach ($targets as $target) {
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
        }

        return $saved;
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
