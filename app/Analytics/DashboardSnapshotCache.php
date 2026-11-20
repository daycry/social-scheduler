<?php

namespace App\Analytics;

use App\Connectors\Network;
use App\Models\Brand;
use App\Models\SocialAccount;
use App\Models\User;
use Closure;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class DashboardSnapshotCache
{
    /** Guarda cálculos diarios. Las cuentas y publicaciones se consultan en cada petición. */
    public static function remember(User $user, Brand $brand, Period $period, ?Period $compare, ?Network $network, Collection $accounts, Closure $load): array
    {
        $versionKey = self::versionKey($brand->id);
        $version = Cache::get($versionKey);
        if ($version === null) {
            Cache::add($versionKey, (string) Str::uuid(), 86400);
            $version = Cache::get($versionKey);
        }
        if ($version === null) {
            return $load();
        }

        $accountState = ($network === null ? $accounts->sortBy('id') : $accounts)->map(fn (SocialAccount $account) => [
            $account->id, $account->network->value, $account->status,
            $network === null ? null : ($account->meta['analytics_unavailable'] ?? null),
        ])->values()->all();
        $closedDays = $accounts->pluck('network')->uniqueStrict()->mapWithKeys(
            fn (Network $net) => [$net->value => Days::closed($net)->toDateString()],
        )->sortKeys()->all();
        $key = 'dashboard-snapshots:'.hash('sha256', json_encode([
            $user->id, $brand->id, $version, $period->toArray(), $compare?->toArray(),
            $network?->value, $accountState, $closedDays,
        ], JSON_THROW_ON_ERROR));

        return Cache::flexible($key, [60, 300], $load, ['seconds' => 30]);
    }

    public static function invalidateAfterCommit(int $brandId): void
    {
        DB::afterCommit(fn () => Cache::forget(self::versionKey($brandId)));
    }

    private static function versionKey(int $brandId): string
    {
        return 'dashboard-snapshots-version:'.$brandId;
    }
}
