<?php

use App\Connectors\Network;
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

// T-19, contrato compartido: aprobar, rechazar, manual, reintento, series, lotes y colaboradores devuelven lo mismo en la web, la API REST y el MCP.

beforeEach(function () {
    Http::preventStrayRequests();
    Queue::fake();
    Carbon::setTestNow('2026-11-09 12:00:00');
    $this->owner = User::factory()->create();
    $this->approver = User::factory()->create();
    $this->brand = Brand::factory()->for($this->owner)->create(['timezone' => 'UTC']);
    $this->fb = SocialAccount::factory()->for($this->owner)->create(['brand_id' => $this->brand->id, 'network' => Network::Facebook]);
    BrandCollaborator::query()->create(['brand_id' => $this->brand->id, 'owner_id' => $this->owner->id, 'user_id' => $this->approver->id, 'role' => 'aprobador']);
    $this->ownerKey = $this->mcpKey($this->owner);
    $this->approverKey = $this->mcpKey($this->approver);
});

function wfPost(array $over = []): Post
{
    return app(PostService::class)->create(test()->owner, test()->brand, array_merge([
        'text' => 'Hola', 'schedule' => false, 'scheduled_at' => '2026-11-12T10:00', 'media' => [],
        'targets' => [['account_id' => test()->fb->id, 'content_type' => 'facebook_text']],
    ], $over));
}

/** Llama a la API REST con una key nueva (cada peticion es independiente). */
function wfApi(string $key, string $method, string $uri, array $body = [], array $headers = [])
{
    app('auth')->forgetGuards();

    return test()->withToken($key)->json($method, $uri, $body, $headers);
}

test('aprobar: web, API y MCP devuelven el mismo post', function () {
    $web = wfPost(['requires_approval' => true]);
    $api = wfPost(['requires_approval' => true]);
    $mcp = wfPost(['requires_approval' => true]);

    $w = $this->actingAs($this->approver)->postJson(route('workflow.approval.approve', $web->id))->assertOk()->json('post');
    $a = wfApi($this->approverKey, 'POST', "/api/v1/posts/{$api->id}/approval/approve")->assertOk()->json('post');
    [$err, $m] = $this->callTool($this->approverKey, 'approve_post', ['post_id' => $mcp->id]);

    expect($err)->toBeFalse();
    $strip = fn (array $p) => array_diff_key($p, ['id' => 1, 'requested_by' => 1]);
    expect($strip($w))->toBe($strip($a))->and($strip($m['post']))->toBe($strip($a))->and($a['approval_status'])->toBe('aprobado');
});

test('rechazar: mismo contrato y el comentario es obligatorio en los tres canales', function () {
    $a = wfPost(['requires_approval' => true]);
    $b = wfPost(['requires_approval' => true]);
    $c = wfPost(['requires_approval' => true]);

    $this->actingAs($this->approver)->postJson(route('workflow.approval.reject', $a->id), ['comment' => ''])->assertStatus(422);
    wfApi($this->approverKey, 'POST', "/api/v1/posts/{$b->id}/approval/reject", [])->assertStatus(422);
    [$err] = $this->callTool($this->approverKey, 'reject_post', ['post_id' => $c->id, 'comment' => '   ']);
    expect($err)->toBeTrue();

    $w = $this->actingAs($this->approver)->postJson(route('workflow.approval.reject', $a->id), ['comment' => 'No'])->assertOk()->json('post');
    $x = wfApi($this->approverKey, 'POST', "/api/v1/posts/{$b->id}/approval/reject", ['comment' => 'No'])->assertOk()->json('post');
    [$err2, $m] = $this->callTool($this->approverKey, 'reject_post', ['post_id' => $c->id, 'comment' => 'No']);
    expect($err2)->toBeFalse();
    $strip = fn (array $p) => array_diff_key($p, ['id' => 1]);
    expect($strip($w))->toBe($strip($x))->and($strip($m['post']))->toBe($strip($x))->and($x['approval_status'])->toBe('rechazado');
});

