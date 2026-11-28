<?php

use App\Models\Brand;
use App\Models\Post;
use App\Models\User;
use App\Services\CalendarFeed;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Notification;
use Inertia\Testing\AssertableInertia as Assert;

// T-90 · F-18: feed .ics por usuario con token secreto (hash, regenerable, revocable); solo sus publicaciones.

beforeEach(function () {
    Carbon::setTestNow('2026-11-20 10:00:00');
    $this->user = User::factory()->create();
    $this->brand = Brand::factory()->for($this->user)->create(['name' => 'Mi Marca']);
});

/** Programa un post del usuario (sin pasar por PostService: solo importa el dato). */
function feedPost(User $user, Brand $brand, ?string $utc, string $text = 'Hola', string $status = 'programada'): Post
{
    $post = new Post(['text' => $text, 'scheduled_at' => $utc ? Carbon::parse($utc, 'UTC') : null, 'status' => $status]);
    $post->user_id = $user->id;
    $post->brand_id = $brand->id;
    $post->save();

    return $post;
}

/** Genera el token por la ruta de la aplicación y devuelve la URL pública que ve el usuario. */
function feedUrl(User $user): string
{
    test()->actingAs($user)->post(route('calendar.feed.store'))->assertRedirect()->assertSessionHas('calendar_feed_url');

    return Crypt::decryptString(session('calendar_feed_url'));
}

/** Desdobla las líneas plegadas (RFC 5545 §3.1) y devuelve las líneas lógicas. */
function icsLines(string $body): array
{
    return explode("\r\n", rtrim(preg_replace('/\r\n[ \t]/', '', $body), "\r\n"));
}

it('genera una URL secreta con un token de alta entropía y guarda solo su hash', function () {
    $url = feedUrl($this->user);

    expect($url)->toMatch('#/calendar/feed/[A-Za-z0-9_-]{43}\.ics$#');
    $token = basename(parse_url($url, PHP_URL_PATH), '.ics');
    $stored = $this->user->fresh()->calendar_feed_token_hash;
    expect($stored)->toBe(hash('sha256', $token))->and($stored)->not->toContain($token);
});

it('el feed es un iCalendar válido: cabeceras, CRLF, UTC, UID estable y escapado', function () {
    $post = feedPost($this->user, $this->brand, '2026-11-24 08:30:00', "Oferta, 20% dto; ¡ya!\\ y salto\nde línea");
    $url = feedUrl($this->user);

    $res = $this->get($url)->assertOk();
    $res->assertHeader('Content-Type', 'text/calendar; charset=utf-8');
    expect($res->headers->get('Cache-Control'))->toContain('no-store')
        ->and($res->headers->get('X-Robots-Tag'))->toContain('noindex');
    $body = $res->getContent();
    expect($body)->toContain("\r\n")->and(preg_match('/(?<!\r)\n/', $body))->toBe(0);

    $lines = icsLines($body);
    expect($lines[0])->toBe('BEGIN:VCALENDAR')->and(end($lines))->toBe('END:VCALENDAR')
        ->and($lines)->toContain('VERSION:2.0')->toContain('BEGIN:VEVENT')->toContain('END:VEVENT')
        ->and(collect($lines)->contains(fn ($l) => str_starts_with($l, 'PRODID:')))->toBeTrue()
        ->and($lines)->toContain('DTSTART:20261124T083000Z')
        ->and(collect($lines)->contains(fn ($l) => preg_match('/^DTSTAMP:\d{8}T\d{6}Z$/', $l) === 1))->toBeTrue();
    $uid = collect($lines)->first(fn ($l) => str_starts_with($l, 'UID:'));
    expect($uid)->toContain((string) $post->id);
    $summary = collect($lines)->first(fn ($l) => str_starts_with($l, 'SUMMARY:'));
    $description = collect($lines)->first(fn ($l) => str_starts_with($l, 'DESCRIPTION:'));
    expect($summary)->toContain('Oferta\\, 20% dto\\; ¡ya!\\\\ y salto de línea')
        ->and($description)->toContain('y salto\\nde línea');

    // El UID es estable entre peticiones.
    $again = icsLines($this->get($url)->getContent());
    expect(collect($again)->first(fn ($l) => str_starts_with($l, 'UID:')))->toBe($uid);
});

