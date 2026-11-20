<?php

use App\Connectors\Network;
use App\Mcp\Abilities;
use App\Models\Autolist;
use App\Models\Brand;
use App\Models\Competitor;
use App\Models\ComposerPreset;
use App\Models\InboxComment;
use App\Models\Post;
use App\Models\SavedText;
use App\Models\SmartLink;
use App\Models\SocialAccount;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\Support\BlueskyFake;
use Tests\Support\FakeGraph;
use Tests\Support\McpClient;

uses(McpClient::class);

// T-30 · herramientas MCP finas sobre los servicios: SmartLinks, Ads, autolistas, competidores, ocultar comentarios, textos guardados,
// presets y papelera. Cada una: exito, otro usuario, otra marca, ability ausente y validacion. Todo con fakes, sin red.

beforeEach(function () {
    Carbon::setTestNow('2026-11-01 12:00:00');
    Cache::flush();
    Http::preventStrayRequests();
    $this->user = User::factory()->create();
    $this->other = User::factory()->create();
    $this->brand = Brand::factory()->for($this->user)->create(['name' => 'Mi marca', 'timezone' => 'UTC']);
    $this->foreignBrand = Brand::factory()->for($this->other)->create(['name' => 'Ajena']);
    $this->ig = SocialAccount::factory()->for($this->user)->create(['brand_id' => $this->brand->id, 'network' => Network::Instagram]);
    $this->key = $this->mcpKey($this->user);
    $this->otherKey = $this->mcpKey($this->other);
    $this->none = $this->mcpKey($this->user, ['brands:read']);
});

// ------------------------------------------------------------------------------------------------------------ smartlink

test('manage_smartlink: save, get y stats con las abilities smartlinks', function () {
    $writer = $this->mcpKey($this->user, ['smartlinks:write']);
    $reader = $this->mcpKey($this->user, ['smartlinks:read']);
    $args = ['brand_id' => $this->brand->id, 'slug' => 'mi-marca', 'title' => 'Mi marca', 'published' => true, 'blocks' => [['type' => 'link', 'label' => 'Web', 'url' => 'https://example.com']]];

    [$err, $saved] = $this->callTool($writer, 'manage_smartlink', ['action' => 'save'] + $args);
    expect($err)->toBeFalse()->and($saved)->toMatchArray(['slug' => 'mi-marca', 'title' => 'Mi marca', 'published' => true])->and($saved['blocks'])->toHaveCount(1);

    [$err, $got] = $this->callTool($reader, 'manage_smartlink', ['action' => 'get', 'brand_id' => $this->brand->id]);
    expect($err)->toBeFalse()->and($got['id'])->toBe($saved['id'])->and($got['public_url'])->toContain('mi-marca');

    [$err, $stats] = $this->callTool($reader, 'manage_smartlink', ['action' => 'stats', 'brand_id' => $this->brand->id, 'days' => 7]);
    expect($err)->toBeFalse()->and($stats)->toHaveKey('views');

    [$err, $replaced] = $this->callTool($writer, 'manage_smartlink', ['action' => 'save', 'title' => 'Nuevo'] + $args);
    expect($err)->toBeFalse()->and($replaced['id'])->toBe($saved['id'])->and(SmartLink::withoutUserScope()->where('brand_id', $this->brand->id)->count())->toBe(1);
});

test('manage_smartlink: otra marca, otro usuario, ability ausente y validacion', function () {
    $writer = $this->mcpKey($this->user, ['smartlinks:write']);
    $reader = $this->mcpKey($this->user, ['smartlinks:read']);
    $args = ['brand_id' => $this->brand->id, 'slug' => 'mi-marca', 'title' => 'Mi marca'];

    [$err, $msg] = $this->callTool($this->key, 'manage_smartlink', ['action' => 'get', 'brand_id' => $this->foreignBrand->id]);
    expect($err)->toBeTrue()->and($msg)->toContain('Marca no encontrada');
    [$err] = $this->callTool($this->otherKey, 'manage_smartlink', ['action' => 'save'] + $args);
    expect($err)->toBeTrue()->and(SmartLink::withoutUserScope()->count())->toBe(0);

    [$err, $msg] = $this->callTool($reader, 'manage_smartlink', ['action' => 'save'] + $args);
    expect($err)->toBeTrue()->and($msg)->toContain('smartlinks:write');
    [$err, $msg] = $this->callTool($writer, 'manage_smartlink', ['action' => 'get', 'brand_id' => $this->brand->id]);
    expect($err)->toBeTrue()->and($msg)->toContain('smartlinks:read');
    [$err, $msg] = $this->callTool($this->none, 'manage_smartlink', ['action' => 'get', 'brand_id' => $this->brand->id]);
    expect($err)->toBeTrue()->and($msg)->toContain('smartlinks:read');

    [$err, $msg] = $this->callTool($writer, 'manage_smartlink', ['action' => 'save', 'brand_id' => $this->brand->id, 'slug' => 'A', 'title' => '']);
    expect($err)->toBeTrue()->and($msg)->toContain('slug')->and($msg)->toContain('title');
    [$err, $msg] = $this->callTool($reader, 'manage_smartlink', ['action' => 'get', 'brand_id' => $this->brand->id]);
    expect($err)->toBeTrue()->and($msg)->toContain('no tiene SmartLink');
    [$err] = $this->callTool($reader, 'manage_smartlink', ['action' => 'stats', 'brand_id' => $this->brand->id, 'days' => 0]);
    expect($err)->toBeTrue();
});

