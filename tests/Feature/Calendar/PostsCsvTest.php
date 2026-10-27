<?php

use App\Analytics\Metric;
use App\Connectors\Network;
use App\Models\Brand;
use App\Models\CalendarView;
use App\Models\MediaAsset;
use App\Models\Post;
use App\Models\PostMetricSnapshot;
use App\Models\SocialAccount;
use App\Models\User;
use App\Services\PostService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    Carbon::setTestNow('2026-10-25 12:00:00');
    $this->user = User::factory()->create();
    $this->brandA = Brand::factory()->for($this->user)->create(['name' => 'Marca A', 'timezone' => 'Europe/Madrid']);
    $this->brandB = Brand::factory()->for($this->user)->create(['name' => 'Marca B', 'timezone' => 'Europe/Madrid']);
    $this->igA = SocialAccount::factory()->for($this->user)->create(['brand_id' => $this->brandA->id, 'network' => Network::Instagram, 'name' => 'ig_a']);
    $this->fbA = SocialAccount::factory()->for($this->user)->create(['brand_id' => $this->brandA->id, 'network' => Network::Facebook, 'name' => 'fb_a']);
    $this->igB = SocialAccount::factory()->for($this->user)->create(['brand_id' => $this->brandB->id, 'network' => Network::Instagram, 'name' => 'ig_b']);
    $this->image = MediaAsset::factory()->for($this->user)->create();
});

function csvPost(string $local, Brand $brand, SocialAccount $account, string $text, string $type = 'instagram_image'): Post
{
    return app(PostService::class)->create($brand->user, $brand, [
        'text' => $text, 'scheduled_at' => $local, 'schedule' => true,
        'media' => $type === 'facebook_text' ? [] : [MediaAsset::factory()->for($brand->user)->create()->id],
        'targets' => [['account_id' => $account->id, 'content_type' => $type]],
    ]);
}

function csvPostBody($response): string
{
    return $response->streamedContent();
}

it('CSV de publicaciones: contiene exactamente las publicaciones del filtro, con BOM, ; y texto neutralizado (CA-13)', function () {
    csvPost('2026-11-03 10:00', $this->brandA, $this->igA, '=HYPERLINK("http://x")');
    csvPost('2026-11-04 10:00', $this->brandB, $this->igB, 'De la marca B');
    csvPost('2026-11-05 10:00', $this->brandA, $this->fbA, 'Facebook A', 'facebook_text');
    csvPost('2027-03-01 10:00', $this->brandA, $this->igA, 'Fuera del rango');

    $query = ['view' => 'list', 'date' => '2026-11-01', 'brand_id' => $this->brandA->id, 'network' => 'instagram'];
    $r = $this->actingAs($this->user)->get(route('exports.posts', $query));
    $r->assertOk();
    $csv = csvPostBody($r);

    expect($csv)->toStartWith("\xEF\xBB\xBF\"fecha\";\"marca\";\"red\";\"cuenta\";\"tipo\";\"estado\";\"texto\";\"enlace\";\"visualizaciones\"")
        ->and($csv)->toContain('"2026-11-03 10:00";"Marca A";"Instagram";"ig_a"')
        ->and($csv)->toContain("\"'=HYPERLINK(\"\"http://x\"\")\"")
        ->and($csv)->not->toContain('De la marca B')->and($csv)->not->toContain('Facebook A')->and($csv)->not->toContain('Fuera del rango')
        ->and(substr_count($csv, "\n"))->toBe(2); // cabecera + 1 fila
    expect($r->headers->get('Content-Disposition'))->toContain('publicaciones-2026-11-01.csv');
});

