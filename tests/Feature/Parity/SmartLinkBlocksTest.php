<?php

use App\Connectors\Network;
use App\Enums\PostStatus;
use App\Exceptions\PostValidationException;
use App\Models\Brand;
use App\Models\MediaAsset;
use App\Models\Post;
use App\Models\PostTarget;
use App\Models\SmartLink;
use App\Models\SmartLinkBlock;
use App\Models\SmartLinkSubscriber;
use App\Models\SocialAccount;
use App\Models\User;
use App\Services\SmartLinkService;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

/**
 * T-20 · SmartLinks ampliados: bloques enriquecidos, visibilidad programada, reordenar, clics por bloque y las mitigaciones
 * (XSS, redireccion abierta, SSRF por diseno, abuso del formulario). La pagina publica no usa sesion ni cookies.
 */
beforeEach(function () {
    Carbon::setTestNow('2026-11-10 12:00:00');
    $this->user = User::factory()->create();
    $this->brand = Brand::factory()->for($this->user)->create(['name' => 'Mi marca', 'timezone' => 'Europe/Madrid']);
});

function slRich(Brand $brand, array $blocks, array $over = []): array
{
    return array_merge(['brand_id' => $brand->id, 'slug' => 'mi-marca', 'title' => 'Mi marca', 'published' => true, 'blocks' => $blocks], $over);
}

function slSave(object $t, array $blocks, array $over = [])
{
    return $t->actingAs($t->user)->post(route('smartlinks.save'), slRich($t->brand, $blocks, $over));
}

function slPublic(): void
{
    auth()->logout();
    test()->flushSession();
}

test('Bloques: boton, imagen propia, video, separador, iconos, formulario y posts se guardan con su forma y la pagina los pinta', function () {
    $img = MediaAsset::factory()->create(['user_id' => $this->user->id, 'kind' => MediaAsset::KIND_IMAGE, 'status' => MediaAsset::STATUS_READY]);

    slSave($this, [
        ['type' => 'header', 'label' => 'Novedades'],
        ['type' => 'link', 'label' => 'Mi web', 'url' => 'https://example.com/a'],
        ['type' => 'image', 'label' => 'Catalogo', 'url' => 'https://example.com/catalogo', 'media_id' => $img->id],
        ['type' => 'youtube', 'label' => 'Ultimo video', 'url' => 'https://youtu.be/dQw4w9WgXcQ?t=3'],
        ['type' => 'divider', 'label' => ''],
        ['type' => 'social', 'label' => 'Sigueme', 'data' => ['items' => [['network' => 'instagram', 'url' => 'https://instagram.com/mi'], ['network' => 'x', 'url' => 'https://x.com/mi']]]],
        ['type' => 'email', 'label' => 'Boletin', 'data' => ['cta' => 'Quiero', 'consent' => 'Solo novedades']],
        ['type' => 'posts', 'label' => 'Ultimos posts', 'data' => ['count' => 9]],
    ])->assertSessionHasNoErrors();

    $blocks = SmartLink::query()->firstOrFail()->blocks;
    expect($blocks->pluck('type')->all())->toBe(['header', 'link', 'image', 'youtube', 'divider', 'social', 'email', 'posts'])
        ->and($blocks[3]->url)->toBe('https://www.youtube.com/watch?v=dQw4w9WgXcQ')->and($blocks[3]->data)->toBe(['video_id' => 'dQw4w9WgXcQ'])
        ->and($blocks[5]->data['items'])->toHaveCount(2)->and($blocks[6]->data['cta'])->toBe('Quiero')->and($blocks[7]->data['count'])->toBe(6);

    slPublic();
    $page = $this->get('/l/mi-marca');
    $page->assertOk()->assertSee('https://www.youtube-nocookie.com/embed/dQw4w9WgXcQ', false)->assertSee('name="email"', false)->assertSee('Quiero')
        ->assertSee('Sigueme')->assertSee('role="separator"', false)
        ->assertDontSee('instagram.com/mi', false)->assertDontSee('example.com/catalogo', false);
    expect($page->headers->has('Set-Cookie'))->toBeFalse()
        ->and($page->headers->get('Content-Security-Policy'))->toContain("default-src 'none'")->toContain('frame-src https://www.youtube-nocookie.com')->not->toContain('script-src *');
});

