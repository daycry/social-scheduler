<?php

use App\Models\ShortLink;
use App\Models\User;
use App\Services\ShortLinkService;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    Carbon::setTestNow('2026-11-27 12:00:00');
    $this->user = User::factory()->create();
});

/** Crea un enlace corto por la ruta web y devuelve su modelo. */
function makeShort(User $user, array $over = []): ShortLink
{
    test()->actingAs($user)->postJson(route('shortlinks.store'), $over + ['url' => 'https://example.com/oferta?a=1', 'label' => 'Oferta'])->assertCreated();

    return ShortLink::ownedBy($user)->latest('id')->firstOrFail();
}

test('ShortLinkTest: crea un enlace corto y redirige con 302 sin guardarse en cache', function () {
    $link = makeShort($this->user);

    expect($link->code)->toMatch('/^[A-Z][A-Z0-9]{7}$/')->and($link->active)->toBeTrue();

    $this->get('/s/'.$link->code)
        ->assertStatus(302)
        ->assertRedirect('https://example.com/oferta?a=1')
        ->assertHeader('X-Robots-Tag', 'noindex, nofollow')
        ->assertHeader('Referrer-Policy', 'no-referrer');
    expect($this->get('/s/'.$link->code)->headers->get('Cache-Control'))->toContain('no-store');
});

test('ShortLinkTest: la respuesta de alta devuelve la URL corta completa', function () {
    $response = $this->actingAs($this->user)->postJson(route('shortlinks.store'), ['url' => 'https://example.com/a'])->assertCreated();

    expect($response->json('short_url'))->toStartWith(url('/s').'/')->and(strlen($response->json('short_url')))->toBe(strlen(url('/s').'/') + 8);
});

test('ShortLinkTest: tres visitas son tres clics en el agregado del dia, sin tabla de eventos', function () {
    $link = makeShort($this->user);

    foreach (range(1, 3) as $i) {
        $this->get('/s/'.$link->code)->assertRedirect();
    }

    $rows = DB::table('short_link_clicks')->where('short_link_id', $link->id)->get();
    expect($rows)->toHaveCount(1)->and($rows[0]->date)->toBe('2026-11-27')->and((int) $rows[0]->count)->toBe(3);
});

test('ShortLinkTest: al cambiar el dia (UTC) abre un agregado nuevo', function () {
    $link = makeShort($this->user);
    $this->get('/s/'.$link->code);
    $this->get('/s/'.$link->code);

    Carbon::setTestNow('2026-11-27 23:59:59');
    $this->get('/s/'.$link->code);
    Carbon::setTestNow('2026-11-28 00:00:01');
    $this->get('/s/'.$link->code);

    expect(DB::table('short_link_clicks')->where('short_link_id', $link->id)->orderBy('date')->pluck('count', 'date')->map(fn ($c) => (int) $c)->all())
        ->toBe(['2026-11-27' => 3, '2026-11-28' => 1]);
});

test('ShortLinkTest: HEAD y la precarga de navegadores no cuentan', function () {
    $link = makeShort($this->user);

    $this->call('HEAD', '/s/'.$link->code)->assertRedirect();
    $this->get('/s/'.$link->code, ['Sec-Purpose' => 'prefetch'])->assertRedirect();
    $this->get('/s/'.$link->code, ['Purpose' => 'prefetch'])->assertRedirect();

    expect(DB::table('short_link_clicks')->count())->toBe(0);
});

test('ShortLinkTest: desactivado no redirige (410 legible) y no cuenta; al reactivarlo vuelve a redirigir', function () {
    $link = makeShort($this->user);
    $this->get('/s/'.$link->code)->assertRedirect();

    $this->actingAs($this->user)->patch(route('shortlinks.update', $link), ['active' => false])->assertRedirect();
    expect($link->fresh()->active)->toBeFalse();

    $this->get('/s/'.$link->code)->assertStatus(410)->assertSee('Este enlace está desactivado')->assertDontSee('example.com');
    expect((int) DB::table('short_link_clicks')->where('short_link_id', $link->id)->sum('count'))->toBe(1);

    $this->actingAs($this->user)->patch(route('shortlinks.update', $link), ['active' => true])->assertRedirect();
    $this->get('/s/'.$link->code)->assertRedirect('https://example.com/oferta?a=1');
    expect((int) DB::table('short_link_clicks')->where('short_link_id', $link->id)->sum('count'))->toBe(2);
});