// ----------------------------------------------------------------------------------------------------------------- ads

function mcpAdsFake(FakeGraph $g): void
{
    $g->on('GET', '#^me/adaccounts$#', ['data' => [['id' => 'act_111', 'name' => 'Cuenta A', 'currency' => 'EUR', 'account_status' => 1]]]);
    $g->on('GET', '#^act_\d+/campaigns$#', ['data' => [['id' => '9001', 'name' => 'Rebajas', 'status' => 'ACTIVE', 'objective' => 'OUTCOME_TRAFFIC']]]);
    $g->on('GET', '#^act_\d+/insights$#', ['data' => [['spend' => '12.50', 'impressions' => '1000', 'clicks' => '40', 'ctr' => '4.0', 'cpc' => '0.31', 'campaign_id' => '9001']]]);
}

test('get_ads: cuentas, campanas y metricas de la marca, solo con GET', function () {
    SocialAccount::factory()->for($this->user)->create(['brand_id' => $this->brand->id, 'network' => Network::Facebook, 'access_token' => 'PAGE', 'refresh_token' => 'USER-TOKEN', 'scopes' => ['pages_show_list', 'ads_read']]);
    $graph = new FakeGraph;
    mcpAdsFake($graph);

    [$err, $res] = $this->callTool($this->mcpKey($this->user, ['stats:read']), 'get_ads', ['brand_id' => $this->brand->id, 'days' => 7]);

    expect($err)->toBeFalse()->and($res['available'])->toBeTrue()->and($res['selected']['id'])->toBe('act_111')->and($res['period'])->toBe(7)
        ->and($res['campaigns'][0]['name'])->toBe('Rebajas')->and($res['totals']['clicks'])->toBe(40);
    foreach ($graph->calls as $call) {
        expect($call['method'])->toBe('GET');
    }
});

test('get_ads: sin Meta conectada available es false; otra marca, ability y validacion', function () {
    [$err, $res] = $this->callTool($this->key, 'get_ads', ['brand_id' => $this->brand->id]);
    expect($err)->toBeFalse()->and($res['available'])->toBeFalse()->and($res['reason'])->toBe('sin_permiso');

    [$err, $msg] = $this->callTool($this->key, 'get_ads', ['brand_id' => $this->foreignBrand->id]);
    expect($err)->toBeTrue()->and($msg)->toContain('Marca no encontrada');
    [$err, $msg] = $this->callTool($this->none, 'get_ads', ['brand_id' => $this->brand->id]);
    expect($err)->toBeTrue()->and($msg)->toContain('stats:read');
    [$err] = $this->callTool($this->key, 'get_ads', ['brand_id' => $this->brand->id, 'days' => 5]);
    expect($err)->toBeTrue();
    [$err] = $this->callTool($this->key, 'get_ads', ['brand_id' => $this->brand->id, 'ad_account' => 'otra']);
    expect($err)->toBeTrue();
});

// ------------------------------------------------------------------------------------------------------------- autolist

function mcpAutolist(array $over = []): array
{
    return $over + ['action' => 'create', 'brand_id' => test()->brand->id, 'name' => 'Semanal', 'targets' => [['account_id' => test()->ig->id, 'content_type' => 'instagram_image']], 'slots' => [['day' => 2, 'time' => '10:00']]];
}

