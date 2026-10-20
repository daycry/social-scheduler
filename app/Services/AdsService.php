<?php

namespace App\Services;

use App\Connectors\Exceptions\ConnectorException;
use App\Connectors\Exceptions\PermanentPublishException;
use App\Connectors\Exceptions\ReconnectRequiredException;
use App\Connectors\Exceptions\TransientPublishException;
use App\Connectors\Meta\Ads;
use App\Connectors\Network;
use App\Models\SocialAccount;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;

/**
 * Meta Ads solo lectura (PA-11). Resuelve la cuenta de Meta del usuario con `ads_read`, lista sus cuentas publicitarias y
 * devuelve campanas con insights del periodo. Si no hay permiso/cuenta, el estado es «no disponible» con la instruccion de
 * activarlo (nunca un error). Respuestas cacheadas unos minutos POR USUARIO (limites de Graph).
 */
class AdsService
{
    public const PERIODS = [7, 30, 90];

    public function __construct(private readonly Ads $ads, private readonly SocialAccountService $accounts) {}

    /** Instruccion que acompana a todo estado «no disponible». */
    public const HOWTO = 'Activa el permiso ads_read en los casos de uso de la app de Meta (Marketing API) y vuelve a conectar tu cuenta de Meta.';

    /**
     * @return array{available: bool, reason: ?string, message: ?string, ad_accounts: list<array<string, mixed>>, selected: ?array<string, mixed>, period: int, totals: ?array<string, mixed>, campaigns: list<array<string, mixed>>}
     */
    public function overview(User $user, ?string $adAccountId = null, int $days = 30): array
    {
        $days = in_array($days, self::PERIODS, true) ? $days : 30;
        $base = ['available' => false, 'reason' => null, 'message' => null, 'ad_accounts' => [], 'selected' => null, 'period' => $days, 'totals' => null, 'campaigns' => []];

        $source = $this->source($user);
        if ($source === null) {
            return ['reason' => 'sin_permiso', 'message' => 'Meta Ads no está disponible. '.self::HOWTO] + $base;
        }
        if (! $source->isActive()) {
            return ['reason' => 'reconectar', 'message' => 'La cuenta de Meta necesita reconectarse. '.self::HOWTO] + $base;
        }

        $token = (string) $source->refresh_token;

        try {
            $adAccounts = $this->cached($user, 'accounts', fn () => $this->ads->adAccounts($token));
            $base['ad_accounts'] = $adAccounts;
            $base['available'] = true;

            if ($adAccounts === []) {
                return ['message' => 'Tu usuario de Meta no tiene cuentas publicitarias.'] + $base;
            }

            // Solo cuentas publicitarias que Meta devolvio para ESTE usuario (nunca un act_ arbitrario de la query).
            $selected = collect($adAccounts)->firstWhere('id', $adAccountId) ?? $adAccounts[0];
            $base['selected'] = $selected;

            $until = CarbonImmutable::now('UTC')->toDateString();
            $since = CarbonImmutable::now('UTC')->subDays($days - 1)->toDateString();
            $campaigns = $this->cached($user, "campaigns:{$selected['id']}", fn () => $this->ads->campaigns($token, $selected['id']));
            $totals = $this->cached($user, "totals:{$selected['id']}:$since:$until", fn () => $this->ads->insights($token, $selected['id'], $since, $until, false));
            $perCampaign = $this->cached($user, "by-campaign:{$selected['id']}:$since:$until", fn () => $this->ads->insights($token, $selected['id'], $since, $until, true));

            $base['totals'] = $totals[''] ?? null;
            $base['campaigns'] = collect($campaigns)->map(fn (array $c) => $c + ['insights' => $perCampaign[$c['id']] ?? null])->values()->all();

            return $base;
        } catch (ReconnectRequiredException $e) {
            $this->accounts->flagReconnection($source, $e->getMessage());

            return ['available' => false, 'reason' => 'reconectar', 'message' => 'La cuenta de Meta necesita reconectarse. '.self::HOWTO] + $base;
        } catch (TransientPublishException) {
            return ['available' => false, 'reason' => 'transitorio', 'message' => 'Meta no responde ahora mismo. Inténtalo de nuevo en unos minutos.'] + $base;
        } catch (PermanentPublishException $e) {
            if ($e->errorCode === 'permisos') {
                return ['available' => false, 'reason' => 'sin_permiso', 'message' => 'Meta rechazó la lectura de anuncios. '.self::HOWTO] + $base;
            }

            return ['available' => false, 'reason' => 'error', 'message' => 'Meta rechazó la consulta de anuncios.'] + $base;
        } catch (ConnectorException) {
            return ['available' => false, 'reason' => 'error', 'message' => 'No se pudo leer Meta Ads.'] + $base;
        }
    }

    /** Cuenta de Meta del usuario que concedio `ads_read` y conserva el token de usuario (la mas reciente). */
    public function source(User $user): ?SocialAccount
    {
        return SocialAccount::withoutUserScope()->where('user_id', $user->id)
            ->whereIn('network', [Network::Facebook->value, Network::Instagram->value])
            ->where('status', '!=', SocialAccount::STATUS_DISCONNECTED)
            ->orderByDesc('id')->get()
            ->first(fn (SocialAccount $a) => Ads::hasScope($a) && $a->refresh_token);
    }

    /** @template T @param \Closure(): T $load @return T */
    private function cached(User $user, string $key, \Closure $load): mixed
    {
        $ttl = max(0, (int) config('social.ads.cache_minutes', 10));

        return $ttl === 0 ? $load() : Cache::remember("ads:{$user->id}:$key", now()->addMinutes($ttl), $load);
    }
}
