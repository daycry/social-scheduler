<?php

use App\Connectors\Network;
use App\Exceptions\PostValidationException;
use App\Models\Autolist;
use App\Models\AutolistItem;
use App\Models\AutolistSlot;
use App\Models\Brand;
use App\Models\Post;
use App\Models\SocialAccount;
use App\Models\User;
use App\Services\AutolistService;
use App\Services\Media\UrlGuard;
use App\Services\Media\ValidatedUrl;
use App\Services\Rss\RssText;
use App\Support\TextLength;
use Illuminate\Database\QueryException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

// T-88 (F-14): autolistas con un feed RSS/Atom como origen. El feed lo escribe el usuario: pasa por UrlGuard (IP publica fijada, redirecciones
// revalidadas), con tope de tamano y de tiempo, parseo XML sin entidades y deduplicacion con indice unico. Sin red: Http::fake y un servidor local.

const RSS_URL = 'https://feeds.example.test/blog.xml';

/** Guardia de test: valida como el real pero devuelve una peticion al servidor local, fijando `feeds.example.test` a 127.0.0.1. Solo existe en este fichero. */
class RssLoopbackGuard extends UrlGuard
{
    public function __construct(public int $port) {}

    public function validate(string $url): ValidatedUrl
    {
        $path = (string) (parse_url($url, PHP_URL_PATH) ?: '/');

        return new ValidatedUrl("http://feeds.example.test:{$this->port}{$path}", 'https', 'feeds.example.test', $this->port, '127.0.0.1', ['127.0.0.1']);
    }
}

beforeAll(function () {
    $dir = sys_get_temp_dir().DIRECTORY_SEPARATOR.'rss-loopback-'.bin2hex(random_bytes(4));
    mkdir($dir);

    file_put_contents($dir.DIRECTORY_SEPARATOR.'router.php', <<<'PHP'
<?php
$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
header('Content-Type: application/rss+xml');
if ($path === '/slow.xml') {
    sleep(4);
    echo '<rss version="2.0"><channel></channel></rss>';
    return;
}
if ($path === '/huge.xml') {
    // Sin Content-Length (troceado): solo el tope en la lectura lo detiene. 8 MB de 64 en 64 KB.
    echo '<rss version="2.0"><channel><item><title>';
    for ($i = 0; $i < 128; $i++) { echo str_repeat('a', 65536); flush(); }
    echo '</title></item></channel></rss>';
    return;
}
echo '<rss version="2.0"><channel><item><guid>a</guid><title>Local</title><link>https://blog.example.test/a</link></item></channel></rss>';
PHP);

    $sock = stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);
    $port = (int) parse_url('tcp://'.stream_socket_get_name($sock, false), PHP_URL_PORT);
    fclose($sock);

    $proc = proc_open([PHP_BINARY, '-S', "127.0.0.1:$port", '-t', $dir, $dir.DIRECTORY_SEPARATOR.'router.php'], [0 => ['pipe', 'r'], 1 => ['file', $dir.DIRECTORY_SEPARATOR.'out.log', 'w'], 2 => ['file', $dir.DIRECTORY_SEPARATOR.'err.log', 'w']], $pipes);

    for ($i = 0; $i < 50; $i++) {
        if (($c = @fsockopen('127.0.0.1', $port)) !== false) {
            fclose($c);
            break;
        }
        usleep(100000);
    }

    $GLOBALS['rss_loopback'] = ['dir' => $dir, 'port' => $port, 'proc' => $proc];
});

afterAll(function () {
    $l = $GLOBALS['rss_loopback'] ?? null;

    if ($l === null) {
        return;
    }

    proc_terminate($l['proc']);
    proc_close($l['proc']);
    foreach (glob($l['dir'].DIRECTORY_SEPARATOR.'*') ?: [] as $f) {
        @unlink($f);
    }
    @rmdir($l['dir']);
});

beforeEach(function () {
    Http::preventStrayRequests();
    $GLOBALS['rss_faked'] = false;
    Carbon::setTestNow('2026-11-01 12:00:00');
    app()->instance(UrlGuard::class, new UrlGuard(fn (string $host) => ['93.184.216.34']));
    $this->user = User::factory()->create();
    $this->brand = Brand::factory()->for($this->user)->create(['timezone' => 'Europe/Madrid']);
    $this->mastodon = SocialAccount::factory()->for($this->user)->create(['brand_id' => $this->brand->id, 'network' => Network::Mastodon]);
    $this->svc = app(AutolistService::class);
});