test('manage_autolist: crear, listar con cursor, actualizar, anadir y quitar elementos, generar y borrar', function () {
    [$err, $created] = $this->callTool($this->key, 'manage_autolist', mcpAutolist());
    expect($err)->toBeFalse()->and($created)->toMatchArray(['name' => 'Semanal', 'active' => true, 'brand_id' => $this->brand->id]);
    $this->callTool($this->key, 'manage_autolist', mcpAutolist(['name' => 'Otra']));

    [, $p1] = $this->callTool($this->key, 'manage_autolist', ['action' => 'list', 'limit' => 1]);
    expect($p1['autolists'])->toHaveCount(1)->and($p1['has_more'])->toBeTrue();
    [, $p2] = $this->callTool($this->key, 'manage_autolist', ['action' => 'list', 'limit' => 1, 'cursor' => $p1['next_cursor']]);
    expect($p2['autolists'])->toHaveCount(1)->and($p2['has_more'])->toBeFalse()->and($p2['next_cursor'])->toBeNull()
        ->and($p2['autolists'][0]['id'])->not->toBe($p1['autolists'][0]['id']);

    [$err, $paused] = $this->callTool($this->key, 'manage_autolist', ['action' => 'update', 'autolist_id' => $created['id'], 'active' => false, 'recycle' => true]);
    expect($err)->toBeFalse()->and($paused['active'])->toBeFalse()->and($paused['recycle'])->toBeTrue();

    [$err, $withItem] = $this->callTool($this->key, 'manage_autolist', ['action' => 'add_item', 'autolist_id' => $created['id'], 'text' => 'Hola cola']);
    expect($err)->toBeFalse()->and($withItem['items'])->toHaveCount(1)->and($withItem['items'][0]['label'])->toBe('Hola cola');
    [$err, $emptied] = $this->callTool($this->key, 'manage_autolist', ['action' => 'remove_item', 'autolist_id' => $created['id'], 'item_id' => $withItem['items'][0]['id']]);
    expect($err)->toBeFalse()->and($emptied['items'])->toBe([]);

    [$err, $gen] = $this->callTool($this->key, 'manage_autolist', ['action' => 'generate', 'autolist_id' => $created['id']]);
    expect($err)->toBeFalse()->and($gen)->toHaveKeys(['created', 'skipped']);

    [$err, $del] = $this->callTool($this->key, 'manage_autolist', ['action' => 'delete', 'autolist_id' => $created['id']]);
    expect($err)->toBeFalse()->and($del['deleted'])->toBeTrue()->and(Autolist::withoutUserScope()->count())->toBe(1);
});

test('manage_autolist: otro usuario, otra marca, ability ausente y validacion', function () {
    [, $created] = $this->callTool($this->key, 'manage_autolist', mcpAutolist());
    $reader = $this->mcpKey($this->user, ['posts:read']);

    [$err, $msg] = $this->callTool($this->otherKey, 'manage_autolist', ['action' => 'delete', 'autolist_id' => $created['id']]);
    [$errMissing, $msgMissing] = $this->callTool($this->otherKey, 'manage_autolist', ['action' => 'delete', 'autolist_id' => 999999]);
    expect($err)->toBeTrue()->and($errMissing)->toBeTrue()->and($msg)->toBe($msgMissing)->and(Autolist::withoutUserScope()->count())->toBe(1);
    [, $otherList] = $this->callTool($this->otherKey, 'manage_autolist', ['action' => 'list']);
    expect($otherList['autolists'])->toBe([]);

    [$err] = $this->callTool($this->key, 'manage_autolist', mcpAutolist(['brand_id' => $this->foreignBrand->id]));
    expect($err)->toBeTrue()->and(Autolist::withoutUserScope()->count())->toBe(1);

    [$err, $msg] = $this->callTool($reader, 'manage_autolist', ['action' => 'update', 'autolist_id' => $created['id'], 'active' => false]);
    expect($err)->toBeTrue()->and($msg)->toContain('posts:write');
    [$err] = $this->callTool($reader, 'manage_autolist', ['action' => 'list']);
    expect($err)->toBeFalse();
    [$err, $msg] = $this->callTool($this->none, 'manage_autolist', ['action' => 'list']);
    expect($err)->toBeTrue()->and($msg)->toContain('posts:read');

    [$err, $msg] = $this->callTool($this->key, 'manage_autolist', mcpAutolist(['slots' => [['day' => 9, 'time' => '25:00']]]));
    expect($err)->toBeTrue()->and($msg)->toContain('slots');
    [$err] = $this->callTool($this->key, 'manage_autolist', ['action' => 'update', 'autolist_id' => $created['id']]);
    expect($err)->toBeTrue();
    [$err] = $this->callTool($this->key, 'manage_autolist', ['action' => 'add_item', 'autolist_id' => $created['id']]);
    expect($err)->toBeTrue();
    [$err] = $this->callTool($this->key, 'manage_autolist', ['action' => 'list', 'cursor' => 'basura']);
    expect($err)->toBeTrue();
});

// ---------------------------------------------------------------------------------------------------------- competitors

