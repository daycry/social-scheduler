<?php

use App\Analytics\Metric;
use App\Connectors\Network;
use App\Enums\PostStatus;
use App\Jobs\GenerateReport;
use App\Mcp\Abilities;
use App\Models\AccountSnapshot;
use App\Models\AuditLog;
use App\Models\Brand;
use App\Models\Post;
use App\Models\PostMetricSnapshot;
use App\Models\PostTarget;
use App\Models\Report;
use App\Models\SocialAccount;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\Support\McpClient;

uses(McpClient::class);

beforeEach(function () {
    Http::preventStrayRequests();
    Queue::fake();
    Carbon::setTestNow('2026-11-01 08:00:00');
    $this->user = User::factory()->create();
    $this->brand = Brand::factory()->for($this->user)->create();
    $this->ig = SocialAccount::factory()->for($this->user)->create(['brand_id' => $this->brand->id, 'network' => Network::Instagram]);
    $this->key = $this->mcpKey($this->user);

    $this->other = User::factory()->create();
    $this->otherBrand = Brand::factory()->for($this->other)->create(['name' => 'Marca secreta B']);
    $otherIg = SocialAccount::factory()->for($this->other)->create(['brand_id' => $this->otherBrand->id, 'network' => Network::Instagram]);
    mcpSnap($otherIg, '2026-10-10', [Metric::FOLLOWERS => 99999, Metric::VIEWS => 88888]);
});

function mcpSnap(SocialAccount $account, string $date, array $metrics): void
{
    AccountSnapshot::withoutUserScope()->create([
        'user_id' => $account->user_id, 'brand_id' => $account->brand_id, 'social_account_id' => $account->id,
        'network' => $account->network->value, 'date' => $date, 'metrics' => array_merge(Metric::blankAccount(), $metrics),
    ]);
}

test('McpStats: get_stats devuelve el resumen con comparacion y null = no disponible', function () {
    mcpSnap($this->ig, '2026-10-05', [Metric::FOLLOWERS => 200, Metric::VIEWS => 100]);
    mcpSnap($this->ig, '2026-09-25', [Metric::FOLLOWERS => 180, Metric::VIEWS => 50]);

    [$err, $d] = $this->callTool($this->key, 'get_stats', ['brand_id' => $this->brand->id, 'from' => '2026-10-01', 'to' => '2026-10-10', 'compare' => 'previous']);
    $cards = collect($d['cards'])->keyBy('key');

    expect($err)->toBeFalse()
        ->and($d['period']['from'])->toBe('2026-10-01')
        ->and($cards['views']['total'])->toBe(100)->and($cards['views']['previous'])->toBe(50)
        ->and($cards['reach']['total'])->toBeNull()->and($cards['reach']['available'])->toBeFalse()
        ->and($cards['views'])->not->toHaveKey('series')
        ->and(json_encode($d))->not->toContain('99999');

    [, $withSeries] = $this->callTool($this->key, 'get_stats', ['brand_id' => $this->brand->id, 'from' => '2026-10-01', 'to' => '2026-10-10', 'include_series' => true]);
    expect($withSeries['cards'][0])->toHaveKey('series');
});

test('McpStats: get_stats por red devuelve crecimiento y metricas; red o modo invalidos son errores', function () {
    mcpSnap($this->ig, '2026-10-01', [Metric::FOLLOWERS => 200]);
    mcpSnap($this->ig, '2026-10-05', [Metric::FOLLOWERS => 230]);

    [$err, $d] = $this->callTool($this->key, 'get_stats', ['brand_id' => $this->brand->id, 'network' => 'instagram', 'from' => '2026-10-01', 'to' => '2026-10-10']);
    expect($err)->toBeFalse()->and($d['network']['value'])->toBe('instagram')
        ->and(collect($d['metrics'])->keyBy('key')['profile_views']['total'])->toBeNull();

    [$err] = $this->callTool($this->key, 'get_stats', ['brand_id' => $this->brand->id, 'network' => 'myspace']);
    expect($err)->toBeTrue();

    [$err] = $this->callTool($this->key, 'get_stats', ['brand_id' => $this->brand->id, 'from' => '2026-10-10', 'to' => '2026-10-01']);
    expect($err)->toBeTrue();
});

test('McpStats: aislamiento, una marca ajena responde igual que una inexistente y no filtra datos', function () {
    [$errA, $msgA] = $this->callTool($this->key, 'get_stats', ['brand_id' => $this->otherBrand->id]);
    [$errB, $msgB] = $this->callTool($this->key, 'get_stats', ['brand_id' => 999999]);

    expect($errA)->toBeTrue()->and($errB)->toBeTrue()->and($msgA)->toBe($msgB)->and($msgA)->not->toContain('secreta');

    [$errR] = $this->callTool($this->key, 'generate_report', ['brand_id' => $this->otherBrand->id, 'format' => 'csv']);
    expect($errR)->toBeTrue()->and(Report::withoutUserScope()->count())->toBe(0);
    Queue::assertNothingPushed();
});