test('Video: solo URLs de YouTube con ID de 11 caracteres; hosts parecidos, esquemas y otros dominios se rechazan sin ninguna consulta de red', function () {
    foreach (['https://www.youtube.com/watch?v=dQw4w9WgXcQ', 'https://m.youtube.com/watch?v=dQw4w9WgXcQ&list=x', 'https://youtu.be/dQw4w9WgXcQ', 'https://www.youtube.com/shorts/dQw4w9WgXcQ', 'https://www.youtube.com/embed/dQw4w9WgXcQ'] as $ok) {
        expect(SmartLinkService::youtubeId($ok))->toBe('dQw4w9WgXcQ');
    }
    foreach ([
        'https://youtube.com.evil.test/watch?v=dQw4w9WgXcQ', 'https://evil.test/watch?v=dQw4w9WgXcQ', 'https://evilyoutube.com/watch?v=dQw4w9WgXcQ',
        'https://www.youtube.com/watch?v=corto', 'https://www.youtube.com/watch?v=dQw4w9WgXcQ"onload="x', 'javascript:alert(1)', 'http://user:pw@youtube.com/watch?v=dQw4w9WgXcQ',
        'https://www.youtube.com/watch?v[]=dQw4w9WgXcQ', 'https://www.youtube.com/playlist?list=PL1234567890', '', null,
    ] as $bad) {
        expect(SmartLinkService::youtubeId($bad))->toBeNull();
    }

    Http::preventStrayRequests();
    slSave($this, [['type' => 'youtube', 'label' => 'v', 'url' => 'https://youtube.com.evil.test/watch?v=dQw4w9WgXcQ']])->assertSessionHasErrors('blocks.0.url');
    Http::assertNothingSent();
});

test('XSS: etiquetas y textos se sanean al guardar y se escapan al pintar; la gestion no usa v-html', function () {
    slSave($this, [
        ['type' => 'link', 'label' => '<img src=x onerror=alert(1)>Oferta<script>alert(2)</script>', 'url' => 'https://example.com'],
        ['type' => 'email', 'label' => 'Boletin', 'data' => ['cta' => '"><svg onload=1>', 'consent' => '<b>acepto</b> &amp; "citas"']],
    ], ['title' => 'T <script>x</script>', 'bio' => '<b onclick=1>bio</b>'])->assertSessionHasNoErrors();

    $link = SmartLink::query()->firstOrFail();
    // Se guarda como texto (sin strip_tags: «1 <2 y 3> 0» es legitimo) y TODO se escapa al pintar.
    expect($link->blocks[0]->label)->toBe('<img src=x onerror=alert(1)>Oferta<script>alert(2)</script>');

    slPublic();
    $html = $this->get('/l/mi-marca')->assertOk()->getContent();
    expect($html)->not->toContain('<img src=x')->and($html)->not->toContain('<svg onload')->and($html)->not->toContain('<script>alert')
        ->and($html)->toContain('T &lt;script&gt;x&lt;/script&gt;');
    expect(file_get_contents(resource_path('js/Pages/SmartLinks/Index.vue')))->not->toContain('v-html');
});

test('Redireccion abierta: go solo usa URLs guardadas en el bloque; un parametro url/to no cambia el destino y los indices fuera de rango dan 404', function () {
    slSave($this, [
        ['type' => 'link', 'label' => 'Web', 'url' => 'https://example.com/a'],
        ['type' => 'social', 'label' => 'Redes', 'data' => ['items' => [['network' => 'instagram', 'url' => 'https://instagram.com/mi'], ['network' => 'x', 'url' => 'https://x.com/mi']]]],
        ['type' => 'header', 'label' => 'Titulo'],
        ['type' => 'email', 'label' => 'Boletin'],
    ]);
    slPublic();
    [$link, $social, $header, $email] = SmartLink::withoutUserScope()->firstOrFail()->blocks->all();

    $this->get('/l/mi-marca/go/'.$link->id.'?url=https://evil.test&to=//evil.test&i=3')->assertRedirect('https://example.com/a');
    $this->get('/l/mi-marca/go/'.$social->id.'?i=1&url=https://evil.test')->assertRedirect('https://x.com/mi');
    $this->get('/l/mi-marca/go/'.$social->id.'?i=0')->assertRedirect('https://instagram.com/mi');
    $this->get('/l/mi-marca/go/'.$social->id.'?i=5')->assertNotFound();
    $this->get('/l/mi-marca/go/'.$header->id)->assertNotFound();
    $this->get('/l/mi-marca/go/'.$email->id)->assertNotFound();

    DB::table('smart_link_blocks')->where('id', $social->id)->update(['data' => json_encode(['items' => [['network' => 'x', 'url' => 'javascript:alert(1)']]])]);
    $this->get('/l/mi-marca/go/'.$social->id.'?i=0')->assertNotFound();
});

