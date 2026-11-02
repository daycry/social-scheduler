<?php

use App\Analytics\Metric;
use App\Connectors\Network;
use App\Models\AccountSnapshot;
use App\Models\Brand;
use App\Models\SocialAccount;
use App\Models\User;
use Illuminate\Support\Carbon;

beforeEach(function () {
    Carbon::setTestNow('2026-11-01 12:00:00');
    $this->user = User::factory()->create();
    $this->a = Brand::factory()->for($this->user)->create(['name' => '=Alfa']);
    $this->b = Brand::factory()->for($this->user)->create(['name' => 'Beta']);
});

function csvSnap(User $user, Brand $brand, Network $network, string $date, array $metrics): void
{
    $a = SocialAccount::query()->withoutGlobalScopes()->where('brand_id', $brand->id)->where('network', $network->value)->first()
        ?? SocialAccount::factory()->for($user)->create(['brand_id' => $brand->id, 'network' => $network]);
    AccountSnapshot::withoutUserScope()->create([
        'user_id' => $user->id, 'brand_id' => $brand->id, 'social_account_id' => $a->id, 'network' => $network->value,
        'date' => $date, 'metrics' => array_merge(Metric::blankAccount(), $metrics),
    ]);
}

function csvBody($response): string
{
    return $response->streamedContent();
}

it('CSV de analitica de todas las marcas: BOM, ; , cabeceras en espanol, null vacio y fila por marca (CA-13)', function () {
    csvSnap($this->user, $this->a, Network::Instagram, '2026-10-02', [Metric::VIEWS => 10, Metric::FOLLOWERS => 100]);
    csvSnap($this->user, $this->b, Network::Bluesky, '2026-10-02', [Metric::FOLLOWERS => 5]);

    $r = $this->actingAs($this->user)->get(route('exports.analytics', ['from' => '2026-10-01', 'to' => '2026-10-10']));
    $r->assertOk()->assertHeader('X-Content-Type-Options', 'nosniff');
    expect($r->headers->get('Content-Type'))->toContain('text/csv')
        ->and($r->headers->get('Content-Disposition'))->toContain('analitica-todas-las-marcas-2026-10-01-2026-10-10.csv');

    $csv = csvBody($r);
    expect($csv)->toStartWith("\xEF\xBB\xBF\"marca\";\"red\";\"metrica\";\"tipo\";\"valor\";\"anterior\";\"variacion_pct\"\n")
        ->and($csv)->toContain('"Todas las marcas";"Todas";"Visualizaciones";"flujo";10;;')   // null de otras marcas no cuenta; suma 10
        ->and($csv)->toContain('"Todas las marcas";"Todas";"Alcance";"flujo";;;')              // ninguna marca: vacio, nunca 0
        ->and($csv)->toContain('"Todas las marcas";"Todas";"Seguidores";"acumulado";105;;')
        ->and($csv)->toContain('"Todas las marcas";"Bluesky";"Visualizaciones";"flujo";;;')
        ->and($csv)->toContain('"\'=Alfa";"Todas";"Visualizaciones";"flujo";10;;')            // CWE-1236 en el nombre de la marca
        ->and($csv)->toContain('"Beta";"Todas";"Visualizaciones";"flujo";;;');
});

it('CSV de analitica de una marca: sus redes y la comparacion; una marca ajena es 404', function () {
    csvSnap($this->user, $this->b, Network::Instagram, '2026-09-25', [Metric::VIEWS => 5]);
    csvSnap($this->user, $this->b, Network::Instagram, '2026-10-02', [Metric::VIEWS => 10]);

    $r = $this->actingAs($this->user)->get(route('exports.analytics', ['brand_id' => $this->b->id, 'from' => '2026-10-01', 'to' => '2026-10-10', 'compare' => 'previous']));
    $csv = csvBody($r);

    expect($r->headers->get('Content-Disposition'))->toContain('analitica-beta-2026-10-01-2026-10-10.csv')
        ->and($csv)->toContain('"Beta";"Todas";"Visualizaciones";"flujo";10;5;100')
        ->and($csv)->toContain('"Beta";"Instagram";"Visualizaciones";"flujo";10;5;100')
        ->and($csv)->not->toContain('Alfa');

    $other = Brand::factory()->for(User::factory()->create())->create();
    $this->actingAs($this->user)->get(route('exports.analytics', ['brand_id' => $other->id]))->assertNotFound();
});

it('CSV de analitica: solo marcas propias, exige sesion y valida el periodo', function () {
    $otherUser = User::factory()->create();
    csvSnap($otherUser, Brand::factory()->for($otherUser)->create(['name' => 'Ajena']), Network::Instagram, '2026-10-02', [Metric::VIEWS => 999]);
    csvSnap($this->user, $this->b, Network::Instagram, '2026-10-02', [Metric::VIEWS => 1]);

    $csv = csvBody($this->actingAs($this->user)->get(route('exports.analytics', ['from' => '2026-10-01', 'to' => '2026-10-10'])));
    expect($csv)->not->toContain('Ajena')->and($csv)->not->toContain('999');

    $this->actingAs($this->user)->get(route('exports.analytics', ['from' => '2026-10-10', 'to' => '2026-10-01']))->assertStatus(422);
    $this->actingAs($this->user)->get(route('exports.analytics', ['compare' => 'otro']))->assertSessionHasErrors('compare');

    auth()->logout();
    $this->get(route('exports.analytics'))->assertRedirect(route('login'));
});