/** @param list<array{guid?: string, title: string, link?: string}> $entries */
function rssXml(array $entries): string
{
    $items = '';
    foreach ($entries as $e) {
        $items .= '<item>'.(isset($e['guid']) ? "<guid>{$e['guid']}</guid>" : '').'<title>'.htmlspecialchars($e['title']).'</title>'
            .(isset($e['link']) ? "<link>{$e['link']}</link>" : '').'<description>Resumen</description></item>';
    }

    return '<?xml version="1.0" encoding="UTF-8"?><rss version="2.0"><channel><title>Blog</title>'.$items.'</channel></rss>';
}

function rssList(?string $url = RSS_URL): Autolist
{
    return test()->svc->create(test()->user, test()->brand, 'Blog', [['account_id' => test()->mastodon->id, 'content_type' => 'mastodon_post']], [['day' => 2, 'time' => '10:00']], false, $url);
}

/** Fija lo que sirve el feed; el cuerpo se puede cambiar entre ejecuciones (Http::fake conserva siempre el primer stub, por eso es una funcion). */
function rssFeed(string $body, int $status = 200, array $headers = []): void
{
    $GLOBALS['rss_stub'] = [$body, $status, $headers + ['Content-Type' => 'application/rss+xml']];
    if (! ($GLOBALS['rss_faked'] ?? false)) {
        $GLOBALS['rss_faked'] = true;
        Http::fake([RSS_URL => fn () => Http::response(...$GLOBALS['rss_stub'])]);
    }
}

function rssLabels(Autolist $l): array
{
    return AutolistItem::withoutUserScope()->where('autolist_id', $l->id)->orderBy('position')->pluck('text')->all();
}

test('RSS: las entradas nuevas del feed generan elementos de la cola, de la mas antigua a la mas reciente', function () {
    rssFeed(rssXml([
        ['guid' => 'g3', 'title' => 'Tercera', 'link' => 'https://blog.example.test/3'],
        ['guid' => 'g2', 'title' => 'Segunda', 'link' => 'https://blog.example.test/2'],
        ['guid' => 'g1', 'title' => 'Primera', 'link' => 'https://blog.example.test/1'],
    ]));
    $list = rssList();

    $res = $this->svc->generate($list);

    expect($res['rss'])->toBe(['added' => 3, 'error' => null])
        ->and(rssLabels($list))->toBe(['Primera', 'Segunda', 'Tercera'])
        ->and(AutolistItem::withoutUserScope()->where('autolist_id', $list->id)->orderBy('position')->pluck('link')->all())->toBe(['https://blog.example.test/1', 'https://blog.example.test/2', 'https://blog.example.test/3']);
    $fresh = $list->fresh();
    expect($fresh->rss_error)->toBeNull()->and($fresh->rss_checked_at)->not->toBeNull();
});

test('RSS: una segunda ejecucion no duplica elementos', function () {
    rssFeed(rssXml([['guid' => 'g1', 'title' => 'Una', 'link' => 'https://blog.example.test/1'], ['guid' => 'g2', 'title' => 'Dos']]));
    $list = rssList();

    $this->svc->generate($list);
    $again = $this->svc->generate($list->fresh());

    expect($again['rss']['added'])->toBe(0)->and(rssLabels($list))->toHaveCount(2);
});

test('RSS: las entradas ya vistas no reaparecen tras quitarlas de la cola ni tras editar el feed', function () {
    rssFeed(rssXml([['guid' => 'g1', 'title' => 'Una'], ['guid' => 'g2', 'title' => 'Dos']]));
    $list = rssList();
    $this->svc->generate($list);
    $one = AutolistItem::withoutUserScope()->where('autolist_id', $list->id)->where('text', 'Una')->firstOrFail();
    $this->svc->removeItem($list, $one->id);

    // Feed editado: «Una» sigue (con otro titulo), «Dos» desaparece y llega «Tres».
    rssFeed(rssXml([['guid' => 'g3', 'title' => 'Tres'], ['guid' => 'g1', 'title' => 'Una (corregida)']]));
    $res = $this->svc->generate($list->fresh());

    expect($res['rss']['added'])->toBe(1)->and(rssLabels($list))->toBe(['Dos', 'Tres']);
});

