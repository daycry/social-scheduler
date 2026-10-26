<?php

use App\Connectors\Network;
use App\Enums\PostStatus;
use App\Mcp\Tools\CreatePost;
use App\Models\AuditLog;
use App\Models\Brand;
use App\Models\Campaign;
use App\Models\Post;
use App\Models\SocialAccount;
use App\Models\User;
use App\Services\PostService;
use Illuminate\JsonSchema\JsonSchemaTypeFactory;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\Support\McpClient;

uses(McpClient::class);

// C-09 notas internas por MCP: update_post_notes y get_post. Mismas reglas que la web; el contenido no se audita.

beforeEach(function () {
    Http::preventStrayRequests();
    Queue::fake();
    Carbon::setTestNow('2026-11-01 12:00:00');
    $this->user = User::factory()->create();
    $this->brand = Brand::factory()->for($this->user)->create(['timezone' => 'Europe/Madrid']);
    $this->fb = SocialAccount::factory()->for($this->user)->create(['brand_id' => $this->brand->id, 'network' => Network::Facebook]);
    $this->service = app(PostService::class);
    $this->key = $this->mcpKey($this->user);
});

function mcpNotesPost(array $over = []): Post
{
    return test()->service->create(test()->user, test()->brand, array_merge([
        'text' => 'Hola', 'schedule' => false, 'media' => [],
        'targets' => [['account_id' => test()->fb->id, 'content_type' => 'facebook_text']],
    ], $over));
}

test('McpNotes: update_post_notes edita un post publicado y get_post devuelve las notas, sin auditar su contenido', function () {
    $post = mcpNotesPost();
    $post->targets->first()->forceFill(['status' => PostStatus::Published])->save();

    [$err, $res] = $this->callTool($this->key, 'update_post_notes', ['post_id' => $post->id, 'notes' => 'CONTENIDO-PRIVADO']);
    expect($err)->toBeFalse()->and($res['notes'])->toBe('CONTENIDO-PRIVADO');

    [, $got] = $this->callTool($this->key, 'get_post', ['post_id' => $post->id]);
    expect($got['notes'])->toBe('CONTENIDO-PRIVADO');

    expect(AuditLog::where('action', 'update_post_notes')->where('outcome', 'ok')->count())->toBe(1)
        ->and(json_encode(AuditLog::all()->toArray()))->not->toContain('CONTENIDO-PRIVADO');
    Queue::assertNothingPushed();
});

test('McpNotes: exige posts:write', function () {
    $post = mcpNotesPost();
    $reader = $this->mcpKey($this->user, ['posts:read'], 'lector');

    [$err, $msg] = $this->callTool($reader, 'update_post_notes', ['post_id' => $post->id, 'notes' => 'x']);

    expect($err)->toBeTrue()->and($msg)->toContain('posts:write')->and($post->fresh()->notes)->toBeNull();
});

test('McpNotes: un post ajeno responde igual que uno inexistente (IDOR)', function () {
    $post = mcpNotesPost(['notes' => 'mia']);
    $other = $this->mcpKey(User::factory()->create());

    [$errF, $msgF] = $this->callTool($other, 'update_post_notes', ['post_id' => $post->id, 'notes' => 'hack']);
    [$errM, $msgM] = $this->callTool($other, 'update_post_notes', ['post_id' => 999999, 'notes' => 'hack']);

    expect($errF)->toBeTrue()->and($errM)->toBeTrue()->and($msgF)->toBe($msgM)->and($post->fresh()->notes)->toBe('mia');
});

test('McpNotes: mas de 5.000 caracteres se rechaza y el vacio borra', function () {
    $post = mcpNotesPost(['notes' => 'algo']);

    [$err, $msg] = $this->callTool($this->key, 'update_post_notes', ['post_id' => $post->id, 'notes' => str_repeat('a', 5001)]);
    expect($err)->toBeTrue()->and($msg)->toContain('Datos no validos');

    [$err, $res] = $this->callTool($this->key, 'update_post_notes', ['post_id' => $post->id, 'notes' => '']);
    expect($err)->toBeFalse()->and($res['notes'])->toBeNull();
});