test('un extrano recibe el mismo 404 en los tres canales y el MCP respeta la ability', function () {
    $post = wfPost(['requires_approval' => true]);
    $stranger = User::factory()->create();
    $key = $this->mcpKey($stranger);

    $this->actingAs($stranger)->postJson(route('workflow.approval.approve', $post->id))->assertNotFound();
    wfApi($key, 'POST', "/api/v1/posts/{$post->id}/approval/approve")->assertNotFound();
    [$err, $msg] = $this->callTool($key, 'approve_post', ['post_id' => $post->id]);
    [$err2, $msg2] = $this->callTool($key, 'approve_post', ['post_id' => 999999]);
    expect($err)->toBeTrue()->and($msg)->toBe($msg2);

    $readOnly = $this->mcpKey($this->approver, ['posts:read']);
    [$denied] = $this->callTool($readOnly, 'approve_post', ['post_id' => $post->id]);
    expect($denied)->toBeTrue()->and($post->fresh()->approval_status)->toBe('pendiente');
    [$ok, $list] = $this->callTool($readOnly, 'list_pending_approvals');
    expect($ok)->toBeFalse()->and($list['pending'][0]['id'])->toBe($post->id);
});

test('manual: marcar publicado por web, API y MCP', function () {
    $mk = fn () => wfPost(['schedule' => true, 'targets' => [['account_id' => $this->fb->id, 'content_type' => 'facebook_text', 'manual' => true]]]);
    [$a, $b, $c] = [$mk(), $mk(), $mk()];
    $tid = fn (Post $p) => $p->targets()->first()->id;
    $url = 'https://facebook.com/p/1';

    $w = $this->actingAs($this->owner)->postJson(route('workflow.manual.published', [$a->id, $tid($a)]), ['url' => $url])->assertOk()->json();
    $x = wfApi($this->ownerKey, 'POST', "/api/v1/posts/{$b->id}/targets/{$tid($b)}/manual-published", ['url' => $url])->assertOk()->json();
    [$err, $m] = $this->callTool($this->ownerKey, 'mark_post_published', ['post_id' => $c->id, 'target_id' => $tid($c), 'url' => $url]);

    expect($err)->toBeFalse()->and($w['target']['status'])->toBe('publicada')->and($x['target']['status'])->toBe('publicada')->and($m['target']['status'])->toBe('publicada')
        ->and($m['target']['external_url'])->toBe($url)->and($m['post']['targets'][0]['manual'])->toBeTrue();
    [$bad] = $this->callTool($this->ownerKey, 'mark_post_published', ['post_id' => $mk()->id, 'target_id' => 1, 'url' => 'http://x.com']);
    expect($bad)->toBeTrue();
});

test('reintentar: mismo resultado por los tres canales y es idempotente', function () {
    $mk = function () {
        $p = wfPost(['schedule' => true, 'scheduled_at' => '2026-11-10T10:00']);
        Post::withoutUserScope()->whereKey($p->id)->update(['scheduled_at' => now()->subHour()]);
        $p->targets()->update(['status' => 'fallida', 'error_code' => 'x']);
        $p->syncStatus();

        return $p;
    };
    [$a, $b, $c] = [$mk(), $mk(), $mk()];

    $w = $this->actingAs($this->owner)->postJson(route('workflow.retry', $a->id))->assertOk()->json();
    $x = wfApi($this->ownerKey, 'POST', "/api/v1/posts/{$b->id}/retry")->assertOk()->json();
    [$err, $m] = $this->callTool($this->ownerKey, 'retry_post', ['post_id' => $c->id]);
    [, $again] = $this->callTool($this->ownerKey, 'retry_post', ['post_id' => $c->id]);

    expect($err)->toBeFalse()->and([$w['retried'], $x['retried'], $m['retried']])->toBe([1, 1, 1])->and($again['retried'])->toBe(0);
});