test('ShortLinkTest: un destino peligroso se rechaza y no se crea nada', function () {
    foreach (['javascript:alert(1)', 'JaVaScRiPt:alert(1)', 'data:text/html,<script>1</script>', '//evil.com', '/relativa', 'ftp://x.com/a', 'https://u:p@x.com', "java\tscript:alert(1)", 'https://', '', str_repeat('a', 2001)] as $bad) {
        $this->actingAs($this->user)->postJson(route('shortlinks.store'), ['url' => $bad])->assertUnprocessable()->assertJsonValidationErrors('url');
    }

    expect(ShortLink::ownedBy($this->user)->count())->toBe(0);
});

test('ShortLinkTest: un codigo inexistente o mal formado es 404', function () {
    $this->get('/s/ZZZZZZZZ')->assertNotFound();
    $this->get('/s/zzzzzzzz')->assertNotFound();
    $this->get('/s/AB')->assertNotFound();
});

test('ShortLinkTest: el codigo no choca con la ruta de los sitios publicados', function () {
    $routes = app('router')->getRoutes();

    expect($routes->match(Request::create('/s/ABCD2345'))->getName())->toBe('shortlinks.go')
        ->and($routes->match(Request::create('/s/abcd2345'))->getName())->toBe('sites.public')
        ->and($routes->match(Request::create('/s/12345678'))->getName())->toBe('sites.public');
});

test('ShortLinkTest: una colision de codigo se resuelve con otro codigo', function () {
    $taken = makeShort($this->user);
    $service = new class([$taken->code, $taken->code, 'NUEVO234']) extends ShortLinkService
    {
        public function __construct(private array $codes) {}

        protected function randomCode(): string
        {
            return array_shift($this->codes);
        }
    };

    $created = $service->create($this->user, 'https://example.com/otro', null);

    expect($created->code)->toBe('NUEVO234')->and(ShortLink::ownedBy($this->user)->count())->toBe(2);
});

test('ShortLinkTest: el codigo es unico a nivel de base de datos', function () {
    $link = makeShort($this->user);

    expect(fn () => DB::table('short_links')->insert(['user_id' => $this->user->id, 'code' => $link->code, 'url' => 'https://example.com/x', 'created_at' => now(), 'updated_at' => now()]))
        ->toThrow(UniqueConstraintViolationException::class);
});

test('ShortLinkTest: un usuario ajeno no puede ver, desactivar ni borrar el enlace', function () {
    $link = makeShort($this->user);
    $other = User::factory()->create();

    $this->actingAs($other)->patch(route('shortlinks.update', $link), ['active' => false])->assertNotFound();
    $this->actingAs($other)->delete(route('shortlinks.destroy', $link))->assertNotFound();
    expect($link->fresh()->active)->toBeTrue()->and(ShortLink::withoutUserScope()->count())->toBe(1);

    $this->actingAs($other)->get(route('smartlinks.index'))->assertInertia(fn (Assert $p) => $p->component('SmartLinks/Index')->has('short_links', 0));
});

test('ShortLinkTest: el listado da los clics totales, de hoy y por dia, solo de los enlaces propios', function () {
    $link = makeShort($this->user);
    makeShort($this->user, ['url' => 'https://example.com/b']);
    foreach (range(1, 2) as $i) {
        $this->get('/s/'.$link->code);
    }
    Carbon::setTestNow('2026-11-28 09:00:00');
    $this->get('/s/'.$link->code);

    $this->actingAs($this->user)->get(route('smartlinks.index'))->assertInertia(fn (Assert $p) => $p
        ->component('SmartLinks/Index')
        ->has('short_links', 2)
        ->where('short_links', function ($rows) use ($link) {
            $row = collect($rows)->firstWhere('id', $link->id);

            return $row['total'] === 3 && $row['today'] === 1 && $row['last_7_days'] === 3
                && $row['daily'][0] === ['date' => '2026-11-28', 'clicks' => 1]
                && $row['daily'][1] === ['date' => '2026-11-27', 'clicks' => 2];
        }));
});

