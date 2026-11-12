<?php

namespace App\Services;

use App\Connectors\CompetitorSources;
use App\Connectors\Contracts\CompetitorSource;
use App\Connectors\Exceptions\ConnectorException;
use App\Connectors\Exceptions\QuotaExceededException;
use App\Connectors\Exceptions\ReconnectRequiredException;
use App\Connectors\Exceptions\TransientPublishException;
use App\Connectors\Meta\CompetitorNotFound;
use App\Connectors\Network;
use App\Exceptions\PostValidationException;
use App\Models\AccountSnapshot;
use App\Models\Brand;
use App\Models\Competitor;
use App\Models\CompetitorPost;
use App\Models\CompetitorSnapshot;
use App\Models\SocialAccount;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\RateLimiter;
use InvalidArgumentException;

/**
 * Competidores (PA-08, T-25): alta con comprobacion inmediata en la fuente publica de la red (Instagram: Business Discovery; Bluesky:
 * AppView publico; YouTube: Data API con cuota), snapshot diario y comparativa con la cuenta propia de esa red (seguidores,
 * publicaciones y crecimiento a 7 y 30 dias). Una fuente por red detras de `CompetitorSource` (ver `CompetitorSources`).
 */
class CompetitorService
{
    public function __construct(private readonly CompetitorSources $sources, private readonly SocialAccountService $accounts) {}

    public function source(Network $network): CompetitorSource
    {
        return $this->sources->for($network);
    }

    /** @return list<Network> Redes en las que se pueden seguir competidores en esta instalacion. */
    public function networks(): array
    {
        return $this->sources->networks();
    }

    /** Cuenta propia activa de la marca en esa red desde la que se consulta (la mas antigua; Instagram por defecto). */
    public function queryAccount(int $userId, int $brandId, ?int $preferredId = null, Network $network = Network::Instagram): ?SocialAccount
    {
        $query = SocialAccount::withoutUserScope()->where('user_id', $userId)->where('brand_id', $brandId)
            ->where('network', $network->value)->where('status', SocialAccount::STATUS_ACTIVE);

        if ($preferredId !== null && ($preferred = (clone $query)->find($preferredId))) {
            return $preferred;
        }

        return $query->orderBy('id')->first();
    }

    /** Cuenta propia con la que se consulta a este competidor, o null si su red no la necesita (Bluesky) o no hay ninguna activa. */
    public function accountFor(Competitor $competitor): ?SocialAccount
    {
        return $this->source($competitor->network)->needsAccount()
            ? $this->queryAccount($competitor->user_id, $competitor->brand_id, $competitor->social_account_id, $competitor->network)
            : null;
    }

    /**
     * Perfil publico actual del competidor (la fuente de su red).
     *
     * @return array<string, mixed>
     */
    public function fetchProfile(Competitor $competitor, ?SocialAccount $account): array
    {
        return $this->source($competitor->network)->lookup($account, $competitor->external_id ?: $competitor->username);
    }

