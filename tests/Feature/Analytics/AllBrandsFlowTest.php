<?php

use App\Analytics\Metric;
use App\Connectors\Network;
use App\Models\AccountSnapshot;
use App\Models\Brand;
use App\Models\SocialAccount;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\McpClient;

uses(McpClient::class);

beforeEach(function () {
    Carbon::setTestNow('2026-11-01 12:00:00');
    $this->user = User::factory()->create();
    $this->a = Brand::factory()->for($this->user)->create(['name' => 'Alfa']);
    $this->b = Brand::factory()->for($this->user)->create(['name' => 'Beta']);
});

function flowAccount(User $user, Brand $brand, Network $network): SocialAccount
{
    return SocialAccount::factory()->for($user)->create(['brand_id' => $brand->id, 'network' => $network]);
}

function flowDays(SocialAccount $a, int $days, array $metrics): void
{
    for ($i = 1; $i <= $days; $i++) {
        AccountSnapshot::withoutUserScope()->create([
            'user_id' => $a->user_id, 'brand_id' => $a->brand_id, 'social_account_id' => $a->id, 'network' => $a->network->value,
            'date' => Carbon::parse('2026-11-01')->subDays($i)->format('Y-m-d'), 'metrics' => array_merge(Metric::blankAccount(), $metrics),
        ]);
    }
}

it('CA-12: dos marcas con snapshots dan la misma suma, null y fila por marca en la web y en el MCP', function () {
    flowDays(flowAccount($this->user, $this->a, Network::Instagram), 10, [Metric::VIEWS => 10, Metric::FOLLOWERS => 100]);
    flowDays(flowAccount($this->user, $this->b, Network::Bluesky), 10, [Metric::FOLLOWERS => 20, Metric::POST_COUNT => 5]);
    $query = ['from' => '2026-10-22', 'to' => '2026-10-31'];

    $web = null;
    $this->actingAs($this->user)->get(route('dashboard.all', $query))->assertOk()
        ->assertInertia(function (Assert $p) use (&$web) {
            $web = $p->toArray()['props']['summary'];
            $p->has('summary.brands', 2)->where('summary.cards.1.total', 100)->where('summary.cards.2.total', null);
        });

    [$err, $mcp] = $this->callTool($this->mcpKey($this->user), 'get_stats', $query);

    expect($err)->toBeFalse()
        ->and(collect($mcp['cards'])->pluck('total', 'key')->all())->toBe(collect($web['cards'])->pluck('total', 'key')->all())
        ->and($mcp['brands'])->toBe($web['brands'])
        ->and(collect($web['cards'])->firstWhere('key', 'followers')['total'])->toBe(120) // stock: ultimo valor de cada cuenta
        ->and(collect(collect($web['cards'])->firstWhere('key', 'views')['by_network'])->firstWhere('network', 'bluesky')['available'])->toBeFalse(); // Bluesky: VIEWS null
});

it('Todas las marcas: las consultas crecen con las marcas, no con los snapshots ni las cuentas (sin N+1 ni hidratacion masiva)', function () {
    $count = function (): int {
        DB::flushQueryLog();
        DB::enableQueryLog();
        $this->actingAs($this->user)->get(route('dashboard.all', ['from' => '2026-10-02', 'to' => '2026-10-31']))->assertOk();

        return count(DB::getQueryLog());
    };

    flowDays(flowAccount($this->user, $this->a, Network::Instagram), 3, [Metric::VIEWS => 1]);
    flowDays(flowAccount($this->user, $this->b, Network::Facebook), 3, [Metric::VIEWS => 1]);
    $base = $count();

    // 10x snapshots y mas cuentas por marca: mismas consultas.
    flowDays(flowAccount($this->user, $this->a, Network::YouTube), 30, [Metric::VIEWS => 1]);
    flowDays(flowAccount($this->user, $this->b, Network::TikTok), 30, [Metric::FOLLOWERS => 1]);
    flowDays(flowAccount($this->user, $this->a, Network::Bluesky), 30, [Metric::FOLLOWERS => 1]);

    expect($count())->toBe($base);
});

it('Todas las marcas: una marca ajena ni se suma ni aparece', function () {
    $other = User::factory()->create();
    flowDays(flowAccount($other, Brand::factory()->for($other)->create(['name' => 'Ajena']), Network::Instagram), 5, [Metric::VIEWS => 1000]);
    flowDays(flowAccount($this->user, $this->a, Network::Instagram), 5, [Metric::VIEWS => 1]);

    $this->actingAs($this->user)->get(route('dashboard.all', ['from' => '2026-10-22', 'to' => '2026-10-31']))
        ->assertInertia(fn (Assert $p) => $p->where('summary.cards.1.total', 5)->has('summary.brands', 2));
});