test('Visibilidad programada: fechas locales de la marca, oculto antes y despues, sin pista en la pagina ni en go, y validacion del rango', function () {
    slSave($this, [
        ['type' => 'link', 'label' => 'Siempre', 'url' => 'https://example.com/a'],
        ['type' => 'link', 'label' => 'Futuro', 'url' => 'https://example.com/b', 'visible_from' => '2026-11-12T10:00'],
        ['type' => 'link', 'label' => 'Caduca', 'url' => 'https://example.com/c', 'visible_until' => '2026-11-11T09:00'],
    ])->assertSessionHasNoErrors();
    [$always, $future, $expiring] = SmartLink::query()->firstOrFail()->blocks->all();
    expect($future->visible_from->toIso8601String())->toBe('2026-11-12T09:00:00+00:00'); // 10:00 en Madrid = 09:00 UTC

    slPublic();
    $this->get('/l/mi-marca')->assertSee('Siempre')->assertSee('Caduca')->assertDontSee('Futuro');
    $this->get('/l/mi-marca/go/'.$future->id)->assertNotFound();
    expect(DB::table('smart_link_clicks')->count())->toBe(0);

    Carbon::setTestNow('2026-11-12 09:30:00');
    $this->get('/l/mi-marca')->assertSee('Futuro')->assertDontSee('Caduca');
    $this->get('/l/mi-marca/go/'.$future->id)->assertRedirect('https://example.com/b');
    $this->get('/l/mi-marca/go/'.$expiring->id)->assertNotFound();

    $this->actingAs($this->user);
    slSave($this, [['type' => 'link', 'label' => 'x', 'url' => 'https://example.com', 'visible_from' => '2026-11-12T10:00', 'visible_until' => '2026-11-12T09:00']])->assertSessionHasErrors('blocks.0.visible_until');
    slSave($this, [['type' => 'link', 'label' => 'x', 'url' => 'https://example.com', 'visible_from' => 'no es una fecha']])->assertSessionHasErrors('blocks.0.visible_from');
});

test('Reordenar y clics por bloque: el orden enviado manda, se conservan los clics por id y se resumen 7 y 30 dias', function () {
    slSave($this, [
        ['type' => 'link', 'label' => 'A', 'url' => 'https://example.com/a'],
        ['type' => 'link', 'label' => 'B', 'url' => 'https://example.com/b'],
        ['type' => 'header', 'label' => 'C'],
    ]);
    [$a, $b, $c] = SmartLink::query()->firstOrFail()->blocks->all();
    slPublic();
    $this->get('/l/mi-marca/go/'.$a->id);
    $this->get('/l/mi-marca/go/'.$b->id);
    $this->get('/l/mi-marca/go/'.$b->id);
    Carbon::setTestNow('2026-11-25 12:00:00'); // el primer clic cae fuera de los 7 dias
    $this->get('/l/mi-marca/go/'.$b->id);

    $this->actingAs($this->user);
    slSave($this, [
        ['id' => $c->id, 'type' => 'header', 'label' => 'C'],
        ['id' => $b->id, 'type' => 'link', 'label' => 'B', 'url' => 'https://example.com/b'],
        ['id' => $a->id, 'type' => 'link', 'label' => 'A', 'url' => 'https://example.com/a'],
    ])->assertSessionHasNoErrors();

    $link = SmartLink::query()->firstOrFail();
    expect($link->blocks->pluck('label')->all())->toBe(['C', 'B', 'A'])->and($link->blocks->pluck('id')->all())->toBe([$c->id, $b->id, $a->id]);
    $stats = app(SmartLinkService::class)->stats($link);
    expect($stats['by_block'][$b->id])->toBe(3)->and($stats['by_block'][$a->id])->toBe(1)->and($stats['by_block_7d'][$b->id])->toBe(1)->and($stats['by_block_7d'])->not->toHaveKey($a->id);

    $this->get(route('smartlinks.index'))->assertInertia(fn ($p) => $p->where('link.blocks.1.label', 'B')->where('stats.by_block.'.$b->id, 3)->has('block_types', 8));
});