    public function add(User $user, Brand $brand, string $username, Network $network = Network::Instagram): Competitor
    {
        if ($brand->user_id !== $user->id) {
            throw new PostValidationException(['brand' => ['La marca no pertenece al usuario.']]);
        }
        if (! $this->sources->has($network)) {
            throw new PostValidationException(['network' => ["{$network->label()} no admite competidores en esta instalacion."]]);
        }

        $source = $this->source($network);
        $label = $network->label();
        try {
            $username = $source->normalize($username);
        } catch (InvalidArgumentException $e) {
            throw new PostValidationException(['username' => [$e->getMessage()]]);
        }

        $query = Competitor::withoutUserScope()->where('user_id', $user->id)->where('brand_id', $brand->id);
        if ((clone $query)->where('network', $network->value)->where('username', $username)->exists()) {
            throw new PostValidationException(['username' => ['Ya sigues a esa cuenta.']]);
        }
        if ((clone $query)->count() >= (int) config('social.competitors.max_per_brand', 10)) {
            throw new PostValidationException(['username' => ['Has alcanzado el maximo de competidores para esta marca.']]);
        }

        $account = $source->needsAccount() ? $this->queryAccount($user->id, $brand->id, null, $network) : null;
        if ($source->needsAccount() && ! $account) {
            throw new PostValidationException(['username' => ["Conecta una cuenta de $label activa en esta marca para comparar."]]);
        }

        // Una cuenta propia de la marca en esa red no es un competidor.
        $own = SocialAccount::withoutUserScope()->where('user_id', $user->id)->where('brand_id', $brand->id)->where('network', $network->value)->pluck('username')
            ->map(fn ($u) => ltrim(mb_strtolower((string) $u), '@'));
        if ($own->contains($username)) {
            throw new PostValidationException(['username' => ['Esa es tu propia cuenta.']]);
        }

        try {
            $profile = $source->lookup($account, $username);
        } catch (CompetitorNotFound $e) {
            throw new PostValidationException(['username' => [$e->getMessage()]]);
        } catch (ReconnectRequiredException $e) {
            if ($account) {
                $this->accounts->flagReconnection($account, $e->getMessage());
            }
            throw new PostValidationException(['username' => ["Tu cuenta de $label necesita reconectarse."]]);
        } catch (QuotaExceededException) {
            throw new PostValidationException(['username' => ["La cuota diaria de $label para competidores esta agotada. Se libera pronto; intentalo mas tarde."]]);
        } catch (TransientPublishException) {
            throw new PostValidationException(['username' => ["$label no responde ahora mismo. Intentalo de nuevo en unos minutos."]]);
        } catch (ConnectorException) {
            throw new PostValidationException(['username' => ["$label rechazo la consulta. Revisa los permisos de la cuenta conectada."]]);
        }

        // El perfil trae el usuario canonico (un canal de YouTube o un handle de Bluesky pueden haberse escrito de otra forma).
        $canonical = mb_strtolower($profile['username']);
        if ($canonical !== $username && (clone $query)->where('network', $network->value)->where('username', $canonical)->exists()) {
            throw new PostValidationException(['username' => ['Ya sigues a esa cuenta.']]);
        }

        $competitor = new Competitor([
            'network' => $network,
            'username' => $canonical,
            'external_id' => $profile['external_id'] ?? null,
            'name' => $profile['name'],
            'profile_picture_url' => $profile['profile_picture_url'],
            'social_account_id' => $account?->id,
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
            'external_id' => $profile['external_id'] ?? $competitor->external_id,
            'last_synced_at' => now(),
            'last_error' => null,
        ])->save();

        return CompetitorSnapshot::withoutUserScope()->updateOrCreate(
            ['competitor_id' => $competitor->id, 'date' => now('UTC')->toDateString()],
            ['user_id' => $competitor->user_id, 'followers_count' => $profile['followers_count'], 'media_count' => $profile['media_count']],
        );
    }

    /** Metricas por las que se ordena el ranking. `avg_interactions` sale de las publicaciones recogidas (likes + comentarios visibles). */
    public const SORTS = ['followers', 'growth', 'growth_pct', 'media', 'avg_interactions'];

    /**
     * Rango de fechas validado (UTC, dia completo). Por defecto los ultimos 30 dias; maximo 366 y nunca en el futuro.
     *
     * @return array{0: string, 1: string}
     */
    public function range(?string $from, ?string $to): array
    {
        $today = now('UTC')->toDateString();
        $valid = fn (?string $d) => $d !== null && preg_match('/^\d{4}-\d{2}-\d{2}$/', $d) === 1 && checkdate((int) substr($d, 5, 2), (int) substr($d, 8, 2), (int) substr($d, 0, 4));
        $to = $valid($to) ? min($to, $today) : $today;
        $from = $valid($from) ? $from : Carbon::parse($to)->subDays(29)->toDateString();
        if ($from > $to) {
            throw new PostValidationException(['from' => ['La fecha inicial no puede ser posterior a la final.']]);
        }
        if (Carbon::parse($from)->diffInDays(Carbon::parse($to)) > 365) {
            throw new PostValidationException(['from' => ['El rango máximo es de un año.']]);
        }

        return [$from, $to];
    }

    /** Lee las ultimas publicaciones publicas del competidor y las guarda (upsert por id). Devuelve cuantas procesó. */
    public function refreshPosts(Competitor $competitor): int
    {
        $source = $this->source($competitor->network);
        $account = $this->accountFor($competitor);
        if ($source->needsAccount() && ! $account) {
            throw new PostValidationException(['username' => ["Conecta una cuenta de {$competitor->network->label()} activa en esta marca para comparar."]]);
        }

        $rows = $source->media($account, $competitor->username, $competitor->external_id, (int) config('social.competitors.posts_limit', 25));
        foreach ($rows as $r) {
            $post = CompetitorPost::withoutUserScope()->firstOrNew(['competitor_id' => $competitor->id, 'external_id' => $r['external_id']]);
            $post->fill($r);
            $post->user_id = $competitor->user_id;
            $post->competitor_id = $competitor->id;
            $post->save();
        }

        return count($rows);
    }

