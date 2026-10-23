<?php

namespace App\Connectors\Meta;

use App\Models\SocialAccount;
use InvalidArgumentException;

/**
 * Meta Ads en SOLO LECTURA (PA-11): cuentas publicitarias (`me/adaccounts`), campanas e insights basicos. Usa el token de
 * USUARIO de Meta (el que guarda la cuenta como `refresh_token`; `me/adaccounts` no existe con un token de Pagina) en
 * `Authorization: Bearer`. Requiere el permiso `ads_read` (activarlo en los casos de uso de la app de Meta y reconectar).
 * Ningun metodo crea ni modifica nada.
 */
class Ads
{
    public const SCOPE = 'ads_read';

    private const INSIGHT_FIELDS = 'spend,impressions,clicks,ctr,cpc';

    public function __construct(private readonly GraphClient $graph = new GraphClient) {}

    public static function hasScope(SocialAccount $account): bool
    {
        return in_array(self::SCOPE, (array) $account->scopes, true);
    }

    /** @return list<array{id: string, name: string, currency: ?string, status: ?int}> */
    public function adAccounts(string $userToken): array
    {
        $json = $this->graph->get('me/adaccounts', ['fields' => 'id,name,currency,account_status', 'limit' => (int) config('social.ads.max_accounts', 25)], $userToken, true);

        return collect((array) ($json['data'] ?? []))->filter(fn ($a) => ! empty($a['id']))->map(fn ($a) => [
            'id' => (string) $a['id'],
            'name' => (string) ($a['name'] ?? $a['id']),
            'currency' => isset($a['currency']) ? (string) $a['currency'] : null,
            'status' => isset($a['account_status']) ? (int) $a['account_status'] : null,
        ])->values()->all();
    }

    /** @return list<array{id: string, name: string, status: ?string, objective: ?string}> */
    public function campaigns(string $userToken, string $adAccountId): array
    {
        $this->assertAdAccount($adAccountId);
        $json = $this->graph->get($adAccountId.'/campaigns', ['fields' => 'id,name,status,objective', 'limit' => (int) config('social.ads.max_campaigns', 50)], $userToken, true);

        return collect((array) ($json['data'] ?? []))->filter(fn ($c) => ! empty($c['id']))->map(fn ($c) => [
            'id' => (string) $c['id'],
            'name' => (string) ($c['name'] ?? $c['id']),
            'status' => $c['status'] ?? null,
            'objective' => $c['objective'] ?? null,
        ])->values()->all();
    }

    /**
     * Insights de la cuenta (total) o por campana del periodo [since, until] (YYYY-MM-DD).
     *
     * @return array<string, array{spend: ?float, impressions: ?int, clicks: ?int, ctr: ?float, cpc: ?float}> indexado por campaign_id ('' = total de la cuenta)
     */
    public function insights(string $userToken, string $adAccountId, string $since, string $until, bool $byCampaign): array
    {
        $this->assertAdAccount($adAccountId);
        $query = [
            'fields' => self::INSIGHT_FIELDS.($byCampaign ? ',campaign_id,campaign_name' : ''),
            'time_range' => json_encode(['since' => $since, 'until' => $until]),
            'level' => $byCampaign ? 'campaign' : 'account',
            'limit' => (int) config('social.ads.max_campaigns', 50),
        ];
        $json = $this->graph->get($adAccountId.'/insights', $query, $userToken, true);

        $out = [];
        foreach ((array) ($json['data'] ?? []) as $row) {
            $out[$byCampaign ? (string) ($row['campaign_id'] ?? '') : ''] = [
                'spend' => isset($row['spend']) ? (float) $row['spend'] : null,
                'impressions' => isset($row['impressions']) ? (int) $row['impressions'] : null,
                'clicks' => isset($row['clicks']) ? (int) $row['clicks'] : null,
                'ctr' => isset($row['ctr']) ? (float) $row['ctr'] : null,
                'cpc' => isset($row['cpc']) ? (float) $row['cpc'] : null,
            ];
        }

        return $out;
    }

    /** El id va en la RUTA de Graph: solo `act_<digitos>`. */
    private function assertAdAccount(string $id): void
    {
        if (preg_match('/^act_\d{1,30}$/', $id) !== 1) {
            throw new InvalidArgumentException('Cuenta publicitaria no valida.');
        }
    }
}
