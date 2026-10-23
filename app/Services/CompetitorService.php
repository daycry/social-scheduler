<?php

namespace App\Services;

use App\Connectors\Exceptions\ConnectorException;
use App\Connectors\Exceptions\ReconnectRequiredException;
use App\Connectors\Exceptions\TransientPublishException;
use App\Connectors\Meta\BusinessDiscovery;
use App\Connectors\Meta\CompetitorNotFound;
use App\Connectors\Network;
use App\Exceptions\PostValidationException;
use App\Models\AccountSnapshot;
use App\Models\Brand;
use App\Models\Competitor;
use App\Models\CompetitorSnapshot;
use App\Models\SocialAccount;
use App\Models\User;
use Illuminate\Support\Collection;

/**
 * Competidores de Instagram (PA-08): alta con comprobacion inmediata en Business Discovery, snapshot diario y
 * comparativa con la cuenta propia (seguidores, publicaciones y crecimiento a 7 y 30 dias).
 */
class CompetitorService
{
    public function __construct(private readonly BusinessDiscovery $discovery, private readonly SocialAccountService $accounts) {}

    /** Cuenta de Instagram activa de la marca desde la que se consulta (la mas antigua). */
    public function queryAccount(int $userId, int $brandId, ?int $preferredId = null): ?SocialAccount
    {
        $query = SocialAccount::withoutUserScope()->where('user_id', $userId)->where('brand_id', $brandId)
            ->where('network', Network::Instagram->value)->where('status', SocialAccount::STATUS_ACTIVE);

        if ($preferredId !== null && ($preferred = (clone $query)->find($preferredId))) {
            return $preferred;
        }

        return $query->orderBy('id')->first();
    }

    public function add(User $user, Brand $brand, string $username): Competitor
    {
        if ($brand->user_id !== $user->id) {
            throw new PostValidationException(['brand' => ['La marca no pertenece al usuario.']]);
        }

        $username = strtolower(ltrim(trim($username), '@'));
        if (preg_match(BusinessDiscovery::USERNAME_PATTERN, $username) !== 1) {
            throw new PostValidationException(['username' => ['Escribe un usuario de Instagram valido (letras, numeros, puntos y guiones bajos).']]);
        }

        $query = Competitor::withoutUserScope()->where('user_id', $user->id)->where('brand_id', $brand->id);
        if ((clone $query)->where('username', $username)->exists()) {
            throw new PostValidationException(['username' => ['Ya sigues a esa cuenta.']]);
        }
        if ((clone $query)->count() >= (int) config('social.competitors.max_per_brand', 10)) {
            throw new PostValidationException(['username' => ['Has alcanzado el maximo de competidores para esta marca.']]);
        }

        $account = $this->queryAccount($user->id, $brand->id);
        if (! $account) {
            throw new PostValidationException(['username' => ['Conecta una cuenta de Instagram activa en esta marca para comparar.']]);
        }
        if (strtolower((string) $account->username) === $username) {
            throw new PostValidationException(['username' => ['Esa es tu propia cuenta.']]);
        }

        try {
            $profile = $this->discovery->lookup($account, $username);
        } catch (CompetitorNotFound $e) {
            throw new PostValidationException(['username' => [$e->getMessage()]]);
        } catch (ReconnectRequiredException $e) {
            $this->accounts->flagReconnection($account, $e->getMessage());
            throw new PostValidationException(['username' => ['Tu cuenta de Instagram necesita reconectarse.']]);
        } catch (TransientPublishException) {
            throw new PostValidationException(['username' => ['Instagram no responde ahora mismo. Intentalo de nuevo en unos minutos.']]);
        } catch (ConnectorException) {
            throw new PostValidationException(['username' => ['Instagram rechazo la consulta. Revisa los permisos de la cuenta conectada.']]);
        }

        $competitor = new Competitor([
            'username' => strtolower($profile['username']),
            'name' => $profile['name'],
            'profile_picture_url' => $profile['profile_picture_url'],
            'social_account_id' => $account->id,
            'last_synced_at' => now(),
        ]);
        $competitor->user_id = $user->id;
        $competitor->brand_id = $brand->id;
        $competitor->save();

        $this->record($competitor, $profile);

        return $competitor;
    }

