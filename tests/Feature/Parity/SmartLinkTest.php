<?php

use App\Exceptions\PostValidationException;
use App\Models\Brand;
use App\Models\MediaAsset;
use App\Models\SmartLink;
use App\Models\SmartLinkBlock;
use App\Models\User;
use App\Services\SmartLinkService;
use App\Support\SafeUrl;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

beforeEach(function () {
    Carbon::setTestNow('2026-11-01 12:00:00');
    $this->user = User::factory()->create();
    $this->brand = Brand::factory()->for($this->user)->create(['name' => 'Mi marca']);
});

function slPayload(Brand $brand, array $over = []): array
{
    return array_merge([
        'brand_id' => $brand->id, 'slug' => 'mi-marca', 'title' => 'Mi marca', 'bio' => 'Hola mundo', 'theme_color' => '#112233', 'theme_mode' => 'dark',
        'blocks' => [
            ['type' => 'header', 'label' => 'Novedades'],
            ['type' => 'link', 'label' => 'Mi web', 'url' => 'https://example.com/a?b=1'],
        ],
    ], $over);
}

test('SmartLinks: crear el microsite con bloques ordenados y cabecera', function () {
    $this->actingAs($this->user)->post(route('smartlinks.save'), slPayload($this->brand))->assertRedirect()->assertSessionHasNoErrors();

    $link = SmartLink::query()->firstOrFail();
    expect($link->slug)->toBe('mi-marca')->and($link->theme_mode)->toBe('dark')
        ->and($link->blocks->pluck('label')->all())->toBe(['Novedades', 'Mi web'])
        ->and($link->blocks->pluck('type')->all())->toBe(['header', 'link'])
        ->and($link->blocks[0]->url)->toBeNull();
});

test('SmartLinks: rechaza javascript:, data:, relativas y credenciales en la URL; slug y colores invalidos', function () {
    foreach (['javascript:alert(1)', 'JaVaScRiPt:alert(1)', 'data:text/html,<script>1</script>', '//evil.com', '/relativa', 'ftp://x.com/a', 'https://u:p@x.com', "java\tscript:alert(1)", 'https://'] as $bad) {
        $this->actingAs($this->user)->post(route('smartlinks.save'), slPayload($this->brand, ['blocks' => [['type' => 'link', 'label' => 'x', 'url' => $bad]]]))
            ->assertSessionHasErrors('blocks.0.url');
    }
    expect(SmartLink::query()->count())->toBe(0);

    foreach (['Mayus', 'a', '-ab', 'ab-', 'con espacio', 'con/barra', str_repeat('a', 41)] as $slug) {
        $this->actingAs($this->user)->post(route('smartlinks.save'), slPayload($this->brand, ['slug' => $slug]))->assertSessionHasErrors('slug');
    }
    $this->actingAs($this->user)->post(route('smartlinks.save'), slPayload($this->brand, ['theme_color' => 'red; background:url(x)']))->assertSessionHasErrors('theme_color');
    $this->actingAs($this->user)->post(route('smartlinks.save'), slPayload($this->brand, ['blocks' => [['type' => 'script', 'label' => 'x']]]))->assertSessionHasErrors('blocks.0.type');
    expect(SmartLink::query()->count())->toBe(0);
});

test('SafeUrl: solo http/https con host', function () {
    expect(SafeUrl::isSafe('https://example.com/ruta?x=1#a'))->toBeTrue()
        ->and(SafeUrl::isSafe('http://example.com'))->toBeTrue()
        ->and(SafeUrl::isSafe('javascript:alert(1)'))->toBeFalse()
        ->and(SafeUrl::isSafe('mailto:a@b.c'))->toBeFalse()
        ->and(SafeUrl::isSafe(null))->toBeFalse()
        ->and(SafeUrl::isSafe(''))->toBeFalse();
});

