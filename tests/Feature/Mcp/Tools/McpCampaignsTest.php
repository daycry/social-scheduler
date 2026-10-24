<?php

use App\Connectors\Network;
use App\Models\Brand;
use App\Models\Campaign;
use App\Models\SocialAccount;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Tests\Support\McpClient;

uses(McpClient::class);

// C-03 (T-28, CA-12/CA-13): paridad del MCP con la web para la campaña del post y list_campaigns.

beforeEach(function () {
    Http::preventStrayRequests();
    Carbon::setTestNow('2026-11-01 08:00:00');
    $this->user = User::factory()->create();
    $this->brand = Brand::factory()->for($this->user)->create(['timezone' => 'Europe/Madrid']);
    $this->other = Brand::factory()->for($this->user)->create();
    $this->fb = SocialAccount::factory()->for($this->user)->create(['brand_id' => $this->brand->id, 'network' => Network::Facebook]);
    $this->mine = Campaign::factory()->create(['brand_id' => $this->brand->id, 'user_id' => $this->user->id, 'name' => 'Mía']);
    $this->foreign = Campaign::factory()->create(['brand_id' => $this->other->id, 'user_id' => $this->user->id, 'name' => 'De otra marca']);
    $this->key = $this->mcpKey($this->user);
});

function campArgs(array $over = []): array
{
    return array_merge([
        'brand_id' => test()->brand->id, 'text' => 'Hola', 'schedule' => false,
        'targets' => [['account_id' => test()->fb->id, 'content_type' => 'facebook_text']],
    ], $over);
}

test('McpCampaigns: create_post, update_post y get_post llevan campaign_id', function () {
    [$err, $created] = $this->callTool($this->key, 'create_post', campArgs(['campaign_id' => $this->mine->id]));

    expect($err)->toBeFalse()->and($created['campaign_id'])->toBe($this->mine->id);

    [$err, $post] = $this->callTool($this->key, 'get_post', ['post_id' => $created['id']]);
    expect($err)->toBeFalse()->and($post['campaign_id'])->toBe($this->mine->id)->and($post['campaign'])->toBe('Mía');

    // Omitido: no se toca. Nulo: se quita.
    [$err, $same] = $this->callTool($this->key, 'update_post', campArgs(['post_id' => $created['id'], 'text' => 'Editado']));
    expect($err)->toBeFalse()->and($same['campaign_id'])->toBe($this->mine->id);

    [$err, $none] = $this->callTool($this->key, 'update_post', campArgs(['post_id' => $created['id'], 'campaign_id' => null]));
    expect($err)->toBeFalse()->and($none['campaign_id'])->toBeNull()->and($none['campaign'])->toBeNull();
});

test('McpCampaigns: una campaña de otra marca o de otro usuario se rechaza como en la web (CA-12)', function () {
    [$err, $msg] = $this->callTool($this->key, 'create_post', campArgs(['campaign_id' => $this->foreign->id]));
    expect($err)->toBeTrue()->and($msg)->toContain('campaign_id');

    $ajena = Campaign::factory()->create();
    [$err, $msg] = $this->callTool($this->key, 'create_post', campArgs(['campaign_id' => $ajena->id]));
    expect($err)->toBeTrue()->and($msg)->toContain('campaign_id');
});

test('McpCampaigns: list_campaigns devuelve solo las de la marca, activas primero', function () {
    Campaign::factory()->create(['brand_id' => $this->brand->id, 'user_id' => $this->user->id, 'name' => 'Antigua', 'archived_at' => now()]);

    [$err, $data] = $this->callTool($this->key, 'list_campaigns', ['brand_id' => $this->brand->id]);

    expect($err)->toBeFalse()
        ->and(collect($data['campaigns'])->pluck('name')->all())->toBe(['Mía', 'Antigua'])
        ->and($data['campaigns'][0])->toMatchArray(['id' => $this->mine->id, 'color' => '#4f46e5', 'archived' => false])
        ->and($data['campaigns'][1]['archived'])->toBeTrue();
});

test('McpCampaigns: list_campaigns de una marca ajena responde como inexistente y la ability brands:read es suficiente', function () {
    $stranger = Brand::factory()->create();
    [$err] = $this->callTool($this->key, 'list_campaigns', ['brand_id' => $stranger->id]);
    expect($err)->toBeTrue();

    $readOnly = $this->mcpKey($this->user, ['brands:read'], 'lectura');
    [$err, $data] = $this->callTool($readOnly, 'list_campaigns', ['brand_id' => $this->brand->id]);
    expect($err)->toBeFalse()->and($data['campaigns'])->toHaveCount(1);

    $noRead = $this->mcpKey($this->user, ['posts:write'], 'sin-lectura');
    [$err] = $this->callTool($noRead, 'list_campaigns', ['brand_id' => $this->brand->id]);
    expect($err)->toBeTrue();
});

test('McpCampaigns: tools/list ofrece list_campaigns y create_post anuncia campaign_id', function () {
    $tools = collect($this->rpc($this->key, 'tools/list')->assertOk()->json('result.tools'));

    expect($tools->pluck('name')->all())->toContain('list_campaigns')
        ->and($tools->firstWhere('name', 'create_post')['inputSchema']['properties'])->toHaveKey('campaign_id');
});
