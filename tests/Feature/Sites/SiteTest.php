<?php

use App\Exceptions\PostValidationException;
use App\Models\Brand;
use App\Models\MediaAsset;
use App\Models\Site;
use App\Models\SiteToken;
use App\Models\Studio\Design;
use App\Models\Studio\DesignExport;
use App\Models\User;
use App\Services\Sites\SiteService;
use App\Support\SiteRichText;
use App\Support\SiteView;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\Support\McpClient;

uses(McpClient::class);

/**
 * T-15 · Sitios: constructor del borrador, publicacion separada, acceso (publico, contraseña, token), formulario, encuesta,
 * analitica sin IP y paridad MCP. La ruta publica no usa sesion de Laravel.
 */
beforeEach(function () {
    Carbon::setTestNow('2026-11-10 12:00:00');
    $this->user = User::factory()->create();
    $this->brand = Brand::factory()->for($this->user)->create(['name' => 'Mi marca']);
    $this->svc = app(SiteService::class);
    Storage::fake('media');
});

function siteMk(object $t, string $slug = 'mi-sitio'): Site
{
    return $t->svc->create($t->user, $t->brand, 'Mi sitio', $slug);
}

/** Paginas con bloques minimos; la segunda ("contacto") solo existe si se pasa `$extra`. */
function sitePages(array $blocks = [], ?array $extra = null): array
{
    $pages = [['key' => 'aaaaaaa1', 'slug' => 'inicio', 'title' => 'Inicio', 'menu' => true, 'blocks' => $blocks]];
    if ($extra !== null) {
        $pages[] = ['key' => 'bbbbbbb2', 'slug' => 'contacto', 'title' => 'Contacto', 'menu' => true, 'blocks' => $extra];
    }

    return $pages;
}

function siteSave(object $t, Site $site, array $pages, array $over = []): Site
{
    return $t->svc->saveDraft($t->user, $site, array_merge(['title' => $site->title, 'slug' => $site->slug, 'pages' => $pages], $over));
}

