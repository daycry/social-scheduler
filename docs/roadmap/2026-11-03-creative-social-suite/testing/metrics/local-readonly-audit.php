<?php

use App\Analytics\Period;
use App\Models\AccountSnapshot;
use App\Models\Brand;
use App\Models\SocialAccount;
use App\Models\User;
use App\Services\AnalyticsService;
use Illuminate\Database\QueryException;

// Run only via `php artisan tinker --execute="require '.../local-readonly-audit.php';"`.
// SELECT-only audit: never print credentials, raw exception messages, or signed URLs.
try {
    $brand = Brand::withoutUserScope()->whereKey(55)->first(['id', 'user_id', 'name']);
    if (! $brand || mb_strtolower(trim($brand->name)) !== 'marble party race') {
        echo json_encode(['result' => 'local_brand_identity_mismatch', 'requested_brand_id' => 55]);

        return;
    }
    $accounts = SocialAccount::withoutUserScope()->where('brand_id', $brand->id)
        ->where('user_id', $brand->user_id)->orderBy('id')
        ->get(['id', 'user_id', 'brand_id', 'network', 'name', 'username', 'status', 'last_error']);
    $out = ['result' => 'identity_matched', 'brand_id' => $brand->id, 'brand_name' => $brand->name, 'accounts' => []];
    foreach ($accounts as $account) {
        $rows = AccountSnapshot::withoutUserScope()->where('user_id', $brand->user_id)
            ->where('brand_id', $brand->id)->where('social_account_id', $account->id)
            ->whereBetween('date', ['2026-10-01', '2026-11-03'])->orderBy('date')
            ->get(['date', 'metrics', 'collected_at', 'settled_at']);
        $metrics = [];
        foreach (['followers', 'views', 'reach', 'interactions'] as $metric) {
            $known = $rows->filter(fn ($row) => is_numeric($row->metrics[$metric] ?? null));
            $metrics[$metric] = ['days_with_values' => $known->count(), 'zero_days' => $known->filter(fn ($r) => (float) $r->metrics[$metric] === 0.0)->count()];
        }
        $out['accounts'][] = [
            'id' => $account->id, 'network' => $account->network->value, 'name' => $account->name,
            'username' => $account->username, 'status' => $account->status,
            'has_recorded_error' => filled($account->last_error),
            'snapshot_count' => $rows->count(), 'first_date' => $rows->first()?->date, 'last_date' => $rows->last()?->date,
            'latest_attempt_at' => $rows->max('collected_at')?->toIso8601String(),
            'settled_snapshots' => $rows->filter(fn ($r) => $r->settled_at !== null)->count(),
            'metrics' => $metrics,
        ];
    }
    $owner = User::query()->whereKey($brand->user_id)->first();
    if ($owner) {
        $summary = app(AnalyticsService::class)->summary($owner, $brand, Period::make('2026-10-01', '2026-11-03'));
        $out['analytics'] = array_map(fn ($card) => array_intersect_key($card, array_flip(['key', 'total', 'coverage', 'freshness'])), $summary['cards']);
    }
    $out['observed_at'] = now()->toIso8601String();
    echo json_encode($out, JSON_UNESCAPED_SLASHES);
} catch (Throwable $error) {
    echo json_encode(['result' => 'read_unavailable', 'error_category' => $error instanceof QueryException ? 'database_query' : 'runtime']);
}
