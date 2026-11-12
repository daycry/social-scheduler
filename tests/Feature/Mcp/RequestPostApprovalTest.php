<?php

use App\Connectors\Network;
use App\Models\AuditLog;
use App\Models\Brand;
use App\Models\BrandCollaborator;
use App\Models\Post;
use App\Models\SocialAccount;
use App\Models\User;
use App\Services\PostService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\Support\McpClient;

uses(McpClient::class);

// E-09: herramienta MCP request_post_approval: exito, ability posts:write, acceso cruzado entre usuarios y marcas.

beforeEach(function () {
    Http::preventStrayRequests();
    Queue::fake();
    Carbon::setTestNow('2026-11-09 12:00:00');
    $this->owner = User::factory()->create();
    $this->approver = User::factory()->create();
    $this->other = User::factory()->create();
    $this->brand = Brand::factory()->for($this->owner)->create(['timezone' => 'UTC']);
    $this->fb = SocialAccount::factory()->for($this->owner)->create(['brand_id' => $this->brand->id, 'network' => Network::Facebook]);
    BrandCollaborator::query()->create(['brand_id' => $this->brand->id, 'owner_id' => $this->owner->id, 'user_id' => $this->approver->id, 'role' => 'aprobador']);
    $this->ownerKey = $this->mcpKey($this->owner);
});

function rpaDraft(User $user, Brand $brand, SocialAccount $account): Post
{
    return app(PostService::class)->create($user, $brand, [
        'text' => 'Hola', 'schedule' => false, 'scheduled_at' => '2026-11-10T10:00', 'media' => [],
        'targets' => [['account_id' => $account->id, 'content_type' => 'facebook_text']],
    ]);
}

test('RequestPostApproval: el propietario pide la aprobacion, el post queda pendiente y se avisa al aprobador', function () {
    $post = rpaDraft($this->owner, $this->brand, $this->fb);

    [$err, $data] = $this->callTool($this->ownerKey, 'request_post_approval', ['post_id' => $post->id]);

    expect($err)->toBeFalse()->and($data['post']['id'])->toBe($post->id)->and($data['post']['approval_status'])->toBe('pendiente');
    expect($post->fresh()->approval_status)->toBe('pendiente');
    expect($this->approver->fresh()->notifications()->where('data->type', 'approval_requested')->count())->toBe(1);
    // Idempotente: repetir no duplica el aviso.
    [$again] = $this->callTool($this->ownerKey, 'request_post_approval', ['post_id' => $post->id]);
    expect($again)->toBeFalse()->and($this->approver->fresh()->notifications()->where('data->type', 'approval_requested')->count())->toBe(1);
});

test('RequestPostApproval: una key sin posts:write se rechaza y no cambia el post', function () {
    $post = rpaDraft($this->owner, $this->brand, $this->fb);

    foreach ([['posts:read'], ['posts:approve']] as $i => $abilities) {
        [$err, $msg] = $this->callTool($this->mcpKey($this->owner, $abilities, "k$i"), 'request_post_approval', ['post_id' => $post->id]);
        expect($err)->toBeTrue()->and($msg)->toContain('posts:write');
    }

    expect($post->fresh()->approval_status)->not->toBe('pendiente');
    expect($this->approver->fresh()->notifications()->count())->toBe(0);
});

test('RequestPostApproval: otro usuario no puede pedir la aprobacion de un post ajeno y responde como si no existiera', function () {
    $post = rpaDraft($this->owner, $this->brand, $this->fb);
    $otherKey = $this->mcpKey($this->other);
    $missing = $this->callTool($otherKey, 'request_post_approval', ['post_id' => $post->id + 9999]);

    [$err, $msg] = $this->callTool($otherKey, 'request_post_approval', ['post_id' => $post->id]);

    expect($err)->toBeTrue()->and($msg)->toBe($missing[1]);
    expect($post->fresh()->approval_status)->not->toBe('pendiente');
    expect($this->approver->fresh()->notifications()->count())->toBe(0);
});

test('RequestPostApproval: el aprobador invitado de la marca tampoco puede pedirla (solo el propietario)', function () {
    $post = rpaDraft($this->owner, $this->brand, $this->fb);

    [$err] = $this->callTool($this->mcpKey($this->approver), 'request_post_approval', ['post_id' => $post->id]);

    expect($err)->toBeTrue();
    expect($post->fresh()->approval_status)->not->toBe('pendiente');
});

test('RequestPostApproval: no cruza marcas, la key de un usuario no alcanza el post de la marca de otro', function () {
    $otherBrand = Brand::factory()->for($this->other)->create(['timezone' => 'UTC']);
    $otherFb = SocialAccount::factory()->for($this->other)->create(['brand_id' => $otherBrand->id, 'network' => Network::Facebook]);
    $theirs = rpaDraft($this->other, $otherBrand, $otherFb);

    [$err] = $this->callTool($this->ownerKey, 'request_post_approval', ['post_id' => $theirs->id]);

    expect($err)->toBeTrue();
    expect($theirs->fresh()->approval_status)->not->toBe('pendiente');
    expect(AuditLog::query()->where('action', 'request_post_approval')->where('outcome', 'ok')->count())->toBe(0);
});

test('RequestPostApproval: sin key no hay acceso', function () {
    $post = rpaDraft($this->owner, $this->brand, $this->fb);

    $this->rpc(null, 'tools/call', ['name' => 'request_post_approval', 'arguments' => ['post_id' => $post->id]])->assertUnauthorized();

    expect($post->fresh()->approval_status)->not->toBe('pendiente');
});