test('manage_competitor: alta por Bluesky y baja; otro usuario, otra marca, ability y validacion', function () {
    BlueskyFake::dns();
    config(['social.bluesky.enabled' => true]);
    Http::fake(['*/xrpc/app.bsky.actor.getProfile*' => Http::response(['did' => 'did:plc:rival1234567890abcdefgh', 'handle' => 'rival.bsky.social', 'displayName' => 'El Rival', 'followersCount' => 1500, 'followsCount' => 20, 'postsCount' => 320])]);
    $reader = $this->mcpKey($this->user, ['stats:read']);
    $poster = $this->mcpKey($this->user, ['posts:write']);

    [$err, $added] = $this->callTool($this->key, 'manage_competitor', ['action' => 'add', 'brand_id' => $this->brand->id, 'network' => 'bluesky', 'username' => '@Rival.Bsky.Social']);
    expect($err)->toBeFalse()->and($added)->toMatchArray(['network' => 'bluesky', 'username' => 'rival.bsky.social', 'brand_id' => $this->brand->id]);

    [$err, $msg] = $this->callTool($reader, 'manage_competitor', ['action' => 'add', 'brand_id' => $this->brand->id, 'network' => 'bluesky', 'username' => 'otro.bsky.social']);
    expect($err)->toBeTrue()->and($msg)->toContain('competitors:write');
    [$err, $msg] = $this->callTool($poster, 'manage_competitor', ['action' => 'add', 'brand_id' => $this->brand->id, 'network' => 'bluesky', 'username' => 'otro.bsky.social']);
    expect($err)->toBeTrue()->and($msg)->toContain('competitors:write');
    [$err, $msg] = $this->callTool($this->key, 'manage_competitor', ['action' => 'add', 'brand_id' => $this->foreignBrand->id, 'network' => 'bluesky', 'username' => 'otro.bsky.social']);
    expect($err)->toBeTrue()->and($msg)->toContain('Marca no encontrada');
    [$err] = $this->callTool($this->key, 'manage_competitor', ['action' => 'add', 'brand_id' => $this->brand->id, 'network' => 'tiktok', 'username' => 'x']);
    expect($err)->toBeTrue();
    [$err] = $this->callTool($this->key, 'manage_competitor', ['action' => 'add', 'brand_id' => $this->brand->id, 'network' => 'bluesky']);
    expect($err)->toBeTrue();
    [$err] = $this->callTool($this->key, 'manage_competitor', ['action' => 'add', 'brand_id' => $this->brand->id, 'network' => 'bluesky', 'username' => 'sin punto']);
    expect($err)->toBeTrue()->and(Competitor::withoutUserScope()->count())->toBe(1);

    [$err, $msg] = $this->callTool($this->otherKey, 'manage_competitor', ['action' => 'remove', 'competitor_id' => $added['id'], 'confirm' => true]);
    expect($err)->toBeTrue()->and($msg)->toContain('Competidor no encontrado')->and(Competitor::withoutUserScope()->count())->toBe(1);
    [$err, $msg] = $this->callTool($this->key, 'manage_competitor', ['action' => 'remove', 'competitor_id' => $added['id']]);
    expect($err)->toBeTrue()->and($msg)->toContain('confirm')->and(Competitor::withoutUserScope()->count())->toBe(1);
    [$err] = $this->callTool($this->key, 'manage_competitor', ['action' => 'remove', 'competitor_id' => $added['id'], 'confirm' => false]);
    expect($err)->toBeTrue()->and(Competitor::withoutUserScope()->count())->toBe(1);
    [$err, $removed] = $this->callTool($this->key, 'manage_competitor', ['action' => 'remove', 'competitor_id' => $added['id'], 'confirm' => true]);
    expect($err)->toBeFalse()->and($removed['removed'])->toBeTrue()->and(Competitor::withoutUserScope()->count())->toBe(0);
});

// ------------------------------------------------------------------------------------------------------- hide comments