test('RSS: sin guid se deduplica por el enlace y, sin enlace, por el contenido', function () {
    rssFeed(rssXml([['title' => 'Con enlace', 'link' => 'https://blog.example.test/x'], ['title' => 'Sin nada']]));
    $list = rssList();

    $this->svc->generate($list);
    rssFeed(rssXml([['title' => 'Con enlace (otro titulo)', 'link' => 'https://blog.example.test/x'], ['title' => 'Sin nada'], ['title' => 'Nueva']]));
    $res = $this->svc->generate($list->fresh());

    expect($res['rss']['added'])->toBe(1)->and(rssLabels($list))->toHaveCount(3);
});

test('RSS: lee un feed Atom (id y enlace alternate)', function () {
    rssFeed(<<<'XML'
<?xml version="1.0" encoding="utf-8"?>
<feed xmlns="http://www.w3.org/2005/Atom"><title>Blog</title>
  <entry><id>urn:uuid:1</id><title>Entrada Atom</title><link rel="self" href="https://blog.example.test/self"/><link rel="alternate" href="https://blog.example.test/atom-1"/><summary>Resumen</summary></entry>
  <entry><id>urn:uuid:2</id><title type="html">Otra &amp;amp; mas</title></entry>
</feed>
XML);
    $list = rssList();

    $this->svc->generate($list);
    $this->svc->generate($list->fresh());

    expect(rssLabels($list))->toBe(['Otra & mas', 'Entrada Atom']);
    expect(AutolistItem::withoutUserScope()->where('text', 'Entrada Atom')->value('link'))->toBe('https://blog.example.test/atom-1');
});

test('RSS: un enlace que no es http(s) no se copia al texto', function () {
    rssFeed(rssXml([['guid' => 'g1', 'title' => 'Peligrosa', 'link' => 'javascript:alert(1)']]));
    $list = rssList();

    $this->svc->generate($list);

    expect(rssLabels($list))->toBe(['Peligrosa']);
});

test('RSS: una redireccion a una IP privada se rechaza sin llegar a pedirla', function () {
    Http::fake([RSS_URL => Http::response('', 302, ['Location' => 'http://10.0.0.5/feed.xml'])]);
    $list = rssList();

    $res = $this->svc->generate($list);

    expect($res['rss']['added'])->toBe(0)->and($res['rss']['error'])->toContain('no está permitida')
        ->and(rssLabels($list))->toBe([])->and($list->fresh()->rss_error)->toContain('no está permitida');
    Http::assertSentCount(1);
});

test('RSS: un feed cuya URL resuelve a una IP privada no se pide', function () {
    $list = rssList();
    app()->instance(UrlGuard::class, new UrlGuard(fn (string $host) => ['192.168.1.20']));

    $res = $this->svc->generate($list->fresh());

    expect($res['rss']['error'])->toContain('no está permitida');
    Http::assertNothingSent();
});

test('RSS: un cuerpo mayor que el limite se rechaza', function () {
    config(['social.rss.max_bytes' => 2048]);
    rssFeed(rssXml([['guid' => 'g1', 'title' => str_repeat('x', 5000)]]));
    $list = rssList();

    $res = $this->svc->generate($list);

    expect($res['rss']['error'])->toContain('demasiado grande')->and(rssLabels($list))->toBe([]);
});

test('RSS: un Content-Length mayor que el limite se rechaza antes de leer el cuerpo', function () {
    config(['social.rss.max_bytes' => 2048]);
    rssFeed(rssXml([['guid' => 'g1', 'title' => 'x']]), 200, ['Content-Length' => '9999999']);
    $list = rssList();

    expect($this->svc->generate($list)['rss']['error'])->toContain('demasiado grande');
});

test('RSS: un feed que no responde a tiempo da un error legible y la lista sigue generando', function () {
    Http::fake([RSS_URL => fn () => throw new ConnectionException('cURL error 28: Operation timed out')]);
    $list = rssList();

    $res = $this->svc->generate($list);

    expect($res['rss']['error'])->toContain('a tiempo')->and($list->fresh()->last_generated_at)->not->toBeNull();
});

test('RSS: la peticion lleva la URL del feed', function () {
    config(['social.rss.timeout' => 7, 'social.rss.connect_timeout' => 3]);
    rssFeed(rssXml([]));
    $list = rssList();

    $this->svc->generate($list);

    Http::assertSent(fn (Request $r) => $r->url() === RSS_URL);
});