test('SmartLinks: el slug es unico entre usuarios y editar conserva los clics de los bloques existentes', function () {
    $this->actingAs($this->user)->post(route('smartlinks.save'), slPayload($this->brand))->assertSessionHasNoErrors();
    $other = User::factory()->create();
    $otherBrand = Brand::factory()->for($other)->create();
    $this->actingAs($other)->post(route('smartlinks.save'), slPayload($otherBrand))->assertSessionHasErrors('slug');

    $link = SmartLink::withoutUserScope()->where('slug', 'mi-marca')->firstOrFail();
    $linkBlock = $link->blocks->firstWhere('type', 'link');
    $this->get(route('smartlinks.go', ['slug' => 'mi-marca', 'block' => $linkBlock->id]));
    $this->actingAs($this->user)->post(route('smartlinks.save'), slPayload($this->brand, ['title' => 'Nuevo', 'blocks' => [['id' => $linkBlock->id, 'type' => 'link', 'label' => 'Web 2', 'url' => 'https://example.org']]]))
        ->assertSessionHasNoErrors();

    $link->refresh();
    expect($link->title)->toBe('Nuevo')->and(SmartLink::withoutUserScope()->where('user_id', $this->user->id)->count())->toBe(1)
        ->and($link->blocks)->toHaveCount(1)->and($link->blocks[0]->id)->toBe($linkBlock->id)->and($link->blocks[0]->label)->toBe('Web 2')
        ->and((int) DB::table('smart_link_clicks')->sum('count'))->toBe(1);
});

test('SmartLinks: pagina publica sin login, sin cookies de sesion, con Open Graph y escapando el contenido', function () {
    $this->actingAs($this->user)->post(route('smartlinks.save'), slPayload($this->brand, ['title' => 'Marca <script>x</script>', 'bio' => 'Bio "citada" & mas']))->assertSessionHasNoErrors();
    auth()->logout();
    $this->flushSession();

    $resp = $this->get('/l/mi-marca');

    $resp->assertOk()
        ->assertSee('property="og:title"', false)->assertSee('property="og:description"', false)->assertSee('property="og:url"', false)
        ->assertSee('Novedades')->assertSee('Mi web')
        ->assertDontSee('<script>x</script>', false)->assertSee('Marca &lt;script&gt;x&lt;/script&gt;', false);
    expect($resp->headers->getCookies())->toBe([])->and($resp->headers->has('Set-Cookie'))->toBeFalse();
    // El enlace publico apunta al contador, no directamente al destino.
    $block = SmartLinkBlock::query()->where('type', 'link')->firstOrFail();
    $resp->assertSee('/l/mi-marca/go/'.$block->id, false)->assertDontSee('example.com/a', false);
});

test('SmartLinks: visitas y clics se cuentan por dia sin datos personales y el clic redirige', function () {
    $this->actingAs($this->user)->post(route('smartlinks.save'), slPayload($this->brand));
    auth()->logout();
    $block = SmartLinkBlock::query()->where('type', 'link')->firstOrFail();

    $this->get('/l/mi-marca')->assertOk();
    $this->withHeaders(['User-Agent' => 'Agente-Secreto/1.0', 'Referer' => 'https://referer-secreto.test'])->get('/l/mi-marca')->assertOk();
    $r = $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.77'])->get('/l/mi-marca/go/'.$block->id);
    $r->assertRedirect('https://example.com/a?b=1');
    expect($r->headers->has('Set-Cookie'))->toBeFalse();

    expect(DB::table('smart_link_views')->get()->all())->toHaveCount(1)
        ->and((int) DB::table('smart_link_views')->value('count'))->toBe(2)
        ->and((int) DB::table('smart_link_clicks')->value('count'))->toBe(1)
        ->and(DB::table('smart_link_views')->value('date'))->toContain('2026-11-01');

    // Sin IP, agente ni referer en ninguna tabla de contadores.
    $dump = json_encode([DB::table('smart_link_views')->get(), DB::table('smart_link_clicks')->get()]);
    expect($dump)->not->toContain('203.0.113.77')->not->toContain('Agente-Secreto')->not->toContain('referer-secreto');
    foreach (['smart_link_views', 'smart_link_clicks'] as $table) {
        expect(DB::getSchemaBuilder()->getColumnListing($table))->not->toContain('ip')->not->toContain('user_agent')->not->toContain('referer');
    }

    // Otro dia: otra fila.
    Carbon::setTestNow('2026-11-02 09:00:00');
    $this->get('/l/mi-marca');
    expect(DB::table('smart_link_views')->count())->toBe(2);
});