test('triage_comments: hide y unhide llaman a Meta y guardan el estado; ajeno, ability y red sin soporte', function () {
    $account = SocialAccount::factory()->for($this->user)->create(['brand_id' => $this->brand->id, 'network' => Network::Instagram, 'scopes' => ['instagram_manage_comments']]);
    $comment = InboxComment::factory()->create(['user_id' => $this->user->id, 'brand_id' => $this->brand->id, 'social_account_id' => $account->id, 'external_id' => '17900001']);
    $graph = new FakeGraph;
    $graph->on('POST', '#^17900001$#', ['success' => true]);
    $writer = $this->mcpKey($this->user, ['inbox:write']);

    [$err, $res] = $this->callTool($writer, 'triage_comments', ['action' => 'hide', 'comment_id' => $comment->id]);
    expect($err)->toBeFalse()->and($res['hidden'])->toBeTrue()->and($comment->fresh()->hidden)->toBeTrue()
        ->and($graph->callsTo('POST', '#^17900001$#')[0]['params']['hide'])->toBe('true');
    [$err, $res] = $this->callTool($writer, 'triage_comments', ['action' => 'unhide', 'comment_id' => $comment->id]);
    expect($err)->toBeFalse()->and($res['hidden'])->toBeFalse()->and($comment->fresh()->hidden)->toBeFalse();

    [$err, $msg] = $this->callTool($this->otherKey, 'triage_comments', ['action' => 'hide', 'comment_id' => $comment->id]);
    expect($err)->toBeTrue()->and($msg)->toContain('Comentario no encontrado');
    [$err, $msg] = $this->callTool($this->mcpKey($this->user, ['inbox:read']), 'triage_comments', ['action' => 'hide', 'comment_id' => $comment->id]);
    expect($err)->toBeTrue()->and($msg)->toContain('inbox:write');

    $noScope = SocialAccount::factory()->for($this->user)->create(['brand_id' => $this->brand->id, 'network' => Network::Instagram, 'scopes' => []]);
    $blocked = InboxComment::factory()->create(['user_id' => $this->user->id, 'brand_id' => $this->brand->id, 'social_account_id' => $noScope->id]);
    [$err, $msg] = $this->callTool($writer, 'triage_comments', ['action' => 'hide', 'comment_id' => $blocked->id]);
    expect($err)->toBeTrue()->and($msg)->toContain('permiso')->and($blocked->fresh()->hidden)->toBeFalse();
    [$err] = $this->callTool($writer, 'triage_comments', ['action' => 'hide']);
    expect($err)->toBeTrue();
});

// ------------------------------------------------------------------------------------------------------- saved texts

test('manage_saved_texts: crear, listar por marca con cursor, editar y borrar', function () {
    [$err, $a] = $this->callTool($this->key, 'manage_saved_texts', ['action' => 'create', 'kind' => 'hashtags', 'title' => 'Tags', 'body' => '#uno #dos']);
    expect($err)->toBeFalse()->and($a)->toMatchArray(['kind' => 'hashtags', 'title' => 'Tags', 'brand_id' => null]);
    [, $b] = $this->callTool($this->key, 'manage_saved_texts', ['action' => 'create', 'kind' => 'fragmento', 'title' => 'Firma', 'body' => 'Gracias', 'brand_id' => $this->brand->id]);
    expect($b['brand'])->toBe('Mi marca');
    SavedText::factory()->create(['user_id' => $this->user->id, 'brand_id' => Brand::factory()->for($this->user)->create()->id, 'title' => 'De otra marca mia']);

    [, $forBrand] = $this->callTool($this->key, 'manage_saved_texts', ['action' => 'list', 'brand_id' => $this->brand->id]);
    expect(collect($forBrand['saved_texts'])->pluck('title')->sort()->values()->all())->toBe(['Firma', 'Tags']);

    [, $p1] = $this->callTool($this->key, 'manage_saved_texts', ['action' => 'list', 'limit' => 2]);
    [, $p2] = $this->callTool($this->key, 'manage_saved_texts', ['action' => 'list', 'limit' => 2, 'cursor' => $p1['next_cursor']]);
    expect($p1['saved_texts'])->toHaveCount(2)->and($p1['has_more'])->toBeTrue()->and($p2['saved_texts'])->toHaveCount(1)->and($p2['has_more'])->toBeFalse();

    [$err, $up] = $this->callTool($this->key, 'manage_saved_texts', ['action' => 'update', 'saved_text_id' => $a['id'], 'kind' => 'hashtags', 'title' => 'Tags 2', 'body' => '#tres']);
    expect($err)->toBeFalse()->and($up['title'])->toBe('Tags 2')->and(SavedText::withoutUserScope()->find($a['id'])->body)->toBe('#tres');
    [$err, $del] = $this->callTool($this->key, 'manage_saved_texts', ['action' => 'delete', 'saved_text_id' => $a['id']]);
    expect($err)->toBeFalse()->and($del['deleted'])->toBeTrue()->and(SavedText::withoutUserScope()->find($a['id']))->toBeNull();
});