test('RSS: un XML con entidad externa o DOCTYPE no se resuelve ni se ingiere', function () {
    $secret = tempnam(sys_get_temp_dir(), 'xxe');
    file_put_contents($secret, 'SECRETO-DEL-SERVIDOR');
    $uri = 'file://'.str_replace('\\', '/', $secret);
    rssFeed('<?xml version="1.0"?><!DOCTYPE rss [<!ENTITY xxe SYSTEM "'.$uri.'">]><rss version="2.0"><channel><item><guid>g1</guid><title>&xxe;</title></item></channel></rss>');
    $list = rssList();

    $res = $this->svc->generate($list);
    @unlink($secret);

    expect($res['rss']['error'])->toContain('no es un feed válido')->and(rssLabels($list))->toBe([]);
    expect(AutolistItem::withoutUserScope()->where('text', 'like', '%SECRETO%')->exists())->toBeFalse();
});

test('RSS: un cuerpo que no es XML ni feed da un error legible', function () {
    rssFeed('<html><body>no soy un feed</body></html>');
    $list = rssList();

    expect($this->svc->generate($list)['rss']['error'])->toContain('no es un feed válido');

    rssFeed('esto no es xml');
    expect($this->svc->generate($list->fresh())['rss']['error'])->toContain('no es un feed válido');
});

test('RSS: un feed con error HTTP se anota y se recupera en el siguiente sondeo', function () {
    rssFeed('', 503);
    $list = rssList();

    expect($this->svc->generate($list)['rss']['error'])->toContain('503');

    rssFeed(rssXml([['guid' => 'g1', 'title' => 'Ya funciona']]));
    $res = $this->svc->generate($list->fresh());

    expect($res['rss'])->toBe(['added' => 1, 'error' => null])->and($list->fresh()->rss_error)->toBeNull();
});

test('RSS: la deduplicacion es un indice unico: dos ejecuciones simultaneas no duplican', function () {
    rssFeed(rssXml([['guid' => 'g1', 'title' => 'Una']]));
    $list = rssList();
    $this->svc->generate($list);

    // Otra ejecucion que leyo el feed a la vez intenta registrar la misma entrada: la BD lo impide.
    $key = DB::table('autolist_rss_entries')->where('autolist_id', $list->id)->value('entry_key');
    expect(fn () => DB::table('autolist_rss_entries')->insert(['autolist_id' => $list->id, 'feed_hash' => DB::table('autolist_rss_entries')->value('feed_hash'), 'entry_key' => $key, 'created_at' => now()]))->toThrow(QueryException::class);

    // Y el servicio, ante una entrada ya registrada por la otra ejecucion, no crea el elemento.
    $this->svc->generate($list->fresh());
    expect(rssLabels($list))->toHaveCount(1);
});

test('RSS: la cola llena no pierde entradas: se quedan sin ver y se avisa', function () {
    rssFeed(rssXml([['guid' => 'g1', 'title' => 'Una'], ['guid' => 'g2', 'title' => 'Dos']]));
    $list = rssList();
    for ($i = 0; $i < AutolistService::MAX_ITEMS - 1; $i++) {
        $this->svc->addItem($this->user, $list, null, "Relleno $i");
    }

    $res = $this->svc->generate($list);

    expect($res['rss']['added'])->toBe(1)->and($res['rss']['error'])->toContain('cola')
        ->and(DB::table('autolist_rss_entries')->where('autolist_id', $list->id)->count())->toBe(1);

    $this->svc->removeItem($list, AutolistItem::withoutUserScope()->where('autolist_id', $list->id)->orderBy('id')->firstOrFail()->id);
    expect($this->svc->generate($list->fresh())['rss']['added'])->toBe(1);
});

test('RSS: una lista sin feed no sondea nada y conserva el resultado clasico', function () {
    $list = rssList(null);

    expect($this->svc->generate($list))->toBe(['created' => 0, 'skipped' => 0]);
    Http::assertNothingSent();
});

test('RSS: se limita el numero de entradas por sondeo', function () {
    config(['social.rss.max_entries' => 2]);
    rssFeed(rssXml([['guid' => 'g1', 'title' => 'Una'], ['guid' => 'g2', 'title' => 'Dos'], ['guid' => 'g3', 'title' => 'Tres']]));
    $list = rssList();

    $this->svc->generate($list);

    // Se toman las 2 primeras del feed (las mas recientes), de la mas antigua a la mas reciente.
    expect(rssLabels($list))->toBe(['Dos', 'Una']);
});

