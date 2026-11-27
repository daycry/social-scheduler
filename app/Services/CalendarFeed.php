<?php

namespace App\Services;

use App\Enums\PostStatus;
use App\Models\Post;
use App\Models\User;
use App\Support\IcsWriter;
use App\Support\MarketingDates;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;

/**
 * Feed .ics del calendario (T-90). La URL secreta lleva un token aleatorio de 256 bits; la base de datos guarda solo su SHA-256
 * (como un API token), así que el token solo se ve al generarlo y regenerarlo invalida el anterior. El feed contiene únicamente las
 * publicaciones PROPIAS con fecha y en estado programada, en curso o publicada (ni borradores ni fallidas) de 30 días atrás a un año
 * adelante, y las fechas señaladas de ese tramo salvo que el usuario las haya ocultado. Cada evento resume la publicación: marca,
 * redes, estado y un extracto del texto; nunca credenciales ni enlaces internos. Con más publicaciones que `social.calendar_feed.max_posts`
 * se prioriza lo próximo: primero las de ahora en adelante (de la más cercana a la más lejana) y el pasado reciente usa el cupo restante.
 */
class CalendarFeed
{
    /** Formato del token en la URL: 32 bytes en base64 URL-safe sin relleno. */
    public const TOKEN_PATTERN = '[A-Za-z0-9_-]{43}';

    private const PAST_DAYS = 30;

    private const FUTURE_DAYS = 365;

    private const EVENT_MINUTES = 30;

    public function __construct(private readonly CalendarService $calendar) {}

    /** Genera (o regenera) el token del usuario y devuelve la URL pública completa; el token anterior deja de valer. */
    public function issue(User $user): string
    {
        $token = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
        $user->forceFill(['calendar_feed_token_hash' => hash('sha256', $token), 'calendar_feed_token_at' => now()])->save();

        return route('calendar.feed', ['token' => $token]);
    }

    public function revoke(User $user): void
    {
        $user->forceFill(['calendar_feed_token_hash' => null, 'calendar_feed_token_at' => null])->save();
    }

    /** Dueño del token, o nulo si no existe, está revocado o no tiene el formato esperado. */
    public function userFor(string $token): ?User
    {
        if (preg_match('/\A'.self::TOKEN_PATTERN.'\z/', $token) !== 1) {
            return null;
        }

        $hash = hash('sha256', $token);
        $user = User::query()->where('calendar_feed_token_hash', $hash)->first();

        return $user !== null && hash_equals((string) $user->calendar_feed_token_hash, $hash) ? $user : null;
    }

    public function render(User $user): string
    {
        $now = CarbonImmutable::now('UTC');
        $host = (string) (parse_url((string) config('app.url'), PHP_URL_HOST) ?: 'localhost');
        $ics = (new IcsWriter)
            ->begin('VCALENDAR')
            ->property('VERSION', '2.0')
            ->property('PRODID', '-//'.config('app.name').'//Calendario//ES')
            ->property('CALSCALE', 'GREGORIAN')
            ->text('X-WR-CALNAME', config('app.name').' · Calendario');

        // Tope (`social.calendar_feed.max_posts`): primero lo PROXIMO (de ahora en adelante, de más cercano a más lejano) y el pasado reciente
        // (de más reciente a más antiguo) usa el cupo que quede; así lo que se recorta es lo más lejano, nunca lo que está a punto de publicarse.
        $max = max(1, (int) config('social.calendar_feed.max_posts', 1000));
        $upcoming = $this->posts($user, $now, $now->addDays(self::FUTURE_DAYS))->orderBy('scheduled_at')->orderBy('id')->limit($max)->get();
        $recent = $upcoming->count() >= $max
            ? collect()
            : $this->posts($user, $now->subDays(self::PAST_DAYS), $now->subSecond())->orderByDesc('scheduled_at')->orderByDesc('id')->limit($max - $upcoming->count())->get();
        $posts = $recent->concat($upcoming)->sortBy([['scheduled_at', 'asc'], ['id', 'asc']]);

        foreach ($posts as $post) {
            $this->postEvent($ics, $post, $host);
        }

        if (! $user->hide_marketing_dates) {
            foreach (MarketingDates::between($now->subDays(self::PAST_DAYS)->format('Y-m-d'), $now->addDays(self::FUTURE_DAYS)->format('Y-m-d')) as $date) {
                $day = CarbonImmutable::parse($date['date'], 'UTC');
                $ics->begin('VEVENT')
                    ->text('UID', "marketing-{$date['key']}-{$date['date']}@{$host}")
                    ->property('DTSTAMP', $now->format('Ymd\THis\Z'))
                    ->property('DTSTART', $day->format('Ymd'), ';VALUE=DATE')
                    ->property('DTEND', $day->addDay()->format('Ymd'), ';VALUE=DATE')
                    ->text('SUMMARY', $date['name'])
                    ->property('TRANSP', 'TRANSPARENT')
                    ->end('VEVENT');
            }
        }

        return $ics->end('VCALENDAR')->render();
    }

    /** @return Builder<Post> */
    private function posts(User $user, CarbonImmutable $from, CarbonImmutable $to)
    {
        return Post::ownedBy($user->id)
            ->whereIn('status', [PostStatus::Scheduled, PostStatus::Publishing, PostStatus::Processing, PostStatus::Published])
            ->whereBetween('scheduled_at', [$from, $to])
            ->with(['targets' => fn ($t) => $t->withoutGlobalScopes(), 'brand' => fn ($b) => $b->withoutGlobalScopes()]);
    }

    private function postEvent(IcsWriter $ics, Post $post, string $host): void
    {
        $start = CarbonImmutable::instance($post->scheduled_at)->utc();
        $text = trim($this->calendar->displayText($post));
        $oneLine = trim((string) preg_replace('/\s+/u', ' ', $text));
        $brand = (string) $post->brand?->name;
        $networks = $post->targets->map(fn ($t) => $t->network->label())->unique()->implode(', ');
        $details = array_filter([
            $brand !== '' ? "Marca: {$brand}" : null,
            $networks !== '' ? "Redes: {$networks}" : null,
            'Estado: '.$post->status->label(),
            '',
            mb_strimwidth($text, 0, 300, '…'),
        ], fn ($line) => $line !== null);

        $ics->begin('VEVENT')
            ->text('UID', "post-{$post->id}@{$host}")
            ->property('DTSTAMP', CarbonImmutable::instance($post->updated_at ?? $start)->utc()->format('Ymd\THis\Z'))
            ->property('DTSTART', $start->format('Ymd\THis\Z'))
            ->property('DTEND', $start->addMinutes(self::EVENT_MINUTES)->format('Ymd\THis\Z'))
            ->text('SUMMARY', ($brand !== '' ? $brand.': ' : '').($oneLine !== '' ? mb_strimwidth($oneLine, 0, 80, '…') : 'Publicación'))
            ->text('DESCRIPTION', implode("\n", $details))
            ->end('VEVENT');
    }
}