test('McpStats: get_post_stats devuelve las ultimas metricas por destino y bloquea posts ajenos', function () {
    $post = Post::factory()->for($this->user)->create(['brand_id' => $this->brand->id]);
    $t = PostTarget::factory()->create([
        'post_id' => $post->id, 'social_account_id' => $this->ig->id, 'network' => Network::Instagram,
        'status' => PostStatus::Published, 'external_id' => 'm1', 'published_at' => '2026-10-03 10:00:00',
    ]);
    foreach (['2026-10-04' => [100, 10], '2026-10-06' => [200, 30]] as $date => [$views, $inter]) {
        PostMetricSnapshot::withoutUserScope()->create(['user_id' => $this->user->id, 'post_target_id' => $t->id, 'social_account_id' => $this->ig->id,
            'network' => 'instagram', 'date' => $date, 'metrics' => [Metric::VIEWS => $views, Metric::INTERACTIONS => $inter]]);
    }

    [$err, $d] = $this->callTool($this->key, 'get_post_stats', ['post_id' => $post->id]);
    expect($err)->toBeFalse()->and($d['targets'][0]['metrics']['views'])->toBe(200)->and($d['targets'][0]['metrics']['reach'])->toBeNull()
        ->and($d['targets'][0]['has_data'])->toBeTrue()->and($d['targets'][0]['engagement_rate'])->toEqual(15);

    $foreign = Post::factory()->for($this->other)->create(['brand_id' => $this->otherBrand->id]);
    [$errF, $msgF] = $this->callTool($this->key, 'get_post_stats', ['post_id' => $foreign->id]);
    [, $msgN] = $this->callTool($this->key, 'get_post_stats', ['post_id' => 999999]);
    expect($errF)->toBeTrue()->and($msgF)->toBe($msgN);
});

test('McpStats: generate_report encola y get_report da estado y enlace firmado solo si esta listo', function () {
    [$err, $r] = $this->callTool($this->key, 'generate_report', ['brand_id' => $this->brand->id, 'format' => 'csv', 'from' => '2026-10-01', 'to' => '2026-10-10', 'compare' => 'previous']);

    expect($err)->toBeFalse()->and($r['status'])->toBe('pending');
    Queue::assertPushed(GenerateReport::class, fn ($j) => $j->reportId === $r['id']);
    $row = Report::withoutUserScope()->findOrFail($r['id']);
    expect($row->user_id)->toBe($this->user->id)->and($row->compare)->toBe('previous');

    [, $pending] = $this->callTool($this->key, 'get_report', ['report_id' => $r['id']]);
    expect($pending['status'])->toBe('pending')->and($pending['download_url'])->toBeNull();

    $row->update(['status' => Report::STATUS_READY, 'path' => "reports/{$this->user->id}/{$row->id}.csv", 'size' => 10]);
    [, $ready] = $this->callTool($this->key, 'get_report', ['report_id' => $r['id']]);
    expect($ready['status'])->toBe('ready')->and($ready['download_url'])->toContain('signature=')->toContain("reports/{$row->id}/download");
});

test('McpStats: get_report de un informe ajeno responde como inexistente y generate_report valida y limita', function () {
    $foreign = Report::factory()->create(['user_id' => $this->other->id, 'brand_id' => $this->otherBrand->id]);

    [$errA, $msgA] = $this->callTool($this->key, 'get_report', ['report_id' => $foreign->id]);
    [, $msgB] = $this->callTool($this->key, 'get_report', ['report_id' => 999999]);
    expect($errA)->toBeTrue()->and($msgA)->toBe($msgB);

    [$err] = $this->callTool($this->key, 'generate_report', ['brand_id' => $this->brand->id, 'format' => 'docx']);
    expect($err)->toBeTrue();

    foreach (range(1, 5) as $i) {
        $this->callTool($this->key, 'generate_report', ['brand_id' => $this->brand->id, 'format' => 'csv']);
    }
    [$errMax, $msg] = $this->callTool($this->key, 'generate_report', ['brand_id' => $this->brand->id, 'format' => 'csv']);
    expect($errMax)->toBeTrue()->and($msg)->toContain('en curso')
        ->and(Report::withoutUserScope()->where('user_id', $this->user->id)->count())->toBe(5);
});

test('McpStats: abilities, sin stats:read o reports:write se deniega y queda auditado; tools/list las incluye', function () {
    $reader = $this->mcpKey($this->user, ['posts:read'], 'lector');

    [$e1, $m1] = $this->callTool($reader, 'get_stats', ['brand_id' => $this->brand->id]);
    [$e2, $m2] = $this->callTool($reader, 'generate_report', ['brand_id' => $this->brand->id, 'format' => 'csv']);
    expect($e1)->toBeTrue()->and($m1)->toContain('stats:read')->and($e2)->toBeTrue()->and($m2)->toContain('reports:write')
        ->and(Report::withoutUserScope()->count())->toBe(0)
        ->and(AuditLog::where('outcome', 'denied')->pluck('action')->all())->toContain('get_stats', 'generate_report');

    $names = collect($this->rpc($this->key, 'tools/list')->json('result.tools'))->pluck('name')->all();
    expect($names)->toContain('get_stats', 'get_post_stats', 'generate_report', 'get_report')
        ->and(Abilities::keys())->toContain('stats:read', 'reports:write');
});

test('McpStats: las llamadas OK quedan auditadas sin volcar datos', function () {
    $this->callTool($this->key, 'get_stats', ['brand_id' => $this->brand->id]);
    $this->callTool($this->key, 'generate_report', ['brand_id' => $this->brand->id, 'format' => 'pdf']);

    expect(AuditLog::where('outcome', 'ok')->pluck('action')->all())->toContain('get_stats', 'generate_report');
});