it('CSV de publicaciones: lleva las metricas del ultimo snapshot y un null sale vacio, nunca 0', function () {
    $post = csvPost('2026-11-10 10:00', $this->brandA, $this->igA, 'Con metricas');
    $post->targets->each(fn ($t) => $t->forceFill(['status' => 'publicada', 'published_at' => '2026-11-10 08:00:00', 'external_url' => 'https://ig.example/p/1'])->save());
    $t = $post->targets->first();
    foreach (['2026-10-21' => [Metric::VIEWS => 5], '2026-10-23' => [Metric::VIEWS => 50, Metric::INTERACTIONS => 7]] as $date => $m) {
        PostMetricSnapshot::withoutUserScope()->create(['user_id' => $this->user->id, 'post_target_id' => $t->id, 'social_account_id' => $this->igA->id, 'network' => 'instagram', 'date' => $date, 'metrics' => $m]);
    }

    $csv = csvPostBody($this->actingAs($this->user)->get(route('exports.posts', ['view' => 'list', 'date' => '2026-11-01'])));

    // visualizaciones 50 (ultimo snapshot), alcance vacio (null), ..., interacciones 7
    expect($csv)->toContain('"Con metricas";"https://ig.example/p/1";50;;;;;;7')
        ->and($csv)->not->toContain(';0;');
});

it('CSV de publicaciones: respeta una vista guardada, avisa del tope y solo exporta datos propios', function () {
    foreach (range(1, 4) as $i) {
        csvPost("2026-11-0{$i} 10:00", $this->brandA, $this->igA, "Post $i");
    }
    csvPost('2026-11-02 11:00', $this->brandB, $this->igB, 'Otra marca');
    $other = User::factory()->create();
    $otherBrand = Brand::factory()->for($other)->create(['name' => 'Ajena']);
    csvPost('2026-11-02 12:00', $otherBrand, SocialAccount::factory()->for($other)->create(['brand_id' => $otherBrand->id, 'network' => Network::Instagram]), 'Secreto ajeno');

    $view = CalendarView::factory()->for($this->user)->create(['name' => 'Solo A', 'filters' => ['brand_id' => $this->brandA->id, 'view' => 'list']]);
    $csv = csvPostBody($this->actingAs($this->user)->get(route('exports.posts', ['saved_view' => $view->id, 'date' => '2026-11-01'])));
    expect($csv)->toContain('Post 4')->and($csv)->not->toContain('Otra marca')->and($csv)->not->toContain('Secreto ajeno');

    config(['social.export.max_rows' => 2]);
    $r = $this->actingAs($this->user)->get(route('exports.posts', ['view' => 'list', 'date' => '2026-11-01', 'brand_id' => $this->brandA->id]));
    $csv = csvPostBody($r);
    expect(substr_count($csv, "\n"))->toBe(3) // cabecera + 2 filas del tope
        ->and($r->headers->get('X-Export-Truncated'))->toBe('1')->and($r->headers->get('X-Export-Total'))->toBe('4');

    // La vista Lista informa del total y del tope para avisar ANTES de descargar.
    $this->actingAs($this->user)->get(route('calendar.index', ['view' => 'list', 'date' => '2026-11-01', 'brand_id' => $this->brandA->id]))
        ->assertInertia(fn (Assert $p) => $p->where('export.total', 4)->where('export.max', 2)->where('export.truncated', true));

    // Una vista guardada ajena es 404.
    $foreign = CalendarView::factory()->for($other)->create(['name' => 'Ajena', 'filters' => []]);
    $this->actingAs($this->user)->get(route('exports.posts', ['saved_view' => $foreign->id]))->assertNotFound();
});

it('CSV de publicaciones: el volumen no hace crecer las consultas (sin N+1) y exige sesion', function () {
    $count = function () {
        DB::flushQueryLog();
        DB::enableQueryLog();
        csvPostBody($this->actingAs($this->user)->get(route('exports.posts', ['view' => 'list', 'date' => '2026-11-01'])));

        return count(DB::getQueryLog());
    };

    csvPost('2026-11-03 10:00', $this->brandA, $this->igA, 'Uno');
    $base = $count();
    foreach (range(4, 9) as $d) {
        csvPost("2026-11-0{$d} 10:00", $this->brandA, $this->igA, "Post $d");
    }
    expect($count())->toBe($base);

    auth()->logout();
    $this->get(route('exports.posts'))->assertRedirect(route('login'));
    $this->actingAs($this->user)->get(route('exports.posts', ['network' => 'myspace']))->assertNotFound();
});