test('SmartLinks: un destino inseguro en BD nunca se redirige ni se muestra (defensa en profundidad)', function () {
    $this->actingAs($this->user)->post(route('smartlinks.save'), slPayload($this->brand));
    auth()->logout();
    $block = SmartLinkBlock::query()->where('type', 'link')->firstOrFail();
    DB::table('smart_link_blocks')->where('id', $block->id)->update(['url' => 'javascript:alert(1)']);

    $this->get('/l/mi-marca/go/'.$block->id)->assertNotFound();
    $this->get('/l/mi-marca')->assertOk()->assertDontSee('javascript:', false)->assertDontSee('Mi web');
    expect(DB::table('smart_link_clicks')->count())->toBe(0);
});

test('SmartLinks: slug inexistente, despublicado o bloque de otro microsite/cabecera dan 404', function () {
    $this->actingAs($this->user)->post(route('smartlinks.save'), slPayload($this->brand));
    $other = User::factory()->create();
    $otherBrand = Brand::factory()->for($other)->create();
    $this->actingAs($other)->post(route('smartlinks.save'), slPayload($otherBrand, ['slug' => 'otro', 'blocks' => [['type' => 'link', 'label' => 'o', 'url' => 'https://otro.test']]]));
    auth()->logout();

    $header = SmartLinkBlock::query()->where('type', 'header')->firstOrFail();
    $foreign = SmartLinkBlock::query()->where('label', 'o')->firstOrFail();

    $this->get('/l/no-existe')->assertNotFound();
    $this->get('/l/mi-marca/go/'.$header->id)->assertNotFound();
    $this->get('/l/mi-marca/go/'.$foreign->id)->assertNotFound(); // bloque de OTRO microsite
    $this->get('/l/mi-marca/go/9999')->assertNotFound();

    SmartLink::withoutUserScope()->where('slug', 'mi-marca')->update(['published' => false]);
    $this->get('/l/mi-marca')->assertNotFound();
});

test('SmartLinks: aislamiento — marca ajena, avatar ajeno y borrado ajeno', function () {
    $other = User::factory()->create();
    $otherBrand = Brand::factory()->for($other)->create();
    $this->actingAs($other)->post(route('smartlinks.save'), slPayload($otherBrand, ['slug' => 'ajeno']));
    $otherLink = SmartLink::withoutUserScope()->where('slug', 'ajeno')->firstOrFail();
    $foreignImage = MediaAsset::factory()->for($other)->create();
    $video = MediaAsset::factory()->for($this->user)->video()->create();

    $this->actingAs($this->user)->post(route('smartlinks.save'), slPayload($this->brand, ['brand_id' => $otherBrand->id]))->assertNotFound();
    $this->actingAs($this->user)->post(route('smartlinks.save'), slPayload($this->brand, ['avatar_media_id' => $foreignImage->id]))->assertSessionHasErrors('avatar_media_id');
    $this->actingAs($this->user)->post(route('smartlinks.save'), slPayload($this->brand, ['avatar_media_id' => $video->id]))->assertSessionHasErrors('avatar_media_id');
    $this->actingAs($this->user)->delete(route('smartlinks.destroy', $otherLink))->assertNotFound();
    $this->actingAs($this->user)->get(route('smartlinks.index'))->assertOk()->assertDontSee('ajeno');

    expect(SmartLink::withoutUserScope()->where('slug', 'ajeno')->exists())->toBeTrue()
        ->and(fn () => app(SmartLinkService::class)->save($this->user, $otherBrand, ['slug' => 'robo', 'title' => 'x']))->toThrow(PostValidationException::class);
});

test('SmartLinks: una marca solo puede tener un microsite y se puede eliminar', function () {
    $this->actingAs($this->user)->post(route('smartlinks.save'), slPayload($this->brand));
    $this->actingAs($this->user)->post(route('smartlinks.save'), slPayload($this->brand, ['slug' => 'otro-slug']))->assertSessionHasNoErrors();
    expect(SmartLink::query()->count())->toBe(1)->and(SmartLink::query()->value('slug'))->toBe('otro-slug');

    $this->actingAs($this->user)->delete(route('smartlinks.destroy', SmartLink::query()->firstOrFail()))->assertRedirect();
    expect(SmartLink::query()->count())->toBe(0)->and(SmartLinkBlock::query()->count())->toBe(0);
    auth()->logout();
    $this->get('/l/otro-slug')->assertNotFound();
});
