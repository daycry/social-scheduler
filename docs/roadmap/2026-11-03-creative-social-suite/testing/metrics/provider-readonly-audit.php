<?php

use App\Analytics\Days;
use App\Connectors\Exceptions\ConnectorException;
use App\Connectors\Meta\GraphClient;
use App\Connectors\Network;
use App\Models\Brand;
use App\Models\SocialAccount;

// Explicitly authorized diagnostic GETs only: no collectors, OAuth refresh, token writes or status changes.
// Execute via Artisan Tinker with its history redirected to TEMP. Never print raw exceptions/requests/responses.
try {
    $brand = Brand::withoutUserScope()->whereKey(55)->first(['id', 'user_id', 'name']);
    if (! $brand || mb_strtolower(trim($brand->name)) !== 'marble party race') {
        echo json_encode(['result' => 'local_brand_identity_mismatch']);

        return;
    }
    $accounts = SocialAccount::withoutUserScope()->where('brand_id', $brand->id)->where('user_id', $brand->user_id)
        ->whereIn('id', [1, 2])->orderBy('id')->get();
    $graph = app(GraphClient::class);
    $out = ['result' => 'read_only_provider_diagnostic', 'accounts' => []];
    foreach ($accounts as $account) {
        $expected = $account->id === 1 ? ['facebook', 'Marblepartyrace'] : ['instagram', 'marblepartyrace'];
        if ([$account->network->value, $account->name] !== $expected || ! filled($account->access_token) || ! filled($account->external_id)) {
            $out['accounts'][] = ['id' => $account->id, 'result' => 'identity_or_credential_unavailable'];

            continue;
        }
        $end = Days::closed($account->network);
        // Six closed calendar days stay below 7*24 hours even across the Pacific DST change.
        $from = $end->subDays(5)->format('Y-m-d');
        $to = $end->addDay()->format('Y-m-d');
        $row = ['id' => $account->id, 'network' => $account->network->value, 'from' => $from, 'to_exclusive' => $to, 'metrics' => []];
        $metrics = $account->network === Network::Facebook
            ? ['views' => 'page_media_view', 'interactions' => 'page_post_engagements']
            : ['views' => 'views', 'interactions' => 'total_interactions'];
        foreach ($metrics as $key => $metric) {
            try {
                $query = ['metric' => $metric, 'period' => 'day', 'since' => $from, 'until' => $to];
                if ($account->network === Network::Instagram) {
                    $query['metric_type'] = 'total_value';
                }
                $json = $graph->get($account->external_id.'/insights', $query, (string) $account->access_token, true);
                $data = $json['data'][0] ?? [];
                $values = array_filter(array_column($data['values'] ?? [], 'value'), 'is_numeric');
                $total = $data['total_value']['value'] ?? null;
                $row['metrics'][$key] = [
                    'result' => 'response_received', 'numeric_day_values' => count($values),
                    'numeric_total' => is_numeric($total) ? $total + 0 : ($values === [] ? null : array_sum($values)),
                ];
            } catch (ConnectorException $error) {
                $row['metrics'][$key] = ['result' => 'connector_error', 'category' => class_basename($error), 'code' => $error->errorCode];
            } catch (Throwable) {
                $row['metrics'][$key] = ['result' => 'runtime_error'];
            }
        }
        $out['accounts'][] = $row;
    }
    echo json_encode($out, JSON_UNESCAPED_SLASHES);
} catch (Throwable) {
    echo json_encode(['result' => 'read_unavailable']);
}
