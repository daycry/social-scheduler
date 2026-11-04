<?php

use App\Models\Brand;
use App\Support\HealthCheck;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

// Whitelisted diagnostics only; no job dispatch, queue probes, schedule run, health storage probe, or credential reads.
try {
    $brand = Brand::withoutUserScope()->whereKey(55)->first(['id', 'user_id', 'name']);
    if (! $brand || mb_strtolower(trim($brand->name)) !== 'marble party race') {
        echo json_encode(['result' => 'local_brand_identity_mismatch']);

        return;
    }
    $out = ['result' => 'read_only_pipeline_diagnostic', 'observed_at' => now()->toIso8601String(), 'config' => [
        'environment' => config('app.env'), 'queue_connection' => config('queue.default'),
        'metrics_queue' => config('social.analytics.queue'), 'worker_queue_names' => config('queue.names'),
        'daily_at' => config('social.analytics.daily_at'), 'app_timezone' => config('app.timezone'),
        'retry_after' => config('queue.connections.'.config('queue.default').'.retry_after'),
        'settle_days' => config('social.analytics.settle_days'), 'backfill_on_connect' => config('social.analytics.backfill_on_connect'),
        'cache_driver' => config('cache.default'),
    ]];
    try {
        $beat = Cache::get(HealthCheck::HEARTBEAT_KEY);
        $out['scheduler'] = ['heartbeat_present' => $beat !== null, 'heartbeat_age_seconds' => is_numeric($beat) ? now()->timestamp - (int) $beat : null];
    } catch (Throwable) {
        $out['scheduler'] = ['result' => 'cache_read_unavailable'];
    }
    $classes = ['App\\Jobs\\CollectSnapshots', 'App\\Jobs\\CollectAccountSnapshot', 'App\\Jobs\\BackfillMetrics', 'App\\Jobs\\RefreshPostMetrics'];
    $classify = function (string $exception): string {
        foreach (['ReconnectRequiredException', 'TransientPublishException', 'PermanentPublishException', 'MaxAttemptsExceededException', 'TimeoutExceededException', 'QueryException', 'ConnectionException'] as $category) {
            if (str_contains($exception, $category)) {
                return $category;
            }
        }

        return 'other';
    };
    foreach (['pending' => config('queue.connections.database.table', 'jobs'), 'failed' => config('queue.failed.table', 'failed_jobs')] as $kind => $table) {
        try {
            $query = DB::table($table)->where('queue', config('social.analytics.queue', 'metrics'));
            $out[$kind] = ['metrics_queue_count' => $query->count(), 'relevant_jobs' => []];
            $columns = $kind === 'pending' ? ['payload', 'attempts', 'created_at', 'available_at', 'reserved_at'] : ['payload', 'failed_at', 'exception'];
            foreach ($query->get($columns) as $row) {
                $payload = json_decode($row->payload, true) ?: [];
                $class = $payload['displayName'] ?? '';
                if (! in_array($class, $classes, true)) {
                    continue;
                }
                $command = $payload['data']['command'] ?? '';
                preg_match('/"accountId";i:(\d+);/', $command, $matches);
                $accountId = isset($matches[1]) ? (int) $matches[1] : null;
                if ($class !== 'App\\Jobs\\CollectSnapshots' && ! in_array($accountId, [1, 2], true)) {
                    continue;
                }
                $job = ['class' => class_basename($class), 'account_id' => $accountId];
                if ($kind === 'pending') {
                    $job += ['attempts' => $row->attempts, 'created_at' => gmdate('c', (int) $row->created_at),
                        'available_at' => gmdate('c', (int) $row->available_at), 'reserved' => $row->reserved_at !== null];
                } else {
                    $job += ['failed_at' => $row->failed_at, 'error_category' => $classify($row->exception)];
                }
                $out[$kind]['relevant_jobs'][] = $job;
            }
        } catch (Throwable) {
            $out[$kind] = ['result' => 'queue_read_unavailable'];
        }
    }
    echo json_encode($out, JSON_UNESCAPED_SLASHES);
} catch (Throwable) {
    echo json_encode(['result' => 'read_unavailable']);
}