test('manage_saved_texts: otro usuario, otra marca, ability ausente y validacion', function () {
    [, $a] = $this->callTool($this->key, 'manage_saved_texts', ['action' => 'create', 'kind' => 'fragmento', 'title' => 'Mio', 'body' => 'x']);
    $reader = $this->mcpKey($this->user, ['posts:read']);

    [$err, $msg] = $this->callTool($this->otherKey, 'manage_saved_texts', ['action' => 'delete', 'saved_text_id' => $a['id']]);
    expect($err)->toBeTrue()->and($msg)->toContain('Texto guardado no encontrado')->and(SavedText::withoutUserScope()->count())->toBe(1);
    [$err] = $this->callTool($this->otherKey, 'manage_saved_texts', ['action' => 'update', 'saved_text_id' => $a['id'], 'kind' => 'fragmento', 'title' => 'Robado', 'body' => 'x']);
    expect($err)->toBeTrue()->and(SavedText::withoutUserScope()->find($a['id'])->title)->toBe('Mio');
    [$err, $msg] = $this->callTool($this->key, 'manage_saved_texts', ['action' => 'create', 'kind' => 'fragmento', 'title' => 'T', 'body' => 'x', 'brand_id' => $this->foreignBrand->id]);
    expect($err)->toBeTrue()->and($msg)->toContain('Marca no encontrada');

    [$err, $msg] = $this->callTool($reader, 'manage_saved_texts', ['action' => 'create', 'kind' => 'fragmento', 'title' => 'T', 'body' => 'x']);
    expect($err)->toBeTrue()->and($msg)->toContain('posts:write');
    [$err] = $this->callTool($reader, 'manage_saved_texts', ['action' => 'list']);
    expect($err)->toBeFalse();
    [$err, $msg] = $this->callTool($this->none, 'manage_saved_texts', ['action' => 'list']);
    expect($err)->toBeTrue()->and($msg)->toContain('posts:read');

    [$err, $msg] = $this->callTool($this->key, 'manage_saved_texts', ['action' => 'create', 'kind' => 'otro', 'title' => '', 'body' => str_repeat('a', 5001)]);
    expect($err)->toBeTrue()->and($msg)->toContain('kind')->and($msg)->toContain('title')->and($msg)->toContain('body');
});

// ------------------------------------------------------------------------------------------------------------- presets

test('get_composer_presets: devuelve los interruptores recordados de la marca; otra marca, otro usuario y ability', function () {
    $preset = new ComposerPreset(['network' => 'instagram', 'options' => ['share_to_feed' => true, 'titulo' => 'no se recuerda']]);
    $preset->user_id = $this->user->id;
    $preset->brand_id = $this->brand->id;
    $preset->save();
    $reader = $this->mcpKey($this->user, ['posts:read']);

    [$err, $res] = $this->callTool($reader, 'get_composer_presets', ['brand_id' => $this->brand->id]);
    expect($err)->toBeFalse()->and($res['presets'])->toBe(['instagram' => ['share_to_feed' => true]])->and($res['brand_name'])->toBe('Mi marca');

    [$err, $res] = $this->callTool($reader, 'get_composer_presets', ['brand' => 'Mi marca']);
    expect($err)->toBeFalse()->and($res['brand_id'])->toBe($this->brand->id);

    $empty = Brand::factory()->for($this->user)->create();
    [, $none] = $this->callTool($reader, 'get_composer_presets', ['brand_id' => $empty->id]);
    expect($none['presets'])->toBe([]);

    [$err, $msg] = $this->callTool($this->otherKey, 'get_composer_presets', ['brand_id' => $this->brand->id]);
    expect($err)->toBeTrue()->and($msg)->toContain('Marca no encontrada');
    [$err, $msg] = $this->callTool($this->none, 'get_composer_presets', ['brand_id' => $this->brand->id]);
    expect($err)->toBeTrue()->and($msg)->toContain('posts:read');
    [$err, $msg] = $this->callTool($reader, 'get_composer_presets');
    expect($err)->toBeTrue()->and($msg)->toContain('Indica la marca');
});

// -------------------------------------------------------------------------------------------------------------- trash

test('manage_trash: listar con cursor, restaurar y eliminar para siempre', function () {
    $a = Post::factory()->for($this->brand)->create(['user_id' => $this->user->id, 'text' => 'Uno']);
    $b = Post::factory()->for($this->brand)->create(['user_id' => $this->user->id, 'text' => 'Dos']);
    $c = Post::factory()->for($this->brand)->create(['user_id' => $this->user->id, 'text' => 'Tres']);
    foreach ([$a, $b, $c] as $p) {
        $p->delete();
    }

    [$err, $p1] = $this->callTool($this->key, 'manage_trash', ['action' => 'list', 'limit' => 2]);
    expect($err)->toBeFalse()->and($p1['posts'])->toHaveCount(2)->and($p1['has_more'])->toBeTrue()->and($p1['retention_days'])->toBe(30)
        ->and($p1['posts'][0])->toHaveKeys(['id', 'brand', 'text', 'networks', 'deleted_at', 'days_left']);
    [, $p2] = $this->callTool($this->key, 'manage_trash', ['action' => 'list', 'limit' => 2, 'cursor' => $p1['next_cursor']]);
    expect($p2['posts'])->toHaveCount(1)->and($p2['has_more'])->toBeFalse();

    [$err, $restored] = $this->callTool($this->key, 'manage_trash', ['action' => 'restore', 'post_id' => $a->id]);
    expect($err)->toBeFalse()->and($restored['restored'])->toBeTrue()->and($restored['id'])->toBe($a->id)->and(Post::withoutUserScope()->find($a->id)->trashed())->toBeFalse();
    [$err, $msg] = $this->callTool($this->key, 'manage_trash', ['action' => 'delete_forever', 'post_id' => $b->id]);
    expect($err)->toBeTrue()->and($msg)->toContain('confirm')->and(Post::withoutUserScope()->withTrashed()->find($b->id))->not->toBeNull();
    [$err] = $this->callTool($this->key, 'manage_trash', ['action' => 'delete_forever', 'post_id' => $b->id, 'confirm' => 'si']);
    expect($err)->toBeTrue()->and(Post::withoutUserScope()->withTrashed()->find($b->id))->not->toBeNull();
    [$err, $gone] = $this->callTool($this->key, 'manage_trash', ['action' => 'delete_forever', 'post_id' => $b->id, 'confirm' => true]);
    expect($err)->toBeFalse()->and($gone['deleted'])->toBeTrue()->and(Post::withoutUserScope()->withTrashed()->find($b->id))->toBeNull();

    [$err, $msg] = $this->callTool($this->key, 'manage_trash', ['action' => 'restore', 'post_id' => $a->id]);
    expect($err)->toBeTrue()->and($msg)->toContain('no encontrada');
});