test('lotes: la misma accion da el mismo resultado por elemento en los tres canales, con Idempotency-Key en API y MCP', function () {
    $mk = fn () => [wfPost(['schedule' => true]), wfPost(['schedule' => true])];
    [$w1, $w2] = $mk();
    [$a1, $a2] = $mk();
    [$m1, $m2] = $mk();
    $shape = fn (array $r) => array_map(fn ($i) => ['ok' => $i['ok'], 'status' => $i['status'] ?? null, 'error' => isset($i['error'])], $r['results']);

    $w = $this->actingAs($this->owner)->postJson(route('workflow.batch'), ['action' => 'reschedule', 'post_ids' => [$w1->id, 999999, $w2->id], 'shift_days' => 1])->assertOk()->json();
    $x = wfApi($this->ownerKey, 'POST', '/api/v1/posts/batch', ['action' => 'reschedule', 'post_ids' => [$a1->id, 999999, $a2->id], 'shift_days' => 1], ['Idempotency-Key' => 'api-1'])->assertOk()->json();
    [$err, $m] = $this->callTool($this->ownerKey, 'batch_posts', ['action' => 'reschedule', 'post_ids' => [$m1->id, 999999, $m2->id], 'shift_days' => 1, 'idempotency_key' => 'mcp-1']);
    [, $replay] = $this->callTool($this->ownerKey, 'batch_posts', ['action' => 'reschedule', 'post_ids' => [$m1->id, 999999, $m2->id], 'shift_days' => 1, 'idempotency_key' => 'mcp-1']);
    $again = wfApi($this->ownerKey, 'POST', '/api/v1/posts/batch', ['action' => 'reschedule', 'post_ids' => [$a1->id, 999999, $a2->id], 'shift_days' => 1], ['Idempotency-Key' => 'api-1'])->assertOk()->assertHeader('Idempotency-Replayed', 'true')->json();

    expect($err)->toBeFalse()->and($shape($w))->toBe($shape($x))->and($shape($m))->toBe($shape($x))
        ->and([$w['ok'], $w['failed']])->toBe([2, 1])->and($replay['replayed'])->toBeTrue()->and($again['results'])->toBe($x['results'])
        ->and($a1->fresh()->scheduled_at->format('Y-m-d'))->toBe('2026-11-13');
});

test('series: crear, ver, editar y cancelar por API y MCP con la misma forma que la web', function () {
    $input = ['brand_id' => $this->brand->id, 'frequency' => 'diaria', 'time_local' => '09:00', 'starts_on' => '2026-11-10', 'max_occurrences' => 3, 'text' => 'S',
        'targets' => [['account_id' => $this->fb->id, 'content_type' => 'facebook_text']]];
    $strip = fn (array $s) => array_diff_key($s, ['id' => 1]);

    $w = $this->actingAs($this->owner)->postJson(route('workflow.series.store'), $input)->assertCreated()->json('series');
    $x = wfApi($this->ownerKey, 'POST', '/api/v1/series', $input)->assertCreated()->json('series');
    [$err, $m] = $this->callTool($this->ownerKey, 'manage_post_series', ['action' => 'create'] + $input);
    expect($err)->toBeFalse();
    $norm = fn (array $s) => array_diff_key($s, ['id' => 1, 'occurrences' => 1]);
    expect($norm($w))->toBe($norm($x))->and($norm($m['series']))->toBe($norm($x))->and($x['generated'])->toBe(3);

    [, $got] = $this->callTool($this->ownerKey, 'manage_post_series', ['action' => 'get', 'series_id' => $m['series']['id']]);
    [, $upd] = $this->callTool($this->ownerKey, 'manage_post_series', ['action' => 'update', 'series_id' => $m['series']['id'], 'text' => 'Nuevo']);
    [, $cancel] = $this->callTool($this->ownerKey, 'manage_post_series', ['action' => 'cancel', 'series_id' => $m['series']['id']]);
    [, $list] = $this->callTool($this->ownerKey, 'manage_post_series', ['action' => 'list']);
    expect($got['series']['id'])->toBe($m['series']['id'])->and($upd['series']['text'])->toBe('Nuevo')->and($cancel['series']['status'])->toBe('cancelada')->and($list['series'])->toHaveCount(3);

    $stranger = $this->mcpKey(User::factory()->create());
    [$denied] = $this->callTool($stranger, 'manage_post_series', ['action' => 'get', 'series_id' => $m['series']['id']]);
    expect($denied)->toBeTrue();
    wfApi($this->ownerKey, 'PATCH', "/api/v1/series/{$x['id']}", ['text' => 'API'])->assertOk()->assertJsonPath('series.text', 'API');
    wfApi($this->ownerKey, 'DELETE', "/api/v1/series/{$x['id']}")->assertOk()->assertJsonPath('series.status', 'cancelada');
});

