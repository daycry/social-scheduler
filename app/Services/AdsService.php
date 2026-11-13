<?php

namespace App\Services;

use App\Connectors\Exceptions\ConnectorException;
use App\Connectors\Exceptions\PermanentPublishException;
use App\Connectors\Exceptions\ReconnectRequiredException;
use App\Connectors\Exceptions\TransientPublishException;
use App\Connectors\Meta\Ads;
use App\Connectors\Network;
use App\Models\Brand;
use App\Models\SocialAccount;
use App\Models\User;
use App\Support\PermissionHelp;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;

/**
 * Meta Ads solo lectura (PA-11). Resuelve la conexión Meta de la marca con `ads_read`, lista sus cuentas publicitarias y
 * devuelve campanas con insights del periodo. Si no hay permiso/cuenta, el estado es «no disponible» con la instruccion de
 * activarlo (nunca un error). Caché por propietario, marca y conexión, invalidada al cambiar credenciales.
 */
class AdsService
{
    public const PERIODS = [7, 30, 90];

    public function __construct(private readonly Ads $ads, private readonly SocialAccountService $accounts) {}

    /** Instruccion (la misma que el inbox, `PermissionHelp`) que acompana a todo estado «no disponible» por permisos. */
    public static function help(): string
    {
        return PermissionHelp::meta('Meta Ads', 'ads_read');
    }

    /**
     * @return array{available: bool, reason: ?string, message: ?string, help: ?string, ad_accounts: list<array<string, mixed>>, selected: ?array<string, mixed>, period: int, totals: ?array<string, mixed>, campaigns: list<array<string, mixed>>}
     */
    public function overview(User $user, ?Brand $brand, ?string $adAccountId = null, int $days = 30, ?array $range = null): array
    {
        $days = in_array($days, self::PERIODS, true) ? $days : 30;
        $base = ['available' => false, 'reason' => null, 'message' => null, 'help' => null, 'ad_accounts' => [], 'selected' => null, 'period' => $days, 'totals' => null, 'campaigns' => []];

        if ($brand === null) {
            return ['reason' => 'sin_marca', 'message' => 'Crea una marca y conecta su cuenta de Meta para consultar los anuncios.'] + $base;
        }
        $source = $this->source($user, $brand);
        if ($source === null) {
            return ['reason' => 'sin_permiso', 'message' => 'Meta Ads no está disponible. '.self::help(), 'help' => self::help()] + $base;
        }
        if (! $source->isActive()) {
            return ['reason' => 'reconectar', 'message' => 'La cuenta de Meta necesita reconectarse. '.self::help(), 'help' => self::help()] + $base;
        }

        $token = (string) $source->refresh_token;

        try {
            $adAccounts = $this->cached($source, 'accounts', fn () => $this->ads->adAccounts($token));
            $base['ad_accounts'] = $adAccounts;
            $base['available'] = true;

            if ($adAccounts === []) {
                return ['message' => 'Tu usuario de Meta no tiene cuentas publicitarias.'] + $base;
            }

            // Solo cuentas publicitarias que Meta devolvio para ESTE usuario (nunca un act_ arbitrario de la query).
            $selected = collect($adAccounts)->firstWhere('id', $adAccountId) ?? $adAccounts[0];
            $base['selected'] = $selected;

            // Con `$range` [desde, hasta] (informes) se consulta ese periodo exacto; sin el, los ultimos `$days` hasta hoy.
            [$since, $until] = $range ?? [CarbonImmutable::now('UTC')->subDays($days - 1)->toDateString(), CarbonImmutable::now('UTC')->toDateString()];
            $campaigns = $this->cached($source, "campaigns:{$selected['id']}", fn () => $this->ads->campaigns($token, $selected['id']));
            $totals = $this->cached($source, "totals:{$selected['id']}:$since:$until", fn () => $this->ads->insights($token, $selected['id'], $since, $until, false));
            $perCampaign = $this->cached($source, "by-campaign:{$selected['id']}:$since:$until", fn () => $this->ads->insights($token, $selected['id'], $since, $until, true));

            $base['totals'] = $totals[''] ?? null;
            $base['campaigns'] = collect($campaigns)->map(fn (array $c) => $c + ['insights' => $perCampaign[$c['id']] ?? null])->values()->all();

            return $base;
        } catch (ReconnectRequiredException $e) {
            $this->accounts->flagReconnection($source, $e->getMessage());

            return ['available' => false, 'reason' => 'reconectar', 'message' => 'La cuenta de Meta necesita reconectarse. '.self::help(), 'help' => self::help()] + $base;
        } catch (TransientPublishException) {
            return ['available' => false, 'reason' => 'transitorio', 'message' => 'Meta no responde ahora mismo. Inténtalo de nuevo en unos minutos.'] + $base;
        } catch (PermanentPublishException $e) {
            if ($e->errorCode === 'permisos') {
                return ['available' => false, 'reason' => 'sin_permiso', 'message' => 'Meta rechazó la lectura de anuncios. '.self::help(), 'help' => self::help()] + $base;
            }

            return ['available' => false, 'reason' => 'error', 'message' => 'Meta rechazó la consulta de anuncios.'] + $base;
        } catch (ConnectorException) {
            return ['available' => false, 'reason' => 'error', 'message' => 'No se pudo leer Meta Ads.'] + $base;
        }
    }

    /** Conexión Meta propia de esta marca con `ads_read` y token de usuario (la más reciente). */
    public function source(User $user, Brand $brand): ?SocialAccount
    {
        abort_unless((int) $brand->user_id === (int) $user->id, 404);

        return SocialAccount::withoutUserScope()->where('user_id', $user->id)
            ->where('brand_id', $brand->id)
            ->whereIn('network', [Network::Facebook->value, Network::Instagram->value])
            ->where('status', '!=', SocialAccount::STATUS_DISCONNECTED)
            ->orderByDesc('id')->get()
            ->first(fn (SocialAccount $a) => Ads::hasScope($a) && $a->refresh_token);
    }

    /** @template T @param \Closure(): T $load @return T */
    private function cached(SocialAccount $source, string $key, \Closure $load): mixed
    {
        $ttl = max(0, (int) config('social.ads.cache_minutes', 10));
        $credentialVersion = hash('sha256', (string) $source->refresh_token);

        return $ttl === 0 ? $load() : Cache::remember("ads:v2:{$source->user_id}:{$source->brand_id}:{$source->id}:$credentialVersion:$key", now()->addMinutes($ttl), $load);
    }
}