test('ShortLinkTest: borrar el enlace borra sus clics y deja de redirigir', function () {
    $link = makeShort($this->user);
    $this->get('/s/'.$link->code);

    $this->actingAs($this->user)->delete(route('shortlinks.destroy', $link))->assertRedirect();

    expect(DB::table('short_link_clicks')->count())->toBe(0);
    $this->get('/s/'.$link->code)->assertNotFound();
});

test('ShortLinkTest: hay un tope de enlaces por usuario', function () {
    foreach (range(1, ShortLinkService::MAX_PER_USER) as $i) {
        ShortLink::query()->create(['user_id' => $this->user->id, 'code' => sprintf('A%07d', $i), 'url' => 'https://example.com/'.$i]);
    }

    $this->actingAs($this->user)->postJson(route('shortlinks.store'), ['url' => 'https://example.com/mas'])->assertUnprocessable()->assertJsonValidationErrors('url');
});

test('ShortLinkTest: la tabla de clics no tiene IP, agente ni identificadores', function () {
    $columns = Schema::getColumnListing('short_link_clicks');

    expect($columns)->toEqualCanonicalizing(['id', 'short_link_id', 'date', 'count']);
});

test('ShortLinkTest: la redireccion no envia cookies ni expone al dueno', function () {
    $link = makeShort($this->user);

    $response = $this->get('/s/'.$link->code);

    expect($response->headers->getCookies())->toBe([])->and((string) $response->getContent())->not->toContain($this->user->email);
});

test('ShortLinkTest: un destino con dominio IDN muestra una pagina intermedia con el dominio real y cuenta el clic', function () {
    $link = makeShort($this->user, ['url' => 'https://bücher.example/oferta']);

    $response = $this->get('/s/'.$link->code);

    $response->assertOk()->assertSee('xn--bcher-kva.example')->assertSee('Continuar')
        ->assertHeader('X-Robots-Tag', 'noindex, nofollow')->assertHeader('Referrer-Policy', 'no-referrer');
    expect($response->headers->get('Location'))->toBeNull()
        ->and((int) DB::table('short_link_clicks')->where('short_link_id', $link->id)->sum('count'))->toBe(1);

    $punycode = makeShort($this->user, ['url' => 'https://xn--bcher-kva.example/x']);
    $this->get('/s/'.$punycode->code)->assertOk()->assertSee('Continuar');
});

test('ShortLinkTest: el listado limita el desglose diario a la ventana y el total cubre todo el historial', function () {
    $link = makeShort($this->user);
    DB::table('short_link_clicks')->insert([
        ['short_link_id' => $link->id, 'date' => '2026-11-27', 'count' => 2],
        ['short_link_id' => $link->id, 'date' => '2026-10-01', 'count' => 5],
    ]);

    $row = collect(app(ShortLinkService::class)->listing($this->user))->firstWhere('id', $link->id);

    expect($row['total'])->toBe(7)->and($row['daily'])->toBe([['date' => '2026-11-27', 'clicks' => 2]]);
});

test('ShortLinkTest: el listado se envia aunque el usuario no tenga marcas', function () {
    makeShort($this->user);

    $this->actingAs($this->user)->get(route('smartlinks.index'))->assertInertia(fn (Assert $p) => $p->where('brand', null)->has('short_links', 1));
});

test('ShortLinkTest: desactivar y borrar tienen limite de ritmo propio', function () {
    $link = makeShort($this->user);
    $middleware = fn (string $name) => collect(app('router')->getRoutes()->getByName($name)->gatherMiddleware());

    expect($middleware('shortlinks.update')->contains(fn ($m) => str_starts_with($m, 'throttle:')))->toBeTrue()
        ->and($middleware('shortlinks.destroy')->contains(fn ($m) => str_starts_with($m, 'throttle:')))->toBeTrue();
});