function siteImage(User $user, array $over = []): MediaAsset
{
    $m = MediaAsset::factory()->for($user)->create($over + ['disk' => 'media', 'mime' => 'image/png', 'path' => 'media/test/p.png']);
    Storage::disk('media')->put($m->path, base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg=='));

    return $m;
}

test('crear un sitio deja una pagina de inicio, slug unico y limite por marca', function () {
    $site = siteMk($this);
    expect($site->draft['pages'])->toHaveCount(1)->and($site->is_published)->toBeFalse()->and($site->url())->toEndWith('/s/mi-sitio');
    expect(fn () => siteMk($this))->toThrow(PostValidationException::class);
    expect(fn () => $this->svc->create($this->user, $this->brand, 'x', 'AB'))->toThrow(PostValidationException::class);
    foreach (range(2, 5) as $i) {
        siteMk($this, "sitio-$i");
    }
    expect(fn () => siteMk($this, 'sitio-6'))->toThrow(PostValidationException::class);
});

test('el borrador sanea texto, valida YouTube y URL, y rechaza imagenes ajenas', function () {
    $site = siteMk($this);
    $alien = siteImage(User::factory()->create());
    $mine = siteImage($this->user, ['path' => 'media/test/m.png']);

    $bad = fn (array $blocks) => expect(fn () => siteSave($this, $site, sitePages($blocks)))->toThrow(PostValidationException::class);
    $bad([['type' => 'youtube', 'url' => 'https://youtube.com.evil.test/watch?v=dQw4w9WgXcQ', 'title' => 'v']]);
    $bad([['type' => 'button', 'kind' => 'url', 'label' => 'x', 'url' => 'javascript:alert(1)']]);
    $bad([['type' => 'button', 'kind' => 'page', 'label' => 'x', 'page' => 'zzzzzzzz']]);
    $bad([['type' => 'image', 'media_id' => $alien->id, 'alt' => 'x']]);
    $bad([['type' => 'image', 'media_id' => $mine->id, 'alt' => '']]);
    $bad([['type' => 'poll', 'question' => 'q', 'options' => ['solo una']]]);
    $bad([['type' => 'contact'], ['type' => 'contact']]);
    $bad([['type' => 'nope']]);

    $saved = siteSave($this, $site, sitePages([
        ['type' => 'heading', 'text' => '<script>alert(1)</script>Hola', 'level' => 2],
        ['type' => 'text', 'body' => 'Texto <img src=x onerror=alert(1)> **negrita** [ok](https://example.com/a?b=1&c=2) [mal](javascript:alert(1))'],
        ['type' => 'youtube', 'url' => 'https://youtu.be/dQw4w9WgXcQ', 'title' => 'Video'],
        ['type' => 'image', 'media_id' => $mine->id, 'alt' => 'Foto'],
    ]));
    $blocks = $saved->draft['pages'][0]['blocks'];
    expect($blocks[0]['text'])->not->toContain('<')->and($blocks[2]['video_id'])->toBe('dQw4w9WgXcQ')->and($blocks[1]['key'])->toMatch('/^[a-f0-9]{8}$/');

    $html = SiteRichText::render($blocks[1]['body']);
    expect($html)->toContain('<strong>negrita</strong>')->toContain('href="https://example.com/a?b=1&amp;c=2"')->toContain('rel="nofollow noopener noreferrer ugc"')
        ->not->toContain('<img')->not->toContain('href="javascript');
    expect(SiteRichText::render('[x](javascript:alert(1))'))->not->toContain('<a');
});

test('lo publicado esta separado del borrador y se actualiza solo al republicar', function () {
    $site = siteMk($this);
    siteSave($this, $site, sitePages([['type' => 'heading', 'text' => 'Version uno', 'level' => 2]]));
    $this->get('/s/mi-sitio')->assertNotFound(); // sin publicar
    $this->svc->publish($this->user, $site->refresh());

    $this->get('/s/mi-sitio')->assertOk()->assertSee('Version uno');
    siteSave($this, $site->refresh(), sitePages([['type' => 'heading', 'text' => 'Version dos', 'level' => 2]]));
    $this->get('/s/mi-sitio')->assertOk()->assertSee('Version uno')->assertDontSee('Version dos');
    expect($site->refresh()->revision)->toBeGreaterThan($site->published_revision);

    $this->svc->publish($this->user, $site);
    $this->get('/s/mi-sitio')->assertSee('Version dos')->assertDontSee('Version uno');

    $this->svc->unpublish($this->user, $site->refresh());
    $this->get('/s/mi-sitio')->assertNotFound();
    $this->get('/s/mi-sitio/inicio')->assertNotFound();
});

test('la pagina publica lleva CSP estricta, cabeceras de seguridad, navegacion y sin cookies', function () {
    $site = siteMk($this);
    siteSave($this, $site, sitePages([['type' => 'youtube', 'url' => 'https://youtu.be/dQw4w9WgXcQ', 'title' => 'Video']], [['type' => 'faq', 'title' => 'FAQ', 'items' => [['q' => 'P1', 'a' => 'R1']]]]));
    $this->svc->publish($this->user, $site->refresh());

    $r = $this->get('/s/mi-sitio')->assertOk();
    $csp = $r->headers->get('Content-Security-Policy');
    expect($csp)->toContain("default-src 'none'")->toContain("frame-ancestors 'none'")->toContain('frame-src https://www.youtube-nocookie.com')
        ->not->toContain('unsafe-inline')->not->toContain('script-src')->toMatch("/style-src 'nonce-[A-Za-z0-9+\/=]+'/");
    expect($r->headers->get('X-Content-Type-Options'))->toBe('nosniff')->and($r->headers->get('Cache-Control'))->toContain('no-store');
    expect($r->headers->getCookies())->toBe([]);
    $r->assertSee('href="/s/mi-sitio/contacto"', false)->assertSee('aria-current="page"', false)->assertDontSee('<script', false)
        ->assertSee('youtube-nocookie.com/embed/dQw4w9WgXcQ', false)->assertSee('<link rel="canonical"', false)->assertDontSee('noindex', false);

    $this->get('/s/mi-sitio/contacto')->assertOk()->assertSee('<details>', false)->assertSee('P1');
    $this->get('/s/mi-sitio/no-existe')->assertNotFound();
});

test('noindex opcional: meta y cabecera', function () {
    $site = siteMk($this);
    $this->svc->updateAccess($this->user, $site, 'public', null, true);
    $this->svc->publish($this->user, $site->refresh());
    $r = $this->get('/s/mi-sitio')->assertOk()->assertSee('<meta name="robots" content="noindex, nofollow">', false);
    expect($r->headers->get('X-Robots-Tag'))->toContain('noindex');
});

test('acceso con contraseña: hash, cookie firmada, y cierre de sesiones al cambiarla', function () {
    $site = siteMk($this);
    expect(fn () => $this->svc->updateAccess($this->user, $site, 'password', null, false))->toThrow(PostValidationException::class);
    expect(fn () => $this->svc->updateAccess($this->user, $site, 'password', 'corta', false))->toThrow(PostValidationException::class);
    $this->svc->updateAccess($this->user, $site, 'password', 'una-clave-larga', false);
    $site->refresh();
    expect($site->password_hash)->not->toContain('una-clave-larga');
    $this->svc->publish($this->user, $site);

    $this->get('/s/mi-sitio')->assertStatus(401)->assertSee('Contraseña')->assertDontSee('Mi sitio');
    $this->get('/s/mi-sitio/inicio')->assertStatus(401);
    $this->post('/s/mi-sitio/_access', ['password' => 'mala'])->assertStatus(401)->assertSee('Contraseña incorrecta');

    $ok = $this->post('/s/mi-sitio/_access', ['password' => 'una-clave-larga'])->assertRedirect('/s/mi-sitio');
    $cookie = $ok->headers->getCookies()[0];
    expect($cookie->isHttpOnly())->toBeTrue()->and($cookie->getPath())->toBe('/s/mi-sitio');
    $this->withUnencryptedCookie($cookie->getName(), $cookie->getValue())->get('/s/mi-sitio')->assertOk()->assertSee('Mi sitio');
    $this->withUnencryptedCookie($cookie->getName(), strrev($cookie->getValue()))->get('/s/mi-sitio')->assertStatus(401);

    $this->svc->updateAccess($this->user, $site->refresh(), 'password', 'otra-clave-larga', false);
    $this->withUnencryptedCookie($cookie->getName(), $cookie->getValue())->get('/s/mi-sitio')->assertStatus(401);
});

test('la contraseña tiene rate limit sin guardar la IP', function () {
    $site = siteMk($this);
    $this->svc->updateAccess($this->user, $site, 'password', 'una-clave-larga', false);
    $this->svc->publish($this->user, $site->refresh());
    foreach (range(1, 5) as $i) {
        $this->post('/s/mi-sitio/_access', ['password' => 'mala'])->assertStatus(401);
    }
    $this->post('/s/mi-sitio/_access', ['password' => 'una-clave-larga'])->assertStatus(429)->assertHeader('Retry-After');
    expect(json_encode(DB::table('site_visits')->get()).json_encode(DB::table('site_messages')->get()))->not->toContain('127.0.0.1');
});

test('acceso por token: hash, canje por cookie sin el token en la URL, caducidad y revocacion inmediata', function () {
    $site = siteMk($this);
    $this->svc->updateAccess($this->user, $site, 'token', null, false);
    $this->svc->publish($this->user, $site->refresh());
    [$token, $plain] = $this->svc->createToken($this->user, $site, 'Cliente', 7);
    expect(SiteToken::first()->token_hash)->toBe(hash('sha256', $plain))->and(json_encode(SiteToken::first()))->not->toContain($plain);

    $this->get('/s/mi-sitio')->assertStatus(403)->assertDontSee('Mi sitio');
    $this->get('/s/mi-sitio?t=st_falso')->assertStatus(403);

    $r = $this->get('/s/mi-sitio?t='.$plain)->assertRedirect('/s/mi-sitio');
    expect($r->headers->get('Location'))->not->toContain('st_');
    $cookie = $r->headers->getCookies()[0];
    $with = fn () => $this->withUnencryptedCookie($cookie->getName(), $cookie->getValue());
    $with()->get('/s/mi-sitio')->assertOk();
    expect($token->refresh()->last_used_at)->not->toBeNull();

    Carbon::setTestNow('2026-11-18 12:00:00'); // caducado
    $with()->get('/s/mi-sitio')->assertStatus(403);
    Carbon::setTestNow('2026-11-10 12:00:00');
    $with()->get('/s/mi-sitio')->assertOk();

    $this->svc->revokeToken($this->user, $site, $token); // efecto inmediato sobre la sesion ya emitida y sobre el enlace
    $with()->get('/s/mi-sitio')->assertStatus(403);
    $this->get('/s/mi-sitio?t='.$plain)->assertStatus(403);
});

test('revocar todo el acceso cierra sesiones y enlaces', function () {
    $site = siteMk($this);
    $this->svc->updateAccess($this->user, $site, 'token', null, false);
    $this->svc->publish($this->user, $site->refresh());
    [, $plain] = $this->svc->createToken($this->user, $site, 'A', null);
    $cookie = $this->get('/s/mi-sitio?t='.$plain)->headers->getCookies()[0];
    $this->withUnencryptedCookie($cookie->getName(), $cookie->getValue())->get('/s/mi-sitio')->assertOk();
    $this->svc->revokeAllAccess($this->user, $site->refresh());
    $this->withUnencryptedCookie($cookie->getName(), $cookie->getValue())->get('/s/mi-sitio')->assertStatus(403);
    expect(SiteToken::whereNull('revoked_at')->count())->toBe(0);
});

test('el formulario de contacto guarda, con trampa, marca de tiempo y limite', function () {
    $site = siteMk($this);
    siteSave($this, $site, sitePages([['type' => 'contact', 'title' => 'Escribenos', 'cta' => 'Enviar']]));
    $this->svc->publish($this->user, $site->refresh());
    $block = $site->refresh()->published['pages'][0]['blocks'][0];
    $url = "/s/mi-sitio/_contact/aaaaaaa1/{$block['key']}";
    $old = fn () => SiteView::token($site, $block['key'], now()->getTimestamp() - 3);
    $data = ['name' => 'Ana <b>', 'email' => 'ana@example.com', 'message' => 'Hola <script>x</script> mundo'];

    $this->post($url, $data + ['ts' => SiteView::token($site, $block['key'], now()->getTimestamp())])->assertRedirect('/s/mi-sitio?sent=0#contacto'); // demasiado rapido
    $this->post($url, $data + ['ts' => $old()])->assertRedirect('/s/mi-sitio?sent=1#contacto');
    $row = DB::table('site_messages')->first();
    expect($row->name)->toBe('Ana')->and($row->message)->not->toContain('<')->and(DB::table('site_messages')->count())->toBe(1);

    $this->post($url, $data + ['website' => 'http://spam', 'ts' => 'x'])->assertRedirect('/s/mi-sitio?sent=1#contacto'); // trampa
    $this->post($url, ['email' => 'no-es-correo', 'name' => 'a', 'message' => 'm', 'ts' => $old()])->assertRedirect('/s/mi-sitio?sent=0#contacto');
    expect(DB::table('site_messages')->count())->toBe(1);

    foreach (range(1, 6) as $i) {
        $this->post($url, $data + ['ts' => $old()]);
    }
    expect(DB::table('site_messages')->count())->toBeLessThanOrEqual(5);
    $this->post('/s/mi-sitio/_contact/aaaaaaa1/ffffffff', $data)->assertNotFound();
});

test('la encuesta agrega votos, limita uno por bloque y reinicia al cambiar las opciones', function () {
    $site = siteMk($this);
    siteSave($this, $site, sitePages([['type' => 'poll', 'question' => 'Cual?', 'options' => ['Uno', 'Dos']]]));
    $this->svc->publish($this->user, $site->refresh());
    $key = $site->refresh()->published['pages'][0]['blocks'][0]['key'];
    $url = "/s/mi-sitio/_vote/aaaaaaa1/$key";

    $this->post($url, ['option' => 1])->assertRedirect("/s/mi-sitio?voted=$key#p-$key");
    $this->post($url, ['option' => 0]); // segundo voto: ignorado
    $this->get("/s/mi-sitio?voted=$key")->assertOk()->assertSee('Dos: 1 voto (100 %)')->assertSee('Uno: 0 votos (0 %)')->assertSee('Gracias por votar');
    $this->get('/s/mi-sitio')->assertDontSee('Dos: 1 voto')->assertSee('Votar');
    $this->get('/s/mi-sitio?results=1')->assertSee('Dos: 1 voto');

    siteSave($this, $site->refresh(), sitePages([['key' => $key, 'type' => 'poll', 'question' => 'Cual?', 'options' => ['Uno', 'Dos', 'Tres']]]));
    $this->svc->publish($this->user, $site->refresh());
    $this->get('/s/mi-sitio?results=1')->assertSee('Dos: 0 votos');
});

test('la analitica cuenta visitas por pagina y dia sin IP ni agente', function () {
    $site = siteMk($this);
    siteSave($this, $site, sitePages([], []));
    $this->svc->publish($this->user, $site->refresh());
    $this->get('/s/mi-sitio');
    $this->get('/s/mi-sitio');
    $this->get('/s/mi-sitio/contacto');
    $this->get('/s/mi-sitio/inexistente');
    $stats = $this->svc->stats($site);
    expect($stats['total'])->toBe(3)->and($stats['by_page'])->toBe(['aaaaaaa1' => 2, 'bbbbbbb2' => 1]);
    expect(array_keys((array) DB::table('site_visits')->first()))->toBe(['id', 'site_id', 'page_key', 'date', 'count']);
});

test('las imagenes solo se sirven si estan en lo publicado y con cabeceras de sandbox', function () {
    $site = siteMk($this);
    $mine = siteImage($this->user);
    $other = siteImage($this->user, ['path' => 'media/test/q.png']);
    siteSave($this, $site, sitePages([['type' => 'image', 'media_id' => $mine->id, 'alt' => 'Foto']]));
    $this->svc->publish($this->user, $site->refresh());

    $r = $this->get("/s/mi-sitio/_m/{$mine->id}")->assertOk();
    expect($r->headers->get('Content-Security-Policy'))->toBe("default-src 'none'; sandbox")->and($r->headers->get('Content-Type'))->toBe('image/png');
    $this->get("/s/mi-sitio/_m/{$other->id}")->assertNotFound();
    $this->get('/s/mi-sitio')->assertSee("/s/mi-sitio/_m/{$mine->id}", false)->assertSee('alt="Foto"', false);
});

test('el estudio se incrusta como imagen exportada de la misma marca', function () {
    $site = siteMk($this);
    $m = siteImage($this->user);
    $design = Design::unguarded(fn () => Design::create(['user_id' => $this->user->id, 'brand_id' => $this->brand->id, 'name' => 'Cartel', 'document' => ['pages' => []], 'width' => 1, 'height' => 1, 'page_count' => 1, 'revision' => 1]));
    $x = DesignExport::create(['user_id' => $this->user->id, 'brand_id' => $this->brand->id, 'design_id' => $design->id, 'revision' => 1, 'format' => 'png', 'pages' => ['p1'], 'media_ids' => [$m->id], 'status' => 'ready']);
    $saved = siteSave($this, $site, sitePages([['type' => 'design', 'export_id' => $x->id, 'page' => 0, 'alt' => 'Cartel']]));
    expect($saved->draft['pages'][0]['blocks'][0]['media_id'])->toBe($m->id);
    expect(fn () => siteSave($this, $site, sitePages([['type' => 'design', 'export_id' => 99999, 'page' => 0, 'alt' => 'x']])))->toThrow(PostValidationException::class);
    expect(fn () => siteSave($this, $site, sitePages([['type' => 'design', 'export_id' => $x->id, 'page' => 3, 'alt' => 'x']])))->toThrow(PostValidationException::class);
});

test('el tema sale del kit de marca y fija un texto legible sobre el acento', function () {
    $site = siteMk($this);
    DB::table('studio_brand_kits')->insert(['user_id' => $this->user->id, 'brand_id' => $this->brand->id, 'palette' => json_encode(['#ffe08a', '#111827']), 'fonts' => json_encode(['Georgia']), 'logos' => '[]', 'created_at' => now(), 'updated_at' => now()]);
    $theme = $this->svc->theme($this->user, $site);
    expect($theme['accent'])->toBe('#ffe08a')->and($theme['on_accent'])->toBe('#111827')->and($theme['font'])->toBe('Georgia');
    $custom = $this->svc->theme($this->user, tap($site)->forceFill(['theme_source' => 'custom', 'theme_color' => '#1e3a8a']));
    expect($custom['accent'])->toBe('#1e3a8a')->and($custom['on_accent'])->toBe('#ffffff');
});

test('gestion: aislamiento por usuario, vista previa del borrador y publicar/despublicar por HTTP', function () {
    $site = siteMk($this);
    siteSave($this, $site, sitePages([['type' => 'heading', 'text' => 'Borrador visible', 'level' => 2]]));
    $other = User::factory()->create();

    $this->actingAs($other)->get(route('sites.edit', $site))->assertNotFound();
    $this->actingAs($other)->post(route('sites.publish', $site))->assertNotFound();
    $this->actingAs($other)->get(route('sites.preview', $site))->assertNotFound();

    $this->actingAs($this->user)->get(route('sites.edit', $site))->assertOk();
    $r = $this->actingAs($this->user)->get(route('sites.preview', $site))->assertOk()->assertSee('Borrador visible')->assertSee('Vista previa del borrador');
    expect($r->headers->get('Content-Security-Policy'))->toContain("frame-ancestors 'self'");
    auth()->logout();
    $this->get('/s/mi-sitio')->assertNotFound(); // el borrador no es publico

    $this->actingAs($this->user)->post(route('sites.publish', $site))->assertRedirect();
    $this->get('/s/mi-sitio')->assertOk();
    $this->actingAs($this->user)->post(route('sites.unpublish', $site))->assertRedirect();
    $this->get('/s/mi-sitio')->assertNotFound();

    $this->actingAs($this->user)->put(route('sites.update', $site), ['title' => 'T', 'slug' => 'mi-sitio', 'pages' => sitePages([['type' => 'button', 'kind' => 'url', 'label' => 'x', 'url' => 'javascript:alert(1)']])])->assertSessionHasErrors();
    $this->actingAs($this->user)->post(route('sites.tokens.store', $site), ['label' => 'Cliente', 'days' => 3])->assertSessionHas('site_token');
});

test('MCP: manage_site lista, devuelve la URL, publica y despublica con sus abilities y sin ver sitios ajenos', function () {
    $site = siteMk($this);
    $this->svc->updateAccess($this->user, $site, 'token', null, false);
    [, $plain] = $this->svc->createToken($this->user, $site, 'A', null);
    $reader = $this->mcpKey($this->user, ['sites:read']);
    $writer = $this->mcpKey($this->user, ['sites:read', 'sites:write']);
    $alien = Site::withoutUserScope()->create(['user_id' => User::factory()->create()->id, 'brand_id' => Brand::factory()->create()->id, 'slug' => 'ajeno', 'title' => 'x', 'draft' => ['pages' => []]]);

    [$err, $res] = $this->callTool($reader, 'manage_site', ['action' => 'list']);
    expect($err)->toBeFalse()->and(collect($res['sites'])->pluck('slug')->all())->toBe(['mi-sitio']);
    [$err, $res] = $this->callTool($reader, 'manage_site', ['action' => 'get_url', 'site_id' => $site->id]);
    expect($res['url'])->toEndWith('/s/mi-sitio')->and(json_encode($res))->not->toContain($plain);
    [$err] = $this->callTool($reader, 'manage_site', ['action' => 'publish', 'site_id' => $site->id]);
    expect($err)->toBeTrue();
    [$err, $res] = $this->callTool($writer, 'manage_site', ['action' => 'publish', 'site_id' => $site->id]);
    expect($err)->toBeFalse()->and($res['published'])->toBeTrue()->and($res['accessible'])->toBeFalse();
    [$err, $res] = $this->callTool($writer, 'manage_site', ['action' => 'unpublish', 'site_id' => $site->id]);
    expect($res['published'])->toBeFalse();
    [$err] = $this->callTool($writer, 'manage_site', ['action' => 'publish', 'site_id' => $alien->id]);
    expect($err)->toBeTrue();
});