test('Formulario de email: alta uniforme, correo invalido y trampa sin guardar, sin cookies y 404 si no es un formulario', function () {
    $this->withoutMiddleware(ThrottleRequests::class);
    slSave($this, [['type' => 'email', 'label' => 'Boletin'], ['type' => 'link', 'label' => 'x', 'url' => 'https://example.com']]);
    slPublic();
    $block = SmartLink::withoutUserScope()->firstOrFail()->blocks->first();
    $url = '/l/mi-marca/subscribe/'.$block->id;

    $r = $this->post($url, ['email' => ' Ana@Example.COM ']);
    $r->assertRedirect('/l/mi-marca?subscribed=1#suscripcion');
    expect($r->headers->has('Set-Cookie'))->toBeFalse()->and(SmartLinkSubscriber::query()->pluck('email')->all())->toBe(['ana@example.com']);
    $this->post($url, ['email' => 'ana@example.com'])->assertRedirect('/l/mi-marca?subscribed=1#suscripcion');   // repetido: misma respuesta
    $this->post($url, ['email' => 'no-es-email'])->assertRedirect('/l/mi-marca?subscribed=0#suscripcion');
    $this->post($url, ['email' => "x@example.com\nBcc: y@example.com"])->assertRedirect('/l/mi-marca?subscribed=0#suscripcion');
    $this->post($url, ['email' => 'bot@example.com', 'website' => 'http://spam.test'])->assertRedirect('/l/mi-marca?subscribed=1#suscripcion'); // trampa
    expect(SmartLinkSubscriber::query()->count())->toBe(1);

    $this->get('/l/mi-marca')->assertSee('name="website"', false);
    // Sin IP ni agente en la tabla.
    expect(DB::getSchemaBuilder()->getColumnListing('smart_link_subscribers'))->not->toContain('ip')->not->toContain('user_agent');

    // Un bloque que no es de email, o uno oculto, no admite altas.
    $other = SmartLink::withoutUserScope()->firstOrFail()->blocks->last();
    $this->post('/l/mi-marca/subscribe/'.$other->id, ['email' => 'a@example.com'])->assertNotFound();
});

test('Formulario de email: limite de ritmo de 5 altas por minuto desde el mismo origen', function () {
    slSave($this, [['type' => 'email', 'label' => 'Boletin']]);
    slPublic();
    $url = '/l/mi-marca/subscribe/'.SmartLink::withoutUserScope()->firstOrFail()->blocks->first()->id;

    $codes = collect(range(1, 7))->map(fn ($i) => $this->post($url, ['email' => "n$i@example.com"])->getStatusCode());

    expect($codes->take(5)->unique()->all())->toBe([302])->and($codes->slice(5)->unique()->values()->all())->toBe([429])
        ->and(SmartLinkSubscriber::query()->count())->toBe(5);
});

test('Formulario de email: tope por bloque, solo un formulario por pagina, exportacion CSV solo del propietario y neutralizada', function () {
    slSave($this, [['type' => 'email', 'label' => 'A'], ['type' => 'email', 'label' => 'B']])->assertSessionHasErrors('blocks');
    slSave($this, [['type' => 'email', 'label' => 'A']])->assertSessionHasNoErrors();
    $link = SmartLink::query()->firstOrFail();
    $block = $link->blocks->first();
    $svc = app(SmartLinkService::class);

    config(['social.smartlinks.max_subscribers' => 2]);
    foreach (['a@example.com', 'b@example.com', 'c@example.com'] as $e) {
        expect($svc->subscribe($link, $block, $e))->toBeTrue();
    }
    expect(SmartLinkSubscriber::query()->count())->toBe(2);

    config(['social.smartlinks.max_subscribers' => 100]);
    $svc->subscribe($link, $block, '=cmd@example.com');
    $csv = $this->get(route('smartlinks.subscribers', $link))->assertOk()->streamedContent();
    expect($csv)->toStartWith("\xEF\xBB\xBF".'"Email"')->and($csv)->toContain('a@example.com')->and($csv)->toContain("\"'=cmd@example.com\"");

    $this->actingAs(User::factory()->create())->get(route('smartlinks.subscribers', $link))->assertNotFound();
});