test('RSS web: crear, cambiar y quitar el feed; una URL privada o no http se rechaza', function () {
    $base = ['brand_id' => $this->brand->id, 'name' => 'Web', 'targets' => [['account_id' => $this->mastodon->id, 'content_type' => 'mastodon_post']], 'slots' => [['day' => 1, 'time' => '09:00']]];

    $this->actingAs($this->user)->post(route('autolists.store'), $base + ['rss_url' => 'http://127.0.0.1/feed'])->assertSessionHasErrors('rss_url');
    $this->actingAs($this->user)->post(route('autolists.store'), $base + ['rss_url' => 'ftp://feeds.example.test/x'])->assertSessionHasErrors('rss_url');
    $this->actingAs($this->user)->post(route('autolists.store'), $base + ['rss_url' => RSS_URL])->assertSessionHasNoErrors();
    $list = Autolist::withoutUserScope()->firstOrFail();
    expect($list->rss_url)->toBe(RSS_URL);

    $this->actingAs($this->user)->patch(route('autolists.update', $list), ['rss_url' => 'https://feeds.example.test/otro.xml'])->assertSessionHasNoErrors();
    expect($list->fresh()->rss_url)->toBe('https://feeds.example.test/otro.xml')->and($list->fresh()->rss_checked_at)->toBeNull();

    $this->actingAs($this->user)->patch(route('autolists.update', $list), ['rss_url' => null])->assertSessionHasNoErrors();
    expect($list->fresh()->rss_url)->toBeNull();
});

test('RSS web: la pagina expone el estado del ultimo sondeo y el error', function () {
    rssFeed('', 503);
    $list = rssList();
    $this->svc->generate($list);

    $this->actingAs($this->user)->get(route('autolists.index', ['brand_id' => $this->brand->id]))->assertInertia(fn ($page) => $page
        ->where('autolists.0.rss_url', RSS_URL)->where('autolists.0.rss_error', fn ($e) => str_contains($e, '503'))->has('autolists.0.rss_checked_at'));
});

test('RSS web: «Generar ahora» sondea el feed y avisa de las entradas anadidas', function () {
    rssFeed(rssXml([['guid' => 'g1', 'title' => 'Una']]));
    $list = rssList();

    $this->actingAs($this->user)->post(route('autolists.generate', $list))->assertSessionHas('success', fn ($m) => str_contains($m, '1 entrada'));
    $this->actingAs($this->user)->post(route('autolists.generate', $list))->assertSessionHas('success', fn ($m) => str_contains($m, '0 entradas'));
});

test('RSS: aislamiento por usuario: nadie mas toca el feed ni ve sus entradas', function () {
    rssFeed(rssXml([['guid' => 'g1', 'title' => 'Una']]));
    $list = rssList();
    $this->svc->generate($list);
    $other = User::factory()->create();

    // El UserScope hace que una lista ajena ni exista para el otro usuario (404).
    $this->actingAs($other)->patch(route('autolists.update', $list), ['rss_url' => 'https://feeds.example.test/robado.xml'])->assertNotFound();
    $this->actingAs($other)->post(route('autolists.generate', $list))->assertNotFound();
    expect($list->fresh()->rss_url)->toBe(RSS_URL);

    $otherBrand = Brand::factory()->for($other)->create();
    $this->actingAs($other)->get(route('autolists.index', ['brand_id' => $otherBrand->id]))->assertInertia(fn ($page) => $page->has('autolists', 0));
    expect(fn () => $this->svc->create($other, $this->brand, 'Ajena', [], [], false, RSS_URL))->toThrow(PostValidationException::class);
});

test('RSS manejador real: un cuerpo troceado mayor que el limite se corta sin leerlo entero', function () {
    Http::allowStrayRequests(); // este test habla con un servidor HTTP local (127.0.0.1) a proposito
    app()->instance(UrlGuard::class, new RssLoopbackGuard($GLOBALS['rss_loopback']['port']));
    config(['social.rss.max_bytes' => 100_000]);
    $list = rssList('https://feeds.example.test/huge.xml');
    $before = memory_get_peak_usage(true);

    $res = $this->svc->generate($list);

    expect($res['rss']['error'])->toContain('demasiado grande')->and(rssLabels($list))->toBe([]);
    expect(memory_get_peak_usage(true) - $before)->toBeLessThan(4 * 1024 * 1024);
});