test('manage_trash: otro usuario, ability ausente y validacion', function () {
    $post = Post::factory()->for($this->brand)->create(['user_id' => $this->user->id]);
    $post->delete();
    $reader = $this->mcpKey($this->user, ['posts:read']);

    [, $otherList] = $this->callTool($this->otherKey, 'manage_trash', ['action' => 'list']);
    expect($otherList['posts'])->toBe([]);
    [$err, $msg] = $this->callTool($this->otherKey, 'manage_trash', ['action' => 'delete_forever', 'post_id' => $post->id, 'confirm' => true]);
    [$errMissing, $msgMissing] = $this->callTool($this->otherKey, 'manage_trash', ['action' => 'delete_forever', 'post_id' => 999999, 'confirm' => true]);
    expect($err)->toBeTrue()->and($errMissing)->toBeTrue()->and($msg)->toBe($msgMissing)->and(Post::withoutUserScope()->onlyTrashed()->count())->toBe(1);

    [$err, $msg] = $this->callTool($reader, 'manage_trash', ['action' => 'restore', 'post_id' => $post->id]);
    expect($err)->toBeTrue()->and($msg)->toContain('posts:write');
    [$err] = $this->callTool($reader, 'manage_trash', ['action' => 'list']);
    expect($err)->toBeFalse();
    [$err, $msg] = $this->callTool($this->none, 'manage_trash', ['action' => 'list']);
    expect($err)->toBeTrue()->and($msg)->toContain('posts:read');
    [$err] = $this->callTool($this->key, 'manage_trash', ['action' => 'restore']);
    expect($err)->toBeTrue();
    [$err] = $this->callTool($this->key, 'manage_trash', ['action' => 'list', 'limit' => 500]);
    expect($err)->toBeTrue();
});

// ------------------------------------------------------------------------------------------------------------- catalogo

test('tools/list incluye las herramientas nuevas y pagina el catalogo completo', function () {
    $names = collect();
    $cursor = null;
    do {
        $response = $this->rpc($this->key, 'tools/list', $cursor === null ? [] : ['cursor' => $cursor]);
        $names = $names->merge(collect($response->json('result.tools'))->pluck('name'));
        $cursor = $response->json('result.nextCursor');
    } while ($cursor !== null);

    expect($names)->toHaveCount(64)->and($names->unique())->toHaveCount(64)
        ->toContain('manage_smartlink', 'get_ads', 'manage_autolist', 'manage_competitor', 'manage_saved_texts', 'get_composer_presets', 'manage_trash', 'triage_comments');
});

// ------------------------------------------------------------------------------------------------- revision 1 (T-30)

/** Catalogo completo de herramientas por nombre (acumula todas las paginas de tools/list). */
function mcpCatalog()
{
    $tools = collect();
    $cursor = null;
    do {
        $r = test()->rpc(test()->key, 'tools/list', $cursor === null ? [] : ['cursor' => $cursor]);
        $tools = $tools->merge(collect($r->json('result.tools'))->keyBy('name'));
        $cursor = $r->json('result.nextCursor');
    } while ($cursor !== null);

    return $tools;
}