    /** Actualiza con tope de uso (una vez cada 10 minutos por competidor) y traduce los errores de la red a mensajes legibles. */
    public function refreshPostsFor(User $user, Competitor $competitor): int
    {
        if ($competitor->user_id !== $user->id) {
            throw new PostValidationException(['competitor' => ['El competidor no pertenece al usuario.']]);
        }

        // Topes (Graph API: 200 llamadas/h por app): un competidor cada 10 minutos y un maximo por hora y usuario.
        $cooldown = (int) config('social.competitors.refresh_cooldown_minutes', 10);
        if (! Cache::add('competitor-refresh:'.$competitor->id, 1, now()->addMinutes($cooldown))) {
            throw new PostValidationException(['username' => ["Ya actualizaste a @{$competitor->username} hace poco: espera unos minutos (una vez cada $cooldown minutos)."]]);
        }
        $perHour = max(1, (int) config('social.competitors.refresh_per_hour', 60));
        if (! RateLimiter::attempt('competitor-refresh-hour:'.$user->id, $perHour, fn () => true, 3600)) {
            Cache::forget('competitor-refresh:'.$competitor->id);
            throw new PostValidationException(['username' => ["Has alcanzado el máximo de $perHour actualizaciones por hora. Inténtalo más tarde."]]);
        }

        try {
            return $this->refreshPosts($competitor);
        } catch (CompetitorNotFound $e) {
            throw new PostValidationException(['username' => [$e->getMessage()]]);
        } catch (ReconnectRequiredException $e) {
            throw new PostValidationException(['username' => ["Tu cuenta de {$competitor->network->label()} necesita reconectarse."]]);
        } catch (QuotaExceededException) {
            throw new PostValidationException(['username' => ["La cuota diaria de {$competitor->network->label()} para competidores esta agotada. Se libera pronto; intentalo mas tarde."]]);
        } catch (TransientPublishException) {
            throw new PostValidationException(['username' => ["{$competitor->network->label()} no responde ahora mismo. Intentalo de nuevo en unos minutos."]]);
        } catch (ConnectorException) {
            throw new PostValidationException(['username' => ["{$competitor->network->label()} rechazo la consulta. Revisa los permisos de la cuenta conectada."]]);
        }
    }

    /**
     * Top publicaciones de un competidor en el rango, por interacciones visibles (las que no muestran ninguna van al final).
     *
     * @return list<array<string, mixed>>
     */
    public function topPosts(User $user, Competitor $competitor, ?string $from = null, ?string $to = null, int $limit = 5): array
    {
        [$from, $to] = $this->range($from, $to);

        return CompetitorPost::withoutUserScope()->where('user_id', $user->id)->where('competitor_id', $competitor->id)
            ->whereBetween('posted_at', [$from.' 00:00:00', $to.' 23:59:59'])->get()
            ->sortByDesc(fn (CompetitorPost $p) => $p->interactions() ?? -1)->take(max(1, min(25, $limit)))
            ->map(fn (CompetitorPost $p) => [
                'id' => $p->id, 'caption' => $p->caption, 'media_type' => $p->media_type, 'permalink' => $p->permalink,
                'likes' => $p->likes_count, 'comments' => $p->comments_count, 'interactions' => $p->interactions(),
                'posted_at' => $p->posted_at?->toIso8601String(),
            ])->values()->all();
    }

