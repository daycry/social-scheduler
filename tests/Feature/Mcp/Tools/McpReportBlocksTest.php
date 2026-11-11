<?php

use App\Jobs\GenerateReport;
use App\Models\Brand;
use App\Models\Report;
use App\Models\ReportTemplate;
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
    $this->other = Brand::factory()->for($this->user)->create();
    $this->key = $this->mcpKey($this->user);
});

test('McpReportBlocks: generate_report acepta bloques directos y get_report los devuelve', function () {
    [$err, $r] = $this->callTool($this->key, 'generate_report', [
        'brand_id' => $this->brand->id, 'format' => 'pdf', 'from' => '2026-10-01', 'to' => '2026-10-10',
        'blocks' => [['type' => 'text', 'text' => 'Nota'], ['type' => 'ads']],
    ]);
    expect($err)->toBeFalse()->and($r['blocks'])->toBe(['text', 'ads']);
    Queue::assertPushed(GenerateReport::class, fn ($j) => $j->reportId === $r['id']);

    [, $g] = $this->callTool($this->key, 'get_report', ['report_id' => $r['id']]);
    expect($g['blocks'])->toBe(['text', 'ads']);
});

test('McpReportBlocks: template_id usa los bloques de la plantilla de esa marca y no admite otra marca ni ajena', function () {
    $t = ReportTemplate::create(['user_id' => $this->user->id, 'brand_id' => $this->brand->id, 'name' => 'P', 'blocks' => [['type' => 'kpis', 'title' => null, 'text' => null]], 'range_days' => 30, 'compare' => 'none']);
    [$err, $r] = $this->callTool($this->key, 'generate_report', ['brand_id' => $this->brand->id, 'format' => 'pdf', 'template_id' => $t->id]);
    expect($err)->toBeFalse()->and($r['blocks'])->toBe(['kpis']);

    [$errB] = $this->callTool($this->key, 'generate_report', ['brand_id' => $this->other->id, 'format' => 'pdf', 'template_id' => $t->id]);
    [$errBoth] = $this->callTool($this->key, 'generate_report', ['brand_id' => $this->brand->id, 'format' => 'pdf', 'template_id' => $t->id, 'blocks' => [['type' => 'kpis']]]);

    $stranger = User::factory()->create();
    $foreign = ReportTemplate::create(['user_id' => $stranger->id, 'brand_id' => Brand::factory()->for($stranger)->create()->id, 'name' => 'X', 'blocks' => [['type' => 'kpis', 'title' => null, 'text' => null]], 'range_days' => 30, 'compare' => 'none']);
    [$errF, $msgF] = $this->callTool($this->key, 'generate_report', ['brand_id' => $this->brand->id, 'format' => 'pdf', 'template_id' => $foreign->id]);
    [, $msgN] = $this->callTool($this->key, 'generate_report', ['brand_id' => $this->brand->id, 'format' => 'pdf', 'template_id' => 999999]);

    expect($errB)->toBeTrue()->and($errBoth)->toBeTrue()->and($errF)->toBeTrue()->and($msgF)->toBe($msgN)
        ->and(Report::withoutUserScope()->count())->toBe(1);
});

test('McpReportBlocks: bloques invalidos se rechazan sin encolar', function () {
    [$err] = $this->callTool($this->key, 'generate_report', ['brand_id' => $this->brand->id, 'format' => 'pdf', 'blocks' => [['type' => 'text', 'text' => '']]]);
    expect($err)->toBeTrue()->and(Report::withoutUserScope()->count())->toBe(0);
});