    /** Guarda (upsert) la foto de hoy (UTC) y refresca los datos del perfil. */
    public function record(Competitor $competitor, array $profile): CompetitorSnapshot
    {
        $competitor->forceFill([
            'name' => $profile['name'] ?? $competitor->name,
            'profile_picture_url' => $profile['profile_picture_url'] ?? $competitor->profile_picture_url,
            'last_synced_at' => now(),
            'last_error' => null,
        ])->save();

        return CompetitorSnapshot::withoutUserScope()->updateOrCreate(
            ['competitor_id' => $competitor->id, 'date' => now('UTC')->toDateString()],
            ['user_id' => $competitor->user_id, 'followers_count' => $profile['followers_count'], 'media_count' => $profile['media_count']],
        );
    }

    /**
     * Comparativa de la marca: la cuenta propia y cada competidor con ultimo valor y crecimiento.
     *
     * @return array{own: ?array<string, mixed>, competitors: list<array<string, mixed>>}
     */
    public function comparison(User $user, Brand $brand): array
    {
        $competitors = Competitor::withoutUserScope()->where('user_id', $user->id)->where('brand_id', $brand->id)->orderBy('username')->get();
        $snapshots = CompetitorSnapshot::withoutUserScope()->where('user_id', $user->id)
            ->whereIn('competitor_id', $competitors->pluck('id'))->where('date', '>=', now('UTC')->subDays(31)->toDateString())
            ->orderBy('date')->get()->groupBy('competitor_id');

        $rows = $competitors->map(function (Competitor $c) use ($snapshots) {
            $series = ($snapshots[$c->id] ?? collect())->map(fn ($s) => ['date' => $s->date instanceof \DateTimeInterface ? $s->date->format('Y-m-d') : substr((string) $s->date, 0, 10), 'followers' => $s->followers_count, 'media' => $s->media_count])->values();

            return ['id' => $c->id, 'username' => $c->username, 'name' => $c->name, 'picture' => $c->profile_picture_url, 'last_synced_at' => $c->last_synced_at?->toIso8601String(), 'error' => $c->last_error] + $this->growth($series);
        })->values()->all();

        $own = null;
        $account = $this->queryAccount($user->id, $brand->id);
        if ($account) {
            $series = AccountSnapshot::withoutUserScope()->where('user_id', $user->id)->where('social_account_id', $account->id)
                ->where('date', '>=', now('UTC')->subDays(31)->toDateString())->orderBy('date')->get()
                ->map(fn ($s) => ['date' => substr((string) ($s->date instanceof \DateTimeInterface ? $s->date->format('Y-m-d') : $s->date), 0, 10), 'followers' => $s->metrics['followers'] ?? null, 'media' => $s->metrics['video_count'] ?? null])->values();
            $own = ['username' => $account->username, 'name' => $account->name] + $this->growth($series);
        }

        return ['own' => $own, 'competitors' => $rows];
    }

    /**
     * @param  Collection<int, array{date: string, followers: ?int, media: ?int}>  $series
     * @return array{followers: ?int, media: ?int, growth_7d: ?int, growth_30d: ?int, series: list<array<string, mixed>>}
     */
    private function growth(Collection $series): array
    {
        $withFollowers = $series->filter(fn ($p) => $p['followers'] !== null)->values();
        $last = $withFollowers->last();
        $diff = function (int $days) use ($withFollowers, $last): ?int {
            if (! $last) {
                return null;
            }
            $from = now('UTC')->subDays($days)->toDateString();
            $base = $withFollowers->first(fn ($p) => $p['date'] >= $from && $p['date'] < $last['date']);

            return $base ? $last['followers'] - $base['followers'] : null;
        };

        return [
            'followers' => $last['followers'] ?? null,
            'media' => $series->last()['media'] ?? null,
            'growth_7d' => $diff(7),
            'growth_30d' => $diff(30),
            'series' => $series->all(),
        ];
    }
}