    /**
     * Comparativa de la marca en un rango: la cuenta propia y cada competidor con seguidores al inicio y al final del rango,
     * crecimiento (absoluto y %), publicaciones, interacciones medias de lo recogido y su posicion en el ranking.
     * Solo usa datos recogidos: sin snapshot en el rango el valor es null (no 0).
     *
     * @return array{own: ?array<string, mixed>, competitors: list<array<string, mixed>>, range: array{from: string, to: string}, sort: string, network: string}
     */
    public function comparison(User $user, Brand $brand, ?string $from = null, ?string $to = null, string $sort = 'followers', Network $network = Network::Instagram): array
    {
        [$from, $to] = $this->range($from, $to);
        $sort = in_array($sort, self::SORTS, true) ? $sort : 'followers';
        $since = min($from, now('UTC')->subDays(31)->toDateString());

        $competitors = Competitor::withoutUserScope()->where('user_id', $user->id)->where('brand_id', $brand->id)->where('network', $network->value)->orderBy('username')->get();
        $snapshots = CompetitorSnapshot::withoutUserScope()->where('user_id', $user->id)
            ->whereIn('competitor_id', $competitors->pluck('id'))->where('date', '>=', $since)->where('date', '<=', $to)
            ->orderBy('date')->get()->groupBy('competitor_id');
        $posts = CompetitorPost::withoutUserScope()->where('user_id', $user->id)->whereIn('competitor_id', $competitors->pluck('id'))
            ->whereBetween('posted_at', [$from.' 00:00:00', $to.' 23:59:59'])->get()->groupBy('competitor_id');

        $rows = $competitors->map(function (Competitor $c) use ($snapshots, $posts, $from, $to) {
            $series = ($snapshots[$c->id] ?? collect())->map(fn ($s) => ['date' => $s->date instanceof \DateTimeInterface ? $s->date->format('Y-m-d') : substr((string) $s->date, 0, 10), 'followers' => $s->followers_count, 'media' => $s->media_count])->values();
            $mine = $posts[$c->id] ?? collect();
            $visible = $mine->map->interactions()->filter(fn ($v) => $v !== null);

            return ['id' => $c->id, 'username' => $c->username, 'name' => $c->name, 'picture' => $c->profile_picture_url, 'last_synced_at' => $c->last_synced_at?->toIso8601String(), 'error' => $c->last_error]
                + $this->growth($series) + $this->rangeStats($series, $from, $to)
                + ['posts_collected' => $mine->count(), 'avg_interactions' => $visible->isEmpty() ? null : (int) round($visible->avg())];
        })->values()->all();

        $own = null;
        $account = $this->queryAccount($user->id, $brand->id, null, $network);
        if ($account) {
            $series = AccountSnapshot::withoutUserScope()->where('user_id', $user->id)->where('social_account_id', $account->id)
                ->where('date', '>=', $since)->where('date', '<=', $to)->orderBy('date')->get()
                ->map(fn ($s) => ['date' => substr((string) ($s->date instanceof \DateTimeInterface ? $s->date->format('Y-m-d') : $s->date), 0, 10), 'followers' => $s->metrics['followers'] ?? null, 'media' => $s->metrics['video_count'] ?? $s->metrics['post_count'] ?? null])->values();
            $own = ['username' => $account->username, 'name' => $account->name, 'posts_collected' => 0, 'avg_interactions' => null] + $this->growth($series) + $this->rangeStats($series, $from, $to);
        }

        // Ranking por la metrica elegida (mayor es mejor); los valores null (sin dato) quedan al final y sin posicion.
        $key = fn (array $r) => $r[$sort] ?? null;
        $ranked = collect($own ? [$own] : [])->merge($rows)->filter(fn ($r) => $key($r) !== null)->sortByDesc($key)->values();
        $position = $ranked->mapWithKeys(fn ($r, $i) => [$r['username'] => $i + 1])->all();
        $rows = collect($rows)->map(fn ($r) => $r + ['rank' => $position[$r['username']] ?? null])->sortBy(fn ($r) => $r['rank'] ?? PHP_INT_MAX)->values()->all();
        if ($own) {
            $own['rank'] = $position[$own['username']] ?? null;
        }

        return ['own' => $own, 'competitors' => $rows, 'range' => ['from' => $from, 'to' => $to], 'sort' => $sort, 'network' => $network->value];
    }

    /**
     * Valores al inicio y al final del rango (primer y ultimo snapshot dentro de el) y su variacion.
     *
     * @param  Collection<int, array{date: string, followers: ?int, media: ?int}>  $series
     * @return array{followers_start: ?int, followers_end: ?int, growth: ?int, growth_pct: ?float, media_growth: ?int}
     */
    private function rangeStats(Collection $series, string $from, string $to): array
    {
        $inRange = $series->filter(fn ($p) => $p['date'] >= $from && $p['date'] <= $to && $p['followers'] !== null)->values();
        $first = $inRange->first();
        $last = $inRange->last();
        $enough = $first && $last && $first['date'] !== $last['date'];
        $growth = $enough ? $last['followers'] - $first['followers'] : null;
        $mediaRange = $series->filter(fn ($p) => $p['date'] >= $from && $p['date'] <= $to && $p['media'] !== null)->values();

        return [
            'followers_start' => $first['followers'] ?? null,
            'followers_end' => $last['followers'] ?? null,
            'growth' => $growth,
            'growth_pct' => $growth !== null && $first['followers'] > 0 ? round($growth / $first['followers'] * 100, 2) : null,
            'media_growth' => $mediaRange->count() > 1 ? $mediaRange->last()['media'] - $mediaRange->first()['media'] : null,
        ];
    }

    /**
     * Filas del CSV de la comparativa (cabecera incluida). Quien llama las escribe con `CsvWriter`.
     *
     * @return list<list<mixed>>
     */
    public function csvRows(array $comparison): array
    {
        $rows = [['Cuenta', 'Posición', 'Seguidores inicio', 'Seguidores fin', 'Crecimiento', 'Crecimiento %', 'Publicaciones', 'Interacciones medias', 'Desde', 'Hasta']];
        $all = array_merge($comparison['own'] ? [['own' => true] + $comparison['own']] : [], $comparison['competitors']);
        foreach ($all as $r) {
            $rows[] = [
                $r['username'].(! empty($r['own']) ? ' (tú)' : ''), $r['rank'] ?? 'no disponible', $r['followers_start'] ?? 'no disponible', $r['followers_end'] ?? 'no disponible',
                $r['growth'] ?? 'no disponible', $r['growth_pct'] ?? 'no disponible', $r['media'] ?? 'no disponible', $r['avg_interactions'] ?? 'no disponible',
                $comparison['range']['from'], $comparison['range']['to'],
            ];
        }

        return $rows;
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