test('McpNotes: create_post admite notes y las 22 herramientas caben en la primera pagina', function () {
    [$err, $res] = $this->callTool($this->key, 'create_post', [
        'brand_id' => $this->brand->id, 'text' => 'Hola', 'schedule' => false, 'notes' => 'desde mcp',
        'targets' => [['account_id' => $this->fb->id, 'content_type' => 'facebook_text']],
    ]);
    expect($err)->toBeFalse()->and($res['notes'])->toBe('desde mcp');

    $r = $this->rpc($this->key, 'tools/list')->assertOk();
    $names = collect($r->json('result.tools'))->pluck('name');

    expect($names)->toHaveCount(22)->and($names)->toContain('update_post_notes', 'publish_post_now')
        ->and($r->json('result.nextCursor'))->toBeNull();
});

test('PostNotes: el calendario marca el post con nota', function () {
    mcpNotesPost(['notes' => 'ojo', 'schedule' => true, 'scheduled_at' => '2026-11-03 10:00']);

    $this->actingAs($this->user)->get(route('calendar.index', ['brand_id' => $this->brand->id, 'view' => 'week', 'date' => '2026-11-03']))
        ->assertInertia(fn ($page) => $page->where('items.0.has_notes', true));
});

test('McpNotes: CRLF y CR suelto se guardan como LF y la linea en blanco se conserva', function () {
    $post = mcpNotesPost();

    [$err, $res] = $this->callTool($this->key, 'update_post_notes', ['post_id' => $post->id, 'notes' => "a\r\n\r\nb\rc"]);

    expect($err)->toBeFalse()->and($res['notes'])->toBe("a\n\nb\nc")->and($post->fresh()->notes)->toBe("a\n\nb\nc");
});

test('McpCampaign: update_post_campaign asigna y quita la campaña de un post publicado y respeta marca y archivo', function () {
    $post = mcpNotesPost();
    $post->targets->first()->forceFill(['status' => PostStatus::Published])->save();
    $mine = Campaign::factory()->create(['brand_id' => $this->brand->id, 'user_id' => $this->user->id]);
    $other = Campaign::factory()->create(['brand_id' => Brand::factory()->for($this->user)->create()->id, 'user_id' => $this->user->id]);
    $archived = Campaign::factory()->create(['brand_id' => $this->brand->id, 'user_id' => $this->user->id, 'archived_at' => now()]);

    [$err, $res] = $this->callTool($this->key, 'update_post_campaign', ['post_id' => $post->id, 'campaign_id' => $mine->id]);
    expect($err)->toBeFalse()->and($res['campaign_id'])->toBe($mine->id);

    [$err, $msg] = $this->callTool($this->key, 'update_post_campaign', ['post_id' => $post->id, 'campaign_id' => $other->id]);
    expect($err)->toBeTrue()->and($msg)->toContain('campaign_id');
    [$err, $msg] = $this->callTool($this->key, 'update_post_campaign', ['post_id' => $post->id, 'campaign_id' => $archived->id]);
    expect($err)->toBeTrue()->and($msg)->toContain('archivada')->and($post->fresh()->campaign_id)->toBe($mine->id);

    [$err, $res] = $this->callTool($this->key, 'update_post_campaign', ['post_id' => $post->id, 'campaign_id' => null]);
    expect($err)->toBeFalse()->and($res['campaign_id'])->toBeNull();
    Queue::assertNothingPushed();
});

test('McpCampaign: el esquema de campaign_id admite null y create_post rechaza campañas archivadas', function () {
    $schema = (new CreatePost)->schema(new JsonSchemaTypeFactory);
    expect($schema['campaign_id']->toArray()['type'])->toBe(['integer', 'null']);

    $archived = Campaign::factory()->create(['brand_id' => $this->brand->id, 'user_id' => $this->user->id, 'archived_at' => now()]);
    [$err, $msg] = $this->callTool($this->key, 'create_post', [
        'brand_id' => $this->brand->id, 'text' => 'x', 'schedule' => false, 'campaign_id' => $archived->id,
        'targets' => [['account_id' => $this->fb->id, 'content_type' => 'facebook_text']],
    ]);
    expect($err)->toBeTrue()->and($msg)->toContain('archivada');
});
