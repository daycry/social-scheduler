<?php

use App\Connectors\Network;
use App\Enums\PostStatus;
use App\Jobs\PublishPostTarget;
use App\Models\AuditLog;
use App\Models\Brand;
use App\Models\Post;
use App\Models\SocialAccount;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\Support\McpClient;

uses(McpClient::class);

// Herramienta MCP publish_post_now: mismas reglas que la web (PostService::publishNow) y aislamiento por usuario.

beforeEach(function () {
    Http::preventStrayRequests();
    Queue::fake();
    Carbon::setTestNow('2026-11-01 08:00:00');
    $this->user = User::factory()->create();
    $this->brand = Brand::factory()->for($this->user)->create(['timezone' => 'Europe/Madrid']);
    $this->fb = SocialAccount::factory()->for($this->user)->create(['brand_id' => $this->brand->id, 'network' => Network::Facebook, 'name' => 'FB A']);
    $this->key = $this->mcpKey($this->user);
});

function mcpDraft(string $key): array
{
    [, $post] = test()->callTool($key, 'create_post', [
        'brand_id' => test()->brand->id, 'text' => 'Hola', 'schedule' => false,
        'targets' => [['account_id' => test()->fb->id, 'content_type' => 'facebook_text']],
    ]);

    return $post;
}

test('PublishPostNowTool: publica un borrador al instante y lo encola', function () {
    $draft = mcpDraft($this->key);

    [$err, $post] = $this->callTool($this->key, 'publish_post_now', ['post_id' => $draft['id']]);

    expect($err)->toBeFalse()->and($post)->toMatchArray(['id' => $draft['id'], 'status' => 'programada'])
        ->and($post['targets'][0]['status'])->toBe('programada');
    Queue::assertPushed(PublishPostTarget::class, 1);
    expect(AuditLog::where('action', 'publish_post_now')->where('outcome', 'ok')->count())->toBe(1);
});

test('PublishPostNowTool: figura en tools/list y respeta la ability posts:write', function () {
    $names = collect($this->rpc($this->key, 'tools/list')->json('result.tools'))->pluck('name')->all();
    expect($names)->toContain('publish_post_now');

    $draft = mcpDraft($this->key);
    $reader = $this->mcpKey($this->user, ['posts:read'], 'lector');
    [$err, $msg] = $this->callTool($reader, 'publish_post_now', ['post_id' => $draft['id']]);

    expect($err)->toBeTrue()->and($msg)->toContain('posts:write');
    Queue::assertNothingPushed();
});

test('PublishPostNowTool: un post de otro usuario responde igual que uno inexistente (NotOwned) y no encola', function () {
    $draft = mcpDraft($this->key);
    $other = $this->mcpKey(User::factory()->create());

    [$errF, $msgF] = $this->callTool($other, 'publish_post_now', ['post_id' => $draft['id']]);
    [$errM, $msgM] = $this->callTool($other, 'publish_post_now', ['post_id' => 999999]);

    expect($errF)->toBeTrue()->and($errM)->toBeTrue()->and($msgF)->toBe($msgM);
    expect(Post::withoutUserScope()->find($draft['id'])->status)->toBe(PostStatus::Draft);
    Queue::assertNothingPushed();
});

test('PublishPostNowTool: un post no editable se rechaza con el mismo error que update_post', function () {
    $draft = mcpDraft($this->key);
    Post::withoutUserScope()->find($draft['id'])->targets()->first()->forceFill(['status' => PostStatus::Publishing])->save();

    [$errNow, $msgNow] = $this->callTool($this->key, 'publish_post_now', ['post_id' => $draft['id']]);
    [$errUpd, $msgUpd] = $this->callTool($this->key, 'update_post', ['post_id' => $draft['id'], 'schedule' => false, 'text' => 'x']);

    expect($errNow)->toBeTrue()->and($errUpd)->toBeTrue()->and($msgNow)->toBe($msgUpd)->and($msgNow)->toContain('no se puede modificar');
    Queue::assertNothingPushed();
});

test('PublishPostNowTool: con errores de contenido se rechaza con los mismos mensajes que al programar', function () {
    $draft = mcpDraft($this->key);
    Post::withoutUserScope()->find($draft['id'])->forceFill(['text' => str_repeat('a', 63207)])->save();

    [$err, $msg] = $this->callTool($this->key, 'publish_post_now', ['post_id' => $draft['id']]);

    expect($err)->toBeTrue()->and($msg)->toContain('Datos no validos')->and($msg)->toContain('63207');
    Queue::assertNothingPushed();
});

test('PublishPostNowTool: create_post admite publish_now (sin fecha) y encola', function () {
    [$err, $post] = $this->callTool($this->key, 'create_post', [
        'brand_id' => $this->brand->id, 'text' => 'Ya', 'schedule' => false, 'publish_now' => true,
        'targets' => [['account_id' => $this->fb->id, 'content_type' => 'facebook_text']],
    ]);

    expect($err)->toBeFalse()->and($post['status'])->toBe('programada');
    Queue::assertPushed(PublishPostTarget::class, 1);
});

test('PublishPostNowTool: publish_now junto con scheduled_at se rechaza', function () {
    [$err, $msg] = $this->callTool($this->key, 'create_post', [
        'brand_id' => $this->brand->id, 'text' => 'Ya', 'schedule' => false, 'publish_now' => true, 'scheduled_at' => '2026-12-01 10:00',
        'targets' => [['account_id' => $this->fb->id, 'content_type' => 'facebook_text']],
    ]);

    expect($err)->toBeTrue()->and($msg)->toContain('scheduled_at');
    expect(Post::withoutUserScope()->count())->toBe(0);
});