it('pliega las líneas a 75 octetos sin partir caracteres UTF-8', function () {
    feedPost($this->user, $this->brand, '2026-11-24 08:30:00', str_repeat('ñandú ✓ 🎉 ', 40));
    $body = $this->get(feedUrl($this->user))->assertOk()->getContent();

    foreach (explode("\r\n", $body) as $line) {
        expect(strlen($line))->toBeLessThanOrEqual(75)->and(mb_check_encoding($line, 'UTF-8'))->toBeTrue();
    }
    expect($body)->toContain("\r\n ");
});

it('solo incluye las publicaciones del usuario, no las de otros ni los borradores sin fecha', function () {
    feedPost($this->user, $this->brand, '2026-11-24 08:30:00', 'Mío visible');
    feedPost($this->user, $this->brand, null, 'Borrador sin fecha', 'borrador');
    $other = User::factory()->create();
    $otherBrand = Brand::factory()->for($other)->create(['name' => 'Marca Ajena']);
    feedPost($other, $otherBrand, '2026-11-24 09:30:00', 'Secreto ajeno');

    $body = $this->get(feedUrl($this->user))->assertOk()->getContent();

    expect($body)->toContain('Mío visible')->not->toContain('Borrador sin fecha')->not->toContain('Secreto ajeno')->not->toContain('Marca Ajena');
});

it('resume el texto largo en vez de volcarlo entero', function () {
    feedPost($this->user, $this->brand, '2026-11-24 08:30:00', str_repeat('palabra ', 200));
    // Se mide cada propiedad desplegada, no el tamano total del feed (depende del entorno: URL de la app, fechas señaladas).
    $lines = icsLines($this->get(feedUrl($this->user))->assertOk()->getContent());
    $value = fn (string $name) => (string) substr((string) collect($lines)->first(fn ($l) => str_starts_with($l, $name.':')), strlen($name) + 1);

    expect(implode('', $lines))->not->toContain(str_repeat('palabra ', 60))
        ->and(mb_strlen($value('SUMMARY')))->toBeLessThanOrEqual(120)
        ->and(mb_strlen($value('DESCRIPTION')))->toBeLessThanOrEqual(420);
});

it('regenerar el token invalida el anterior', function () {
    feedPost($this->user, $this->brand, '2026-11-24 08:30:00');
    $old = feedUrl($this->user);
    $this->get($old)->assertOk();

    $new = feedUrl($this->user);

    expect($new)->not->toBe($old);
    $this->get($old)->assertNotFound();
    $this->get($new)->assertOk();
});

it('revocar deja el feed sin servicio', function () {
    $url = feedUrl($this->user);

    $this->actingAs($this->user)->delete(route('calendar.feed.destroy'))->assertRedirect();

    expect($this->user->fresh()->calendar_feed_token_hash)->toBeNull();
    $this->get($url)->assertNotFound();
});

it('un token inválido, mal formado o ausente da 404 sin pista', function () {
    feedUrl($this->user);
    $good = 'A'.str_repeat('b', 42);

    $this->get("/calendar/feed/{$good}.ics")->assertNotFound();
    $this->get('/calendar/feed/corto.ics')->assertNotFound();
    $this->get('/calendar/feed/'.str_repeat('é', 43).'.ics')->assertNotFound();
    expect(app(CalendarFeed::class)->userFor(''))->toBeNull();
});

it('la ruta del feed es pública: sin cookies y con throttle', function () {
    $url = feedUrl($this->user);
    auth()->logout();

    $res = $this->get($url)->assertOk();
    expect($res->headers->getCookies())->toBe([])
        ->and($res->headers->get('X-RateLimit-Limit'))->not->toBeNull();
});

it('un usuario solo regenera su propio token: el de otro no cambia', function () {
    $other = User::factory()->create();
    $otherUrl = feedUrl($other);
    $before = $other->fresh()->calendar_feed_token_hash;

    feedUrl($this->user);

    expect($other->fresh()->calendar_feed_token_hash)->toBe($before);
    $this->get($otherUrl)->assertOk();
    auth()->logout();
    $this->post(route('calendar.feed.store'))->assertRedirect(route('login'));
});

