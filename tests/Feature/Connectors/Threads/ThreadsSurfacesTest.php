<?php

use App\Analytics\Metric;
use App\Analytics\Period;
use App\Connectors\ConnectorRegistry;
use App\Connectors\Network;
use App\Exceptions\PostValidationException;
use App\Models\AccountSnapshot;
use App\Models\Brand;
use App\Models\Post;
use App\Models\SocialAccount;
use App\Models\User;
use App\Services\PostService;
use App\Services\Reports\PdfRenderer;
use App\Services\ReportService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\McpClient;
use Tests\Support\ThreadsFake;

uses(McpClient::class);

// C-02, CA-10: Threads aparece en TODAS las superficies (compositor, calendario, dashboard, informes y MCP) y desaparece de todas
// con THREADS_ENABLED=false o sin THREADS_APP_ID. El oraculo general es NetworkCoverageTest.

beforeEach(function () {
    Http::preventStrayRequests();
    Carbon::setTestNow('2026-11-01 08:00:00');
    ThreadsFake::configure();
    app()->forgetInstance(ConnectorRegistry::class);
    $this->user = User::factory()->create();
    $this->brand = Brand::factory()->for($this->user)->create(['timezone' => 'Europe/Madrid']);
    $this->th = ThreadsFake::account(['name' => 'Ana Threads'], $this->user, $this->brand);
    $this->ig = SocialAccount::factory()->for($this->user)->create(['brand_id' => $this->brand->id, 'network' => Network::Instagram]);
    $this->key = $this->mcpKey($this->user);
});

function thsOff(): void
{
    config(['social.threads.enabled' => false]);
    app()->forgetInstance(ConnectorRegistry::class);
}

test('el catalogo ofrece Threads con su etiqueta, color y conexion OAuth', function () {
    expect(Network::offered())->toContain(Network::Threads)
        ->and(Network::Threads->toArray())->toBe([
            'value' => 'threads', 'label' => 'Threads', 'short_label' => 'TH', 'color' => '#000000', 'provider' => 'threads', 'auth_kind' => 'oauth',
        ]);
});

test('el compositor ofrece los cuatro tipos de Threads con su limite de 500 y el modo de conteo', function () {
    $this->actingAs($this->user)->get(route('brands.posts.create', $this->brand))
        ->assertInertia(fn (Assert $page) => $page
            ->has('contentTypes.threads', 4)
            ->where('contentTypes.threads.0.value', 'threads_text')
            ->where('limits.threads_text', ['max_chars' => 500, 'max_hashtags' => null, 'count' => 'threads', 'advise_hashtags' => 1])
            ->where('limits.threads_carousel.max_chars', 500)
            ->where('accounts', fn ($accounts) => collect($accounts)->firstWhere('network', 'threads')['available'] === true));
});

test('el calendario, el dashboard y las pestanas listan Threads entre las redes ofrecidas', function () {
    $this->actingAs($this->user)->get(route('calendar.index', ['brand_id' => $this->brand->id]))
        ->assertInertia(fn (Assert $page) => $page->where('networks', fn ($n) => collect($n)->pluck('value')->contains('threads')));

    $this->actingAs($this->user)->get(route('dashboard.network', ['network' => 'threads']))->assertOk();
});

test('el MCP lista la cuenta con sus tipos y create_post valida los 500 caracteres contando los emojis por bytes', function () {
    [$err, $data] = $this->callTool($this->key, 'list_accounts', ['brand_id' => $this->brand->id]);
    $threads = collect($data['accounts'])->firstWhere('network', 'threads');
    expect($err)->toBeFalse()->and($threads['available'])->toBeTrue()
        ->and($threads['content_types'])->toBe(['threads_text', 'threads_image', 'threads_video', 'threads_carousel']);

    $args = fn (string $text) => [
        'brand_id' => $this->brand->id, 'text' => $text, 'scheduled_at' => '2026-11-02 10:00', 'schedule' => true,
        'targets' => [['account_id' => $this->th->id, 'content_type' => 'threads_text']],
    ];

    [$err] = $this->callTool($this->key, 'create_post', $args(str_repeat('a', 500)));
    expect($err)->toBeFalse();
    [$err, $msg] = $this->callTool($this->key, 'create_post', $args(str_repeat('a', 501)));
    expect($err)->toBeTrue()->and($msg)->toContain('501')->toContain('Threads admite como maximo 500');
    // 126 cohetes = 504 bytes: no caben aunque sean 126 caracteres.
    [$err, $msg] = $this->callTool($this->key, 'create_post', $args(str_repeat("\u{1F680}", 126)));
    expect($err)->toBeTrue()->and($msg)->toContain('504');
});

test('el informe CSV incluye Threads: interacciones y seguidores, y las visualizaciones como no disponibles', function () {
    AccountSnapshot::withoutUserScope()->create([
        'user_id' => $this->user->id, 'brand_id' => $this->brand->id, 'social_account_id' => $this->th->id, 'network' => 'threads', 'date' => '2026-10-02',
        'metrics' => array_merge(Metric::blankAccount(), [Metric::PROFILE_VIEWS => 40, Metric::INTERACTIONS => 18, Metric::FOLLOWERS => 321]),
    ]);

    $csv = $this->actingAs($this->user)->get(route('exports.analytics', ['brand_id' => $this->brand->id, 'from' => '2026-10-01', 'to' => '2026-10-10']))->streamedContent();

    expect($csv)->toContain('"Threads";"Interacciones";"flujo";18;')
        ->and($csv)->toContain('"Threads";"Seguidores";"acumulado";321;')
        // Threads no da visualizaciones de cuenta (sus 40 son visitas al perfil): vacio, nunca 0 ni las visitas.
        ->and($csv)->toContain('"Threads";"Visualizaciones";"flujo";;;')
        ->and($csv)->toContain('"Todas";"Visualizaciones";"flujo";;;');
});

