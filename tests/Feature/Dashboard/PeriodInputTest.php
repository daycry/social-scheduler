<?php

use App\Models\Brand;
use App\Models\User;
use Tests\Support\McpClient;

uses(McpClient::class);

test('invalid periods use the same field and message in web API and MCP', function (array $period, string $field, string $message) {
    $user = User::factory()->create();
    $brand = Brand::factory()->for($user)->create();
    $range = $period + ['brand_id' => $brand->id];

    $this->actingAs($user);
    foreach ([
        route('dashboard', $range), route('dashboard.network', $range + ['network' => 'instagram']),
        route('dashboard.all', $period), route('exports.analytics', $range),
    ] as $url) {
        $this->getJson($url)->assertUnprocessable()->assertJsonPath("errors.$field.0", $message);
    }
    $this->postJson(route('reports.store'), $range + ['formats' => ['csv']])
        ->assertUnprocessable()->assertJsonPath("errors.$field.0", $message);
    $this->postJson(route('reports.preview'), $range + ['blocks' => [['type' => 'summary']]])
        ->assertUnprocessable()->assertJsonPath("errors.$field.0", $message);

    $key = $this->mcpKey($user, ['stats:read', 'reports:write']);
    app('auth')->forgetGuards();
    $this->withToken($key)->getJson('/api/v1/brands/'.$brand->id.'/analytics?'.http_build_query($period))
        ->assertUnprocessable()->assertJsonPath("errors.$field.0", $message);
    $this->postJson('/api/v1/brands/'.$brand->id.'/reports', $period + ['format' => 'csv'])
        ->assertUnprocessable()->assertJsonPath("errors.$field.0", $message);
    foreach ([['get_stats', $range], ['get_stats', $period], ['generate_report', $range + ['format' => 'csv']]] as [$tool, $arguments]) {
        [$error, $text] = $this->callTool($key, $tool, $arguments);
        expect($error)->toBeTrue()->and($text)->toBe("Datos no validos: $field: $message");
    }
})->with([
    'reversed' => [['from' => '2026-10-10', 'to' => '2026-10-01'], 'from', 'El periodo es invalido: la fecha final es anterior a la inicial.'],
    'impossible date' => [['from' => '2026-10-01', 'to' => '2026-02-30'], 'to', 'La fecha no es válida. Usa AAAA-MM-DD.'],
    'too long' => [['from' => '2024-10-01', 'to' => '2026-10-01'], 'from', 'El periodo no puede superar 366 dias.'],
    'array' => [['from' => ['2026-10-01'], 'to' => '2026-10-02'], 'from', 'La fecha no es válida. Usa AAAA-MM-DD.'],
]);

test('analytics API still requires both dates while a dashboard accepts its default period', function () {
    $user = User::factory()->create();
    $brand = Brand::factory()->for($user)->create();
    $this->actingAs($user)->get(route('dashboard', ['brand_id' => $brand->id]))->assertOk();
    $key = $this->mcpKey($user, ['stats:read']);
    app('auth')->forgetGuards();
    $this->withToken($key)->getJson('/api/v1/brands/'.$brand->id.'/analytics')
        ->assertUnprocessable()->assertJsonValidationErrors(['from', 'to']);
});