test('manage_smartlink: la descripcion de published dice lo que hace (sin publicar por defecto; uno existente conserva su estado)', function () {
    $tool = mcpCatalog()->get('manage_smartlink');
    $description = $tool['inputSchema']['properties']['published']['description'] ?? '';

    expect($description)->not->toContain('por defecto true')->and($description)->toContain('sin publicar')->and($description)->toContain('conserva');

    $writer = $this->mcpKey($this->user, ['smartlinks:write']);
    [, $created] = $this->callTool($writer, 'manage_smartlink', ['action' => 'save', 'brand_id' => $this->brand->id, 'slug' => 'sin-publicar', 'title' => 'T']);
    expect($created['published'])->toBeFalse();
});

test('manage_trash: delete_forever es irreversible y la descripcion lo avisa; sin confirm no borra', function () {
    $names = mcpCatalog();
    $tool = $names->get('manage_trash');

    expect($tool['description'])->toContain('IRREVERSIBLE')->and($tool['description'])->toContain('confirm')->and($tool['description'])->toContain('humana')
        ->and($names->get('manage_competitor')['description'])->toContain('confirm')->and($names->get('manage_competitor')['description'])->toContain('competitors:write');
});

test('competitors:write es una ability propia: esta en el preset all y no en publish ni read, y posts:write no basta', function () {
    expect(Abilities::keys())->toContain('competitors:write')
        ->and(Abilities::presets()['all']['abilities'])->toContain('competitors:write')
        ->and(Abilities::presets()['publish']['abilities'])->not->toContain('competitors:write')
        ->and(Abilities::presets()['read']['abilities'])->not->toContain('competitors:write')
        ->and(Abilities::all())->toHaveKey('competitors:write');
    $own = $this->mcpKey($this->user, ['competitors:write']);

    [$err, $msg] = $this->callTool($own, 'manage_competitor', ['action' => 'remove', 'competitor_id' => 999999, 'confirm' => true]);

    expect($err)->toBeTrue()->and($msg)->toContain('Competidor no encontrado');
});

test('triage_comments hide: una red sin moderacion por esta via (Bluesky) se rechaza con su mensaje y no llama a nadie', function () {
    $writer = $this->mcpKey($this->user, ['inbox:write']);
    $account = SocialAccount::factory()->for($this->user)->create(['brand_id' => $this->brand->id, 'network' => Network::Bluesky, 'scopes' => []]);
    $comment = InboxComment::factory()->create(['user_id' => $this->user->id, 'brand_id' => $this->brand->id, 'social_account_id' => $account->id, 'network' => Network::Bluesky, 'external_id' => 'at://x/1']);

    [$err, $msg] = $this->callTool($writer, 'triage_comments', ['action' => 'hide', 'comment_id' => $comment->id]);

    expect($err)->toBeTrue()->and($msg)->toContain('solo está disponible en Instagram y Facebook')->and($comment->fresh()->hidden)->toBeFalse();
    Http::assertNothingSent();
});

test('get_ads: una ad_account que Meta no devolvio para esta conexion da un error claro en vez de usar la primera', function () {
    SocialAccount::factory()->for($this->user)->create(['brand_id' => $this->brand->id, 'network' => Network::Facebook, 'access_token' => 'PAGE', 'refresh_token' => 'USER-TOKEN', 'scopes' => ['pages_show_list', 'ads_read']]);
    $graph = new FakeGraph;
    mcpAdsFake($graph);

    [$err, $msg] = $this->callTool($this->mcpKey($this->user, ['stats:read']), 'get_ads', ['brand_id' => $this->brand->id, 'ad_account' => 'act_999']);
    expect($err)->toBeTrue()->and($msg)->toContain('act_999')->and($msg)->toContain('act_111');

    [$err, $res] = $this->callTool($this->mcpKey($this->user, ['stats:read']), 'get_ads', ['brand_id' => $this->brand->id, 'ad_account' => 'act_111']);
    expect($err)->toBeFalse()->and($res['selected']['id'])->toBe('act_111');
});

test('los mensajes de recurso no encontrado concuerdan en genero (Marca, Comentario, Informe, Sitio, Diseno)', function () {
    [, $m1] = $this->callTool($this->key, 'get_report', ['report_id' => 999999]);
    [, $m2] = $this->callTool($this->key, 'manage_site', ['action' => 'get_url', 'site_id' => 999999]);
    [, $m3] = $this->callTool($this->key, 'reply_comment', ['comment_id' => 999999, 'text' => 'x']);
    [, $m4] = $this->callTool($this->key, 'manage_design', ['action' => 'archive', 'brand_id' => $this->brand->id, 'design_id' => 999999]);
    [, $m5] = $this->callTool($this->key, 'manage_autolist', ['action' => 'delete', 'autolist_id' => 999999]);

    expect($m1)->toBe('Informe no encontrado.')->and($m2)->toBe('Sitio no encontrado.')->and($m3)->toBe('Comentario no encontrado.')->and($m5)->toBe('Autolista no encontrada.')
        ->and($m4)->toContain('no encontrad');
});