it('incluye las fechas señaladas como eventos de día completo y las omite si están ocultas', function () {
    $url = feedUrl($this->user);

    $with = $this->get($url)->assertOk()->getContent();
    expect($with)->toContain('SUMMARY:Black Friday')->toContain('DTSTART;VALUE=DATE:20261127')->toContain('DTEND;VALUE=DATE:20261128');

    $this->actingAs($this->user)->patch(route('calendar.marketing-dates.update'), ['hidden' => true]);
    $without = $this->get($url)->assertOk()->getContent();
    expect($without)->not->toContain('Black Friday');
});

it('el calendario informa de si hay feed activo y entrega la URL una sola vez', function () {
    $this->actingAs($this->user)->get(route('calendar.index'))
        ->assertInertia(fn (Assert $page) => $page->where('feed.active', false)->where('feed.url', null));

    $this->actingAs($this->user)->post(route('calendar.feed.store'));
    $this->actingAs($this->user)->get(route('calendar.index'))
        ->assertInertia(fn (Assert $page) => $page->where('feed.active', true)->where('feed.url', fn ($url) => str_contains($url, '/calendar/feed/')));
    $this->actingAs($this->user)->get(route('calendar.index'))
        ->assertInertia(fn (Assert $page) => $page->where('feed.active', true)->where('feed.url', null));
});

it('con más publicaciones que el tope prioriza lo próximo y usa el cupo restante para el pasado reciente', function () {
    config(['social.calendar_feed.max_posts' => 3]);
    feedPost($this->user, $this->brand, '2026-11-01 08:00:00', 'pasado antiguo');
    feedPost($this->user, $this->brand, '2026-11-18 08:00:00', 'pasado reciente');
    feedPost($this->user, $this->brand, '2026-11-21 08:00:00', 'futuro uno');
    feedPost($this->user, $this->brand, '2026-11-22 08:00:00', 'futuro dos');
    feedPost($this->user, $this->brand, '2027-06-01 08:00:00', 'futuro lejano');

    $body = $this->get(feedUrl($this->user))->assertOk()->getContent();

    expect($body)->toContain('futuro uno')->toContain('futuro dos')->toContain('futuro lejano')
        ->not->toContain('pasado reciente')->not->toContain('pasado antiguo');

    config(['social.calendar_feed.max_posts' => 4]);
    $body = $this->get(feedUrl($this->user))->getContent();
    expect($body)->toContain('pasado reciente')->not->toContain('pasado antiguo');
});

it('restablecer o cambiar la contraseña revoca el enlace del feed', function () {
    $url = feedUrl($this->user);
    $this->actingAs($this->user)->from('/profile')->put('/password', ['current_password' => 'password', 'password' => 'new-password', 'password_confirmation' => 'new-password'])->assertSessionHasNoErrors();
    expect($this->user->fresh()->calendar_feed_token_hash)->toBeNull();
    $this->get($url)->assertNotFound();

    Notification::fake();
    $url = feedUrl($this->user);
    auth()->logout();
    $this->post('/forgot-password', ['email' => $this->user->email]);
    Notification::assertSentTo($this->user, ResetPassword::class, function ($notification) {
        $this->post('/reset-password', ['token' => $notification->token, 'email' => $this->user->email, 'password' => 'otra-clave-1', 'password_confirmation' => 'otra-clave-1'])->assertSessionHasNoErrors();

        return true;
    });
    expect($this->user->fresh()->calendar_feed_token_hash)->toBeNull();
    $this->get($url)->assertNotFound();
});

it('cifra el historial de Inertia solo cuando la respuesta lleva la URL secreta', function () {
    $without = $this->actingAs($this->user)->get(route('calendar.index'))->getOriginalContent()->getData()['page'];
    $this->actingAs($this->user)->post(route('calendar.feed.store'));
    $with = $this->actingAs($this->user)->get(route('calendar.index'))->getOriginalContent()->getData()['page'];

    expect($with['encryptHistory'] ?? false)->toBeTrue()->and($without['encryptHistory'] ?? false)->toBeFalse();
});

it('el feed tiene su propio limitador por token, distinto del de SmartLinks', function () {
    $url = feedUrl($this->user);
    auth()->logout();

    expect($this->get($url)->headers->get('X-RateLimit-Limit'))->toBe((string) config('rate_limits.calendar_feed.per_token'));
});