test('RSS manejador real: un feed lento corta por tiempo y uno normal se lee', function () {
    Http::allowStrayRequests();
    app()->instance(UrlGuard::class, new RssLoopbackGuard($GLOBALS['rss_loopback']['port']));
    config(['social.rss.timeout' => 1]);
    $slow = rssList('https://feeds.example.test/slow.xml');

    expect($this->svc->generate($slow)['rss']['error'])->toContain('a tiempo');

    config(['social.rss.timeout' => 10]);
    $ok = rssList('https://feeds.example.test/ok.xml');

    expect($this->svc->generate($ok)['rss'])->toBe(['added' => 1, 'error' => null])->and(rssLabels($ok))->toBe(['Local']);
});

/** Lista con un destino concreto (Bluesky, Mastodon...) y el feed por defecto. */
function rssListFor(Network $network, string $type, string $url = RSS_URL): Autolist
{
    $account = SocialAccount::factory()->for(test()->user)->create(['brand_id' => test()->brand->id, 'network' => $network]);

    return test()->svc->create(test()->user, test()->brand, 'Destino', [['account_id' => $account->id, 'content_type' => $type]], [['day' => 2, 'time' => '10:00']], false, $url);
}

function rssRaw(string $items): string
{
    return '<?xml version="1.0" encoding="UTF-8"?><rss version="2.0"><channel><title>Blog</title>'.$items.'</channel></rss>';
}

test('RSS: una entrada sin titulo y con resumen largo cabe en Bluesky (300 grafemas): se recorta por palabra con «…» y el enlace queda entero', function () {
    $link = 'https://blog.example.test/entrada-con-enlace-largo';
    rssFeed(rssRaw('<item><guid>g1</guid><link>'.$link.'</link><description>'.trim(str_repeat('palabra ', 80)).'</description></item>'));
    $list = rssListFor(Network::Bluesky, 'bluesky_post');

    $res = $this->svc->generate($list);

    $post = Post::withoutUserScope()->firstOrFail();
    expect($res['created'])->toBe(1)->and($res['skipped'])->toBe(0)
        ->and(TextLength::count($post->text, TextLength::GRAPHEMES))->toBeLessThanOrEqual(300)
        ->and($post->text)->toEndWith("…\n".$link)->and($post->text)->toContain('palabra');
});

