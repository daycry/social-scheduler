<?php

namespace App\Services;

use App\Connectors\Google\YouTubeQuota;
use App\Connectors\Network;
use App\Jobs\QueueProbeJob;
use App\Models\SocialAccount;
use App\Models\User;
use App\Support\HealthCheck;
use App\Support\SystemAccess;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/** Lectura administrativa global. No carga credenciales ni consulta las APIs sociales. */
final class SystemStatus
{
    public function __construct(
        private readonly HealthCheck $health,
        private readonly YouTubeQuota $youtube,
        private readonly QuotaPolicy $policy,
    ) {}

    /** @return array<string, mixed> */
    public function snapshot(User $admin): array
    {
        if (! SystemAccess::allows($admin)) {
            throw new AuthorizationException;
        }

        $observed = now()->toIso8601String();
        $health = $this->health->run(false, true);
        $quotas = [];
        $labels = [];
        foreach (Network::cases() as $network) {
            $labels[$network->value] = $network->label();
            $quotas[$network->value] = [
                'source' => 'unavailable', 'used' => null, 'limit' => null,
                'resets_at' => null, 'observed_at' => null,
                'unavailable_reason' => 'not_recorded',
            ];
        }
        try {
            $quotas['youtube'] = [
                'source' => 'local_counter', 'used' => $this->youtube->used(), 'limit' => $this->youtube->limit(),
                'resets_at' => $this->youtube->resetsAt()->toIso8601String(), 'observed_at' => $observed,
                'unavailable_reason' => null,
            ];
        } catch (Throwable $e) {
            $quotas['youtube']['unavailable_reason'] = 'read_failed';
            Log::warning('system.quota_observation_failed', ['exception' => $e::class]);
        }
        $tokensAvailable = true;
        try {
            $tokens = $this->tokens();
        } catch (Throwable $e) {
            $tokens = [];
            $tokensAvailable = false;
            Log::warning('system.token_observation_failed', ['exception' => $e::class]);
        }

        return [
            'observed_at' => $observed,
            'network_labels' => $labels,
            'native_cache_seconds' => max(0, (int) config('health.native_cache_seconds', 300)),
            'health' => $health,
            'tokens' => $tokens,
            'tokens_available' => $tokensAvailable,
            'queues' => $this->queues(),
            'quotas' => $quotas,
            'quota_policy' => $this->policy->policy(),
        ];
    }

    /** @return list<array{name: string, last_processed_at: ?string, available: bool}> */
    private function queues(): array
    {
        $names = array_values(array_unique([
            ...(array) config('queue.names', []),
            (string) config('media.import_queue', 'media'),
            (string) config('queue.studio_render', 'studio-render'),
        ]));

        try {
            return array_map(function (string $name): array {
                $last = Cache::get(QueueProbeJob::latestKey($name));

                return ['name' => $name, 'last_processed_at' => is_array($last) && is_string($last['processed_at'] ?? null) ? $last['processed_at'] : null, 'available' => true];
            }, $names);
        } catch (Throwable $e) {
            Log::warning('system.probe_observation_failed', ['exception' => $e::class]);

            return array_map(fn (string $name): array => ['name' => $name, 'last_processed_at' => null, 'available' => false], $names);
        }
    }

    /** @return array<string, array<string, int|string|null>> */
    private function tokens(): array
    {
        // Proyección agregada explícita: nunca seleccionar/decryptar access_token o refresh_token.
        $rows = DB::table('social_accounts')->where('status', '!=', SocialAccount::STATUS_DISCONNECTED)
            ->select('network')->selectRaw('COUNT(*) AS account_count, MIN(token_expires_at) AS expires_at,
                MIN(refresh_expires_at) AS refresh_expires_at, MIN(data_access_expires_at) AS data_access_expires_at,
                SUM(CASE WHEN token_expires_at IS NULL THEN 1 ELSE 0 END) AS unknown_expiry_count')
            ->groupBy('network')->orderBy('network')->get();
        $tokens = [];
        foreach ($rows as $row) {
            $tokens[$row->network] = [
                'account_count' => (int) $row->account_count,
                'expires_at' => $this->date($row->expires_at),
                'refresh_expires_at' => $this->date($row->refresh_expires_at),
                'data_access_expires_at' => $this->date($row->data_access_expires_at),
                'unknown_expiry_count' => (int) $row->unknown_expiry_count,
            ];
        }

        return $tokens;
    }

    private function date(?string $date): ?string
    {
        return $date === null ? null : CarbonImmutable::parse($date, 'UTC')->toIso8601String();
    }
}
