<?php

namespace App\Services\Goals;

use App\Analytics\Calculator;
use App\Analytics\Days;
use App\Analytics\Metric;
use App\Connectors\Network;
use App\Models\AccountSnapshot;
use App\Models\Brand;
use App\Models\Goal;
use App\Models\SocialAccount;
use App\Models\User;
use App\Services\ConnectionExpiry;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/** Valores locales de días cerrados. Una cuenta sin dato invalida el agregado, nunca aporta cero. */
final class GoalObservations
{
    /** @return array{period: string, current: ?float, previous: null, expires_at: ?string} */
    public function token(User $user, Brand $brand, Goal $goal): array
    {
        $now = CarbonImmutable::instance(now());
        $value = ['period' => $now->setTimezone($brand->timezone ?: 'UTC')->toDateString(), 'current' => null, 'previous' => null, 'expires_at' => null];
        if ($goal->social_account_id === null) {
            return $value;
        }
        $account = SocialAccount::ownedBy($user->id)->where('brand_id', $brand->id)->whereKey($goal->social_account_id)
            ->where('status', '!=', SocialAccount::STATUS_DISCONNECTED)
            ->select('id', 'network', 'status', 'token_expires_at', 'refresh_expires_at', 'data_access_expires_at')
            ->selectRaw('CASE WHEN network = ? THEN refresh_token ELSE NULL END AS refresh_token', [Network::LinkedIn->value])->first();
        if ($account === null) {
            return $value;
        }
        $deadline = ConnectionExpiry::knownDeadline($account);
        if ($deadline === null) {
            return $value;
        }
        $expiry = CarbonImmutable::instance($deadline['at'])->utc();
        $value['current'] = $now->diffInSeconds($expiry, false) / 86400;
        $value['expires_at'] = $expiry->toIso8601String();

        return $value;
    }

    /** @return array{period: string, current: ?float, previous: ?float} */
    public function read(User $user, Brand $brand, Goal $goal): array
    {
        $accounts = SocialAccount::ownedBy($user->id)->where('brand_id', $brand->id)
            ->where('status', '!=', SocialAccount::STATUS_DISCONNECTED)
            ->when($goal->social_account_id !== null, fn ($query) => $query->whereKey($goal->social_account_id))
            ->get(['id', 'network']);
        $brandDay = CarbonImmutable::instance(now())->setTimezone($brand->timezone ?: 'UTC')->subDay()->toDateString();
        $day = CarbonImmutable::createFromFormat('!Y-m-d', $brandDay, 'UTC');
        foreach ($accounts as $account) {
            $day = $day->min(Days::closed($account->network));
        }
        $period = $day->toDateString();
        $previous = $day->subDay()->toDateString();
        $accounts = $accounts->map(fn ($account) => (int) $account->id)->all();
        $snapshots = AccountSnapshot::ownedBy($user->id)->where('brand_id', $brand->id)
            ->whereIn('social_account_id', $accounts)->whereIn('date', [$period, $previous])->get()
            ->groupBy('date');

        return [
            'period' => $period,
            'current' => $this->metricValue($snapshots->get($period, collect()), $accounts, $goal->metric),
            'previous' => $this->metricValue($snapshots->get($previous, collect()), $accounts, $goal->metric),
        ];
    }

    /** @param list<int> $accounts */
    private function metricValue(Collection $snapshots, array $accounts, ?string $metric): ?float
    {
        if ($accounts === [] || ! in_array($metric, ['followers', 'engagement'], true)) {
            return null;
        }
        $byAccount = $snapshots->keyBy('social_account_id');
        $total = 0.0;
        $views = 0.0;
        foreach ($accounts as $id) {
            $snapshot = $byAccount->get($id);
            if ($snapshot === null) {
                return null;
            }
            $metrics = Metric::clean($snapshot->metrics, Metric::ACCOUNT);
            if ($metric === 'followers') {
                if ($metrics[Metric::FOLLOWERS] === null) {
                    return null;
                }
                $total += $metrics[Metric::FOLLOWERS];
            } else {
                if ($metrics[Metric::INTERACTIONS] === null || $metrics[Metric::VIEWS] === null) {
                    return null;
                }
                $total += $metrics[Metric::INTERACTIONS];
                $views += $metrics[Metric::VIEWS];
            }
        }

        return $metric === 'followers' ? $total : Calculator::engagementRate($total, $views);
    }
}