test('el informe (PDF y CSV) incluye Threads y Bluesky con sus metricas; lo que no dan queda como no disponible', function () {
    $bsky = SocialAccount::factory()->for($this->user)->create(['brand_id' => $this->brand->id, 'network' => Network::Bluesky]);
    foreach ([[$this->th, 'threads', [Metric::PROFILE_VIEWS => 40, Metric::LIKES => 12, Metric::FOLLOWERS => 321]], [$bsky, 'bluesky', [Metric::FOLLOWERS => 55, Metric::POST_COUNT => 9]]] as [$account, $network, $metrics]) {
        AccountSnapshot::withoutUserScope()->create([
            'user_id' => $this->user->id, 'brand_id' => $this->brand->id, 'social_account_id' => $account->id, 'network' => $network, 'date' => '2026-10-20',
            'metrics' => array_merge(Metric::blankAccount(), $metrics),
        ]);
    }

    $service = app(ReportService::class);
    $period = Period::make('2026-10-01', '2026-10-31');
    $data = $service->dataset($this->user, $this->brand, $period);

    $threads = collect($data['networks'])->firstWhere('value', 'threads');
    $byKey = collect($threads['metrics'])->keyBy('key');
    expect(collect($data['networks'])->pluck('value')->all())->toContain('threads', 'bluesky')
        ->and($byKey['profile_views']['available'])->toBeTrue()->and($byKey['likes']['available'])->toBeTrue()
        ->and($byKey->has('views'))->toBeFalse();

    $html = view('reports.brand', ['data' => $data, 'branding' => ['color' => '#112233', 'logo' => null, 'footer' => ''], 'sections' => PdfRenderer::SECTIONS])->render();
    expect($html)->toContain('Threads')->toContain('Bluesky')->toContain('Visitas al perfil');

    $csv = $service->csv($data);
    expect($csv)->toContain('Threads')->toContain('Bluesky');
});

test('Conexiones lista Threads como red OAuth disponible', function () {
    $this->actingAs($this->user)->get(route('brands.connections.index', $this->brand))
        ->assertInertia(fn (Assert $page) => $page->where('providers', fn ($p) => collect($p)->firstWhere('network', 'threads')['available'] === true));
});

test('con THREADS_ENABLED=false Threads desaparece de Conexiones, del compositor, de los filtros y del MCP', function () {
    thsOff();

    $this->actingAs($this->user)->get(route('brands.connections.index', $this->brand))
        ->assertInertia(fn (Assert $page) => $page->where('providers', fn ($p) => collect($p)->firstWhere('network', 'threads') === null));

    $this->actingAs($this->user)->get(route('brands.posts.create', $this->brand))
        ->assertInertia(fn (Assert $page) => $page
            ->where('contentTypes.threads', [])
            ->missing('limits.threads_text')
            ->where('accounts', fn ($a) => collect($a)->firstWhere('network', 'threads')['available'] === false));

    $this->actingAs($this->user)->get(route('calendar.index', ['brand_id' => $this->brand->id]))
        ->assertInertia(fn (Assert $page) => $page->where('networks', fn ($n) => ! collect($n)->pluck('value')->contains('threads')));

    [, $data] = $this->callTool($this->key, 'list_accounts', ['brand_id' => $this->brand->id]);
    $threads = collect($data['accounts'])->firstWhere('network', 'threads');
    expect($threads['available'])->toBeFalse()->and($threads['content_types'])->toBe([]);

    [$err, $msg] = $this->callTool($this->key, 'create_post', [
        'brand_id' => $this->brand->id, 'text' => 'Hola', 'scheduled_at' => '2026-11-02 10:00', 'schedule' => true,
        'targets' => [['account_id' => $this->th->id, 'content_type' => 'threads_text']],
    ]);
    expect($err)->toBeTrue()->and($msg)->toContain('Threads no esta disponible');
    expect(Post::withoutUserScope()->count())->toBe(0);
});

test('sin THREADS_APP_ID la red tambien desaparece (no configurada)', function () {
    config(['services.threads.client_id' => '']);
    app()->forgetInstance(ConnectorRegistry::class);

    expect(Network::offered())->not->toContain(Network::Threads);
    [, $data] = $this->callTool($this->key, 'list_accounts', ['brand_id' => $this->brand->id]);
    expect(collect($data['accounts'])->firstWhere('network', 'threads')['available'])->toBeFalse();
});

test('reschedule rechaza un destino de Threads si la red esta apagada y no mueve la fecha', function () {
    $post = app(PostService::class)->create($this->user, $this->brand, [
        'text' => 'Hola', 'targets' => [['account_id' => $this->th->id, 'content_type' => 'threads_text']],
    ]);
    thsOff();

    expect(fn () => app(PostService::class)->reschedule($this->user, $post, '2026-11-05 18:30'))
        ->toThrow(PostValidationException::class, 'Threads no esta disponible');
    expect($post->fresh()->scheduled_at)->toBeNull();

    config(['social.threads.enabled' => true]);
    expect(app(PostService::class)->reschedule($this->user, $post, '2026-11-05 18:30')->scheduled_at)->not->toBeNull();
});

test('regresion: las otras redes siguen igual con Threads apagado', function () {
    thsOff();

    [, $data] = $this->callTool($this->key, 'list_accounts', ['brand_id' => $this->brand->id]);
    $ig = collect($data['accounts'])->firstWhere('network', 'instagram');

    expect($ig['available'])->toBeTrue()->and($ig['content_types'])->toContain('instagram_image')
        ->and(Network::offered())->toContain(Network::Instagram)->and(Network::listing())->not->toContain('threads');
});