test('colaboradores: la invitacion responde igual por web, API y MCP exista o no el email', function () {
    $guest = User::factory()->create();
    $brand = $this->brand->id;

    $w = $this->actingAs($this->owner)->postJson(route('workflow.collaborators.store', $brand), ['email' => $guest->email])->assertStatus(202)->json();
    $x = wfApi($this->ownerKey, 'POST', "/api/v1/brands/$brand/collaborators", ['email' => 'no-existe@ejemplo.test'])->assertStatus(202)->json();
    [$err, $m] = $this->callTool($this->ownerKey, 'manage_brand_collaborators', ['action' => 'invite', 'brand_id' => $brand, 'email' => 'tampoco@ejemplo.test']);

    expect($err)->toBeFalse()->and($x)->toBe($w)->and($m)->toBe($w);
    [, $list] = $this->callTool($this->ownerKey, 'manage_brand_collaborators', ['action' => 'list', 'brand_id' => $brand]);
    expect(collect($list['collaborators'])->pluck('email')->all())->toContain($guest->email, $this->approver->email);
    $id = collect($list['collaborators'])->firstWhere('email', $guest->email)['id'];
    [, $rev] = $this->callTool($this->ownerKey, 'manage_brand_collaborators', ['action' => 'revoke', 'brand_id' => $brand, 'collaborator_id' => $id]);
    expect($rev['revoked'])->toBeTrue()->and(BrandCollaborator::query()->where('user_id', $guest->id)->exists())->toBeFalse();

    // El aprobador invitado no gestiona colaboradores.
    [$denied] = $this->callTool($this->approverKey, 'manage_brand_collaborators', ['action' => 'list', 'brand_id' => $brand]);
    expect($denied)->toBeTrue();
});

test('la API de posts acepta requires_approval y manual y los devuelve en get_post', function () {
    $body = ['text' => 'T', 'schedule' => true, 'scheduled_at' => '2026-11-12T10:00', 'brand_id' => $this->brand->id, 'requires_approval' => true,
        'targets' => [['account_id' => $this->fb->id, 'content_type' => 'facebook_text', 'manual' => true]]];

    $created = wfApi($this->ownerKey, 'POST', '/api/v1/posts', $body)->assertCreated()->json();
    expect($created['approval_status'])->toBe('pendiente')->and($created['status'])->toBe('borrador')->and($created['targets'][0]['manual'])->toBeTrue();

    [, $got] = $this->callTool($this->ownerKey, 'get_post', ['post_id' => $created['id']]);
    expect($got['approval_status'])->toBe('pendiente')->and($got['targets'][0]['manual'])->toBeTrue();

    [$err, $viaMcp] = $this->callTool($this->ownerKey, 'create_post', ['brand_id' => $this->brand->id, 'text' => 'T', 'schedule' => true, 'scheduled_at' => '2026-11-12 10:00', 'requires_approval' => true,
        'targets' => [['account_id' => $this->fb->id, 'content_type' => 'facebook_text']]]);
    expect($err)->toBeFalse()->and($viaMcp['approval_status'])->toBe('pendiente');

    // PATCH de la API sobre un aprobado conserva manual y no lo pisa.
    Post::withoutUserScope()->whereKey($created['id'])->update(['approval_status' => 'aprobado']);
    wfApi($this->ownerKey, 'PATCH', '/api/v1/posts/'.$created['id'], ['schedule' => true])->assertOk()->assertJsonPath('status', 'programada')->assertJsonPath('targets.0.manual', true)->assertJsonPath('approval_status', 'aprobado');
});