test('RSS: con el conteo de Mastodon una URL cuenta 23 aunque mida 326: el titulo se recorta solo lo necesario y el enlace queda entero', function () {
    $link = 'https://blog.example.test/'.str_repeat('a', 300);
    $text = RssText::fit(trim(str_repeat('titulo largo ', 20)), $link, ['max' => 100, 'count' => 'mastodon', 'url_length' => 23]);

    expect($text)->toEndWith('
'.$link)->and($text)->toContain('…')
        ->and(TextLength::count($text, TextLength::MASTODON))->toBeLessThanOrEqual(100)->and(TextLength::count($text, TextLength::MASTODON))->toBeGreaterThan(90);

    // Un destino de Mastodon real (limite 500 y titulo de hasta 300) cabe entero con su enlace.
    rssFeed(rssXml([['guid' => 'g1', 'title' => 'Titulo corto', 'link' => $link]]));
    $res = $this->svc->generate(rssListFor(Network::Mastodon, 'mastodon_post'));

    expect($res['created'])->toBe(1)->and(Post::withoutUserScope()->firstOrFail()->text)->toBe('Titulo corto
'.$link);
});

test('RSS: un titulo que cabe se publica entero y uno largo respeta el limite en grafemas y bytes', function () {
    $short = RssText::fit('Hola mundo', 'https://blog.example.test/x', ['max' => 300, 'count' => 'graphemes']);
    $long = RssText::fit(str_repeat('ab ', 200), 'https://blog.example.test/x', ['max' => 300, 'count' => 'graphemes', 'max_bytes' => 3000]);
    $emoji = RssText::fit(str_repeat('👨‍👩‍👧 ', 150), null, ['max' => 20, 'count' => 'graphemes']);

    expect($short)->toBe("Hola mundo\nhttps://blog.example.test/x")
        ->and(TextLength::count($long, TextLength::GRAPHEMES))->toBeLessThanOrEqual(300)->and($long)->toEndWith("…\nhttps://blog.example.test/x")
        ->and(TextLength::count($emoji, TextLength::GRAPHEMES))->toBeLessThanOrEqual(20)->and($emoji)->toContain('👨‍👩‍👧');
});

test('RSS: si ni el enlace cabe, la entrada no se pierde en silencio: la franja se omite con su motivo', function () {
    $link = 'https://blog.example.test/'.str_repeat('a', 320);
    expect(RssText::fit('Titulo', $link, ['max' => 300, 'count' => 'graphemes']))->toBe("Titulo\n".$link);
    rssFeed(rssXml([['guid' => 'g1', 'title' => 'Titulo', 'link' => $link]]));
    $list = rssListFor(Network::Bluesky, 'bluesky_post');

    $res = $this->svc->generate($list);

    expect($res['created'])->toBe(0)->and($res['skipped'])->toBe(1)
        ->and(AutolistSlot::withoutUserScope()->where('autolist_id', $list->id)->value('skipped_reason'))->toContain('300');
});

test('RSS: ordena por fecha, no por posicion: un feed ascendente de 25 entradas aporta las mas recientes', function () {
    config(['social.rss.initial_entries' => 20]);
    $items = '';
    foreach (range(1, 25) as $n) {
        $items .= "<item><guid>g$n</guid><title>Entrada $n</title><pubDate>".gmdate('D, d M Y H:i:s', strtotime('2026-10-01 00:00:00') + $n * 86400).' GMT</pubDate></item>';
    }
    rssFeed(rssRaw($items));
    $list = rssList();

    $this->svc->generate($list);

    $labels = rssLabels($list);
    expect($labels)->toHaveCount(20)->and($labels[0])->toBe('Entrada 6')->and($labels[19])->toBe('Entrada 25');
});

test('RSS: las entradas sin fecha van detras de las fechadas, en el orden del documento', function () {
    rssFeed(rssRaw('<item><guid>a</guid><title>Sin fecha A</title></item><item><guid>b</guid><title>Fechada</title><pubDate>Mon, 02 Nov 2026 10:00:00 GMT</pubDate></item><item><guid>c</guid><title>Sin fecha C</title></item>'));
    $list = rssList();

    $this->svc->generate($list);

    // Cola de la mas antigua a la mas reciente: las sin fecha (al final del orden) primero, en orden inverso al del documento.
    expect(rssLabels($list))->toBe(['Sin fecha C', 'Sin fecha A', 'Fechada']);
});

test('RSS: la primera lectura solo encola las entradas mas recientes y da por vistas las demas', function () {
    config(['social.rss.initial_entries' => 2]);
    $entries = [];
    foreach ([5, 4, 3, 2, 1] as $n) {
        $entries[] = ['guid' => "g$n", 'title' => "Entrada $n"];
    }
    rssFeed(rssXml($entries));
    $list = rssList();

    $first = $this->svc->generate($list);
    $second = $this->svc->generate($list->fresh());

    expect($first['rss']['added'])->toBe(2)->and(rssLabels($list))->toBe(['Entrada 4', 'Entrada 5'])->and($second['rss']['added'])->toBe(0);

    rssFeed(rssXml([['guid' => 'g6', 'title' => 'Entrada 6'], ...$entries]));
    expect($this->svc->generate($list->fresh())['rss']['added'])->toBe(1)->and(rssLabels($list))->toBe(['Entrada 4', 'Entrada 5', 'Entrada 6']);
});

test('RSS: al cambiar de feed, sus guid no chocan con los del anterior', function () {
    rssFeed(rssXml([['guid' => '1', 'title' => 'Del feed A'], ['guid' => '2', 'title' => 'Tambien A']]));
    $list = rssList();
    $this->svc->generate($list);
    Http::fake(['https://feeds.example.test/b.xml' => Http::response(rssXml([['guid' => '1', 'title' => 'Del feed B'], ['guid' => '2', 'title' => 'Tambien B']]), 200, ['Content-Type' => 'application/rss+xml'])]);

    $this->svc->update($list->fresh(), ['rss_url' => 'https://feeds.example.test/b.xml']);
    $res = $this->svc->generate($list->fresh());

    expect($res['rss']['added'])->toBe(2)->and(rssLabels($list))->toContain('Del feed B', 'Tambien B');
});

test('RSS: se conservan los emojis compuestos y las banderas con etiquetas y se quitan los controles y las marcas bidi', function () {
    $family = "👨\u{200D}👩\u{200D}👧";
    $england = "\u{1F3F4}\u{E0067}\u{E0062}\u{E0065}\u{E006E}\u{E0067}\u{E007F}";
    rssFeed(rssXml([['guid' => 'g1', 'title' => "Hola $family $england\u{202E}fin\u{200B}"]]));
    $list = rssList();

    $this->svc->generate($list);

    expect(rssLabels($list))->toBe(["Hola $family {$england}fin"]);
});

test('RSS: un DOCTYPE en UTF-16 (BOM + entidad) tambien se rechaza', function () {
    $xml = '<?xml version="1.0" encoding="UTF-16"?><!DOCTYPE rss [<!ENTITY a "x">]><rss version="2.0"><channel><item><guid>g1</guid><title>&a;</title></item></channel></rss>';
    rssFeed("\xFF\xFE".mb_convert_encoding($xml, 'UTF-16LE', 'UTF-8'));
    $list = rssList();

    $res = $this->svc->generate($list);

    expect($res['rss']['error'])->toContain('no es un feed válido')->and(rssLabels($list))->toBe([]);
});

test('RSS web: «Generar ahora» con el feed roto lo dice con el motivo', function () {
    rssFeed('', 503);
    $list = rssList();

    $this->actingAs($this->user)->post(route('autolists.generate', $list))
        ->assertSessionHas('error', fn ($m) => str_contains($m, '503'));
});

test('RSS: quitar el feed o volver a uno anterior (A→null→A, A→B→A) no vuelve a encolar lo ya visto; B nuevo es una primera lectura', function () {
    rssFeed(rssXml([['guid' => 'a1', 'title' => 'Del A 1'], ['guid' => 'a2', 'title' => 'Del A 2']]));
    $list = rssList();
    $this->svc->generate($list);
    Http::fake(['https://feeds.example.test/b.xml' => Http::response(rssXml([['guid' => 'b1', 'title' => 'Del B 1']]), 200, ['Content-Type' => 'application/rss+xml'])]);

    $this->svc->update($list->fresh(), ['rss_url' => null]);
    $this->svc->update($list->fresh(), ['rss_url' => RSS_URL]);
    expect($this->svc->generate($list->fresh())['rss']['added'])->toBe(0);

    $this->svc->update($list->fresh(), ['rss_url' => 'https://feeds.example.test/b.xml']);
    expect($this->svc->generate($list->fresh())['rss']['added'])->toBe(1);
    $this->svc->update($list->fresh(), ['rss_url' => RSS_URL]);

    expect($this->svc->generate($list->fresh())['rss']['added'])->toBe(0)->and(rssLabels($list))->toBe(['Del A 2', 'Del A 1', 'Del B 1']);
});

test('RSS: si el feed de la lista cambia mientras se descarga el anterior, no se ingiere nada', function () {
    $list = rssList();
    Http::fake([RSS_URL => function () use ($list) {
        Autolist::withoutUserScope()->whereKey($list->id)->update(['rss_url' => 'https://feeds.example.test/otro.xml']);

        return Http::response(rssXml([['guid' => 'g1', 'title' => 'Del feed viejo']]), 200, ['Content-Type' => 'application/rss+xml']);
    }]);

    $res = $this->svc->generate($list);

    expect($res['rss'])->toBe(['added' => 0, 'error' => null])->and(rssLabels($list))->toBe([])
        ->and(DB::table('autolist_rss_entries')->where('autolist_id', $list->id)->count())->toBe(0);
});

test('RSS: un titulo de una sola palabra de emojis se recorta por grafemas en Threads, no se queda solo el enlace', function () {
    $link = 'https://blog.example.test/x';
    $text = RssText::fit(str_repeat('😀', 200), $link, ['max' => 500, 'count' => 'threads']);

    expect($text)->toEndWith("…\n".$link)->and($text)->toStartWith('😀')
        ->and(TextLength::count($text, TextLength::THREADS))->toBeLessThanOrEqual(500)->and(TextLength::count($text, TextLength::THREADS))->toBeGreaterThan(400);
});

test('RSS web: el aviso muestra el error real del sondeo (cola llena) sin anteponerle «no se pudo leer»', function () {
    rssFeed(rssXml([['guid' => 'g1', 'title' => 'Una'], ['guid' => 'g2', 'title' => 'Dos']]));
    $list = rssList();
    for ($i = 0; $i < AutolistService::MAX_ITEMS - 1; $i++) {
        $this->svc->addItem($this->user, $list, null, "Relleno $i");
    }

    $this->actingAs($this->user)->post(route('autolists.generate', $list))
        ->assertSessionHas('error', fn ($m) => str_starts_with($m, 'La cola de la autolista está llena'));
});
