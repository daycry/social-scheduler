<?php

namespace App\Services;

use App\Exceptions\PostValidationException;
use App\Models\ShortLink;
use App\Models\User;
use App\Support\DailyCounter;
use App\Support\SafeUrl;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

/**
 * Acortador propio (T-89): alta con codigo aleatorio unico y clics agregados por dia UTC (sin IP, agente, referer ni hash
 * por visita; no se deduplica: cada redireccion de un GET real cuenta). El dia es UTC en la base y en la interfaz.
 */
class ShortLinkService
{
    public const MAX_PER_USER = 500;

    /** Sin 0/O ni 1/I; el primer caracter es siempre una letra (ver `ShortLink::CODE_PATTERN`). */
    private const LETTERS = 'ABCDEFGHJKLMNPQRSTUVWXYZ';

    private const ALPHABET = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';

    private const ATTEMPTS = 8;

    /** Ventana (dias) del desglose diario que se envia a la interfaz. */
    public const DAILY_WINDOW_DAYS = 30;

    public function __construct(private readonly DailyCounter $counter) {}

    /** @return array<string, mixed> */
    public static function rules(): array
    {
        return [
            'url' => ['required', 'string', 'max:2000', fn ($attribute, $value, $fail) => SafeUrl::isSafe($value) ? null : $fail('Escribe una dirección que empiece por http:// o https://.')],
            'label' => ['nullable', 'string', 'max:100'],
        ];
    }

    /** @throws PostValidationException si el usuario ya tiene el maximo de enlaces o no hay codigo libre. */
    public function create(User $user, string $url, ?string $label): ShortLink
    {
        // El cerrojo sobre la fila del usuario serializa altas concurrentes: el tope no se supera.
        return DB::transaction(function () use ($user, $url, $label): ShortLink {
            User::query()->whereKey($user->id)->lockForUpdate()->value('id');
            if (ShortLink::ownedBy($user)->count() >= self::MAX_PER_USER) {
                throw new PostValidationException(['url' => ['Has llegado al máximo de '.self::MAX_PER_USER.' enlaces cortos. Borra alguno para crear otro.']]);
            }

            for ($i = 0; $i < self::ATTEMPTS; $i++) {
                try {
                    // Punto de guardado propio: una colision no invalida la transaccion externa.
                    return DB::transaction(fn () => ShortLink::query()->create(['user_id' => $user->id, 'code' => $this->randomCode(), 'url' => $url, 'label' => $label]));
                } catch (UniqueConstraintViolationException) {
                    // Colision de codigo: se prueba con otro.
                }
            }

            throw new PostValidationException(['url' => ['No se pudo generar un código único. Inténtalo de nuevo.']]);
        });
    }

    /** Enlace de un codigo (activo o no), o null si no existe; sin cargar al dueno. */
    public function find(string $code): ?ShortLink
    {
        return ShortLink::withoutUserScope()->where('code', $code)->first(['id', 'url', 'active']);
    }

    public function recordClick(ShortLink $link): void
    {
        $this->counter->increment('short_link_clicks', ['short_link_id' => $link->id, 'date' => now('UTC')->toDateString()]);
    }

    /**
     * Enlaces del usuario con sus clics: total, hoy, ultimos 7 dias y el desglose por dia de los ultimos 30 (mas reciente primero).
     *
     * @return list<array<string, mixed>>
     */
    public function listing(User $user, int $days = self::DAILY_WINDOW_DAYS): array
    {
        $links = ShortLink::ownedBy($user)->orderByDesc('id')->get();
        if ($links->isEmpty()) {
            return [];
        }

        $today = now('UTC')->toDateString();
        $from7 = now('UTC')->subDays(6)->toDateString();
        $from = now('UTC')->subDays($days - 1)->toDateString();
        $ids = $links->pluck('id');
        $totals = DB::table('short_link_clicks')->whereIn('short_link_id', $ids)->groupBy('short_link_id')->selectRaw('short_link_id, SUM(count) as total')->pluck('total', 'short_link_id');
        $recent = DB::table('short_link_clicks')->whereIn('short_link_id', $ids)->where('date', '>=', $from)->orderByDesc('date')->get(['short_link_id', 'date', 'count'])->groupBy('short_link_id');

        return $links->map(function (ShortLink $l) use ($totals, $recent, $today, $from7) {
            $rows = $recent->get($l->id, collect());

            return [
                'id' => $l->id,
                'code' => $l->code,
                'short_url' => $l->shortUrl(),
                'url' => $l->url,
                'label' => $l->label,
                'active' => $l->active,
                'total' => (int) ($totals[$l->id] ?? 0),
                'today' => (int) $rows->where('date', $today)->sum('count'),
                'last_7_days' => (int) $rows->where('date', '>=', $from7)->sum('count'),
                'daily' => $rows->map(fn ($r) => ['date' => (string) $r->date, 'clicks' => (int) $r->count])->values()->all(),
            ];
        })->values()->all();
    }

    protected function randomCode(): string
    {
        $code = self::LETTERS[random_int(0, strlen(self::LETTERS) - 1)];
        for ($i = 1; $i < 8; $i++) {
            $code .= self::ALPHABET[random_int(0, strlen(self::ALPHABET) - 1)];
        }

        return $code;
    }
}