test('Posts recientes: solo publicadas de la marca con URL https, texto saneado y sin cruzar marcas', function () {
    $account = SocialAccount::factory()->for($this->user)->create(['brand_id' => $this->brand->id, 'network' => Network::Instagram]);
    $mk = function (string $text, string $url, PostStatus $status, ?Brand $brand = null, int $minutes = 0) use ($account) {
        $post = Post::factory()->create(['brand_id' => ($brand ?? $this->brand)->id, 'user_id' => $this->user->id, 'text' => $text]);

        return PostTarget::factory()->create(['post_id' => $post->id, 'social_account_id' => $account->id, 'network' => Network::Instagram, 'status' => $status,
            'external_id' => 'x'.$post->id, 'external_url' => $url, 'published_at' => now()->subMinutes($minutes), 'text' => null]);
    };
    $mk('Primero <b>negrita</b>', 'https://instagram.com/p/1', PostStatus::Published, null, 1);
    $mk('Segundo', 'https://instagram.com/p/2', PostStatus::Published, null, 2);
    $mk('Borrador', 'https://instagram.com/p/3', PostStatus::Scheduled);
    $mk('Inseguro', 'javascript:alert(1)', PostStatus::Published);
    $mk('Http plano', 'http://instagram.com/p/4', PostStatus::Published);
    $mk('De otra marca', 'https://instagram.com/p/5', PostStatus::Published, Brand::factory()->for($this->user)->create());

    slSave($this, [['type' => 'posts', 'label' => 'Ultimos', 'data' => ['count' => 3]]]);
    slPublic();

    $posts = app(SmartLinkService::class)->recentPosts(SmartLink::query()->withoutGlobalScopes()->firstOrFail(), 5);
    expect(collect($posts)->pluck('text')->all())->toBe(['Primero <b>negrita</b>', 'Segundo']);
    $this->get('/l/mi-marca')->assertSee('Primero &lt;b&gt;negrita&lt;/b&gt;', false)->assertSee('https://instagram.com/p/1', false)->assertDontSee('Borrador')->assertDontSee('De otra marca')->assertDontSee('javascript:', false);
});

test('Validaciones de tipo y aislamiento: imagen ajena, iconos invalidos, tipo desconocido y datos no http', function () {
    $foreign = MediaAsset::factory()->create(['user_id' => User::factory()->create()->id, 'kind' => MediaAsset::KIND_IMAGE, 'status' => MediaAsset::STATUS_READY]);

    slSave($this, [['type' => 'image', 'label' => 'x', 'url' => 'https://example.com', 'media_id' => $foreign->id]])->assertSessionHasErrors('blocks.0.media_id');
    slSave($this, [['type' => 'social', 'label' => '', 'data' => ['items' => [['network' => 'myspace', 'url' => 'https://x.com']]]]])->assertSessionHasErrors('blocks.0.data');
    slSave($this, [['type' => 'social', 'label' => '', 'data' => ['items' => [['network' => 'x', 'url' => 'javascript:alert(1)']]]]])->assertSessionHasErrors('blocks.0.data');
    slSave($this, [['type' => 'social', 'label' => '', 'data' => ['items' => []]]])->assertSessionHasErrors('blocks.0.data');
    slSave($this, [['type' => 'iframe', 'label' => 'x']])->assertSessionHasErrors('blocks.0.type');
    slSave($this, [['type' => 'image', 'label' => 'x', 'url' => 'ftp://example.com', 'media_id' => null]])->assertSessionHasErrors('blocks.0.url');
    expect(SmartLink::query()->count())->toBe(0);
    expect(fn () => app(SmartLinkService::class)->save($this->user, $this->brand, slRich($this->brand, [['type' => 'link', 'label' => '', 'url' => 'https://example.com']])))->toThrow(PostValidationException::class);
    expect(SmartLinkBlock::TYPES)->toHaveCount(8);
});
