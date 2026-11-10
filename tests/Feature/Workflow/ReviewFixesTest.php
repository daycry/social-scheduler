<?php

use App\Connectors\Network;
use App\Mcp\Abilities;
use App\Models\Brand;
use App\Models\BrandCollaborator;
use App\Models\IdempotencyKey;
use App\Models\Post;
use App\Models\PostSeries;
use App\Models\SocialAccount;
use App\Models\User;
use App\Services\PostService;
use App\Services\SeriesService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\Support\McpClient;

uses(McpClient::class);

// T-19, revision intento 1: firma de contenido en la aprobacion, ability propia, excepcion de serie, limites compartidos y caducidad.

beforeEach(function () {
    Http::preventStrayRequests();
    Queue::fake();
    Carbon::setTestNow('2026-11-09 12:00:00');
    $this->owner = User::factory()->create();
    $this->approver = User::factory()->create();
    $this->brand = Brand::factory()->for($this->owner)->create(['timezone' => 'UTC']);
    $this->fb = SocialAccount::factory()->for($this->owner)->create(['brand_id' => $this->brand->id, 'network' => Network::Facebook]);
    BrandCollaborator::query()->create(['brand_id' => $this->brand->id, 'owner_id' => $this->owner->id, 'user_id' => $this->approver->id, 'role' => 'aprobador']);
});

function rfPost(array $over = []): Post
{
    return app(PostService::class)->create(test()->owner, test()->brand, array_merge([
        'text' => 'V1', 'schedule' => false, 'scheduled_at' => '2026-11-12T10:00', 'media' => [], 'requires_approval' => true,
        'targets' => [['account_id' => test()->fb->id, 'content_type' => 'facebook_text']],
    ], $over));
}

function rfEdit(Post $post, string $text): void
{
    app(PostService::class)->update(test()->owner, $post->fresh(), ['text' => $text, 'schedule' => false, 'scheduled_at' => '2026-11-12T10:00', 'media' => [], 'targets' => [['account_id' => test()->fb->id, 'content_type' => 'facebook_text']]]);
}

test('1. aprobar la version que vio el aprobador no aprueba una version editada despues (409)', function () {
    $post = rfPost();
    $v1 = $post->fresh()->approval_signature;
    expect($v1)->toBeString();

    rfEdit($post, 'V2');
    $v2 = $post->fresh()->approval_signature;
    expect($v2)->not->toBe($v1)->and($post->fresh()->approval_status)->toBe('pendiente');

    $this->actingAs($this->approver)->postJson(route('workflow.approval.approve', $post->id), ['signature' => $v1])->assertStatus(409)->assertJsonPath('message', fn ($m) => str_contains($m, 'cambiado'));
    $this->actingAs($this->approver)->postJson(route('workflow.approval.reject', $post->id), ['signature' => $v1, 'comment' => 'x'])->assertStatus(409);
    expect($post->fresh()->approval_status)->toBe('pendiente');
    // Sin firma tampoco se aprueba a ciegas.
    $this->actingAs($this->approver)->postJson(route('workflow.approval.approve', $post->id))->assertStatus(422);

    $this->actingAs($this->approver)->postJson(route('workflow.approval.approve', $post->id), ['signature' => $v2])->assertOk()->assertJsonPath('post.approval_status', 'aprobado');
});

test('1. por API y MCP tambien exigen la firma', function () {
    $post = rfPost();
    $old = $post->fresh()->approval_signature;
    rfEdit($post, 'V2');
    $key = $this->approver->createToken('k', ['posts:approve'])->plainTextToken;

    app('auth')->forgetGuards();
    $this->withToken($key)->postJson("/api/v1/posts/{$post->id}/approval/approve", ['signature' => $old])->assertStatus(409);
    [$err, $msg] = $this->callTool($key, 'approve_post', ['post_id' => $post->id, 'signature' => $old]);
    expect($err)->toBeTrue()->and($msg)->toContain('409');
    [$ok] = $this->callTool($key, 'approve_post', ['post_id' => $post->id, 'signature' => $post->fresh()->approval_signature]);
    expect($ok)->toBeFalse();
});

test('1. el lote de aprobar exige las firmas y cada elemento se valida con la suya', function () {
    $a = rfPost();
    $b = rfPost();
    $sigA = $a->fresh()->approval_signature;
    $sigB = $b->fresh()->approval_signature;
    rfEdit($b, 'cambiado');

    $this->actingAs($this->approver)->postJson(route('workflow.batch'), ['action' => 'approve', 'post_ids' => [$a->id]])->assertStatus(422);
    $res = $this->actingAs($this->approver)->postJson(route('workflow.batch'), ['action' => 'approve', 'post_ids' => [$a->id, $b->id], 'signatures' => [$a->id => $sigA, $b->id => $sigB]])->assertOk();
    expect($res->json('ok'))->toBe(1)->and($b->fresh()->approval_status)->toBe('pendiente')->and($a->fresh()->approval_status)->toBe('aprobado');
});

test('2. aprobar y rechazar por API/MCP exigen la ability posts:approve, no basta posts:write', function () {
    $post = rfPost();
    $sig = $post->fresh()->approval_signature;
    $write = $this->approver->createToken('w', ['posts:write'])->plainTextToken;

    app('auth')->forgetGuards();
    $this->withToken($write)->postJson("/api/v1/posts/{$post->id}/approval/approve", ['signature' => $sig])->assertForbidden();
    [$e1] = $this->callTool($write, 'approve_post', ['post_id' => $post->id, 'signature' => $sig]);
    [$e2] = $this->callTool($write, 'reject_post', ['post_id' => $post->id, 'comment' => 'x', 'signature' => $sig]);
    [$e3] = $this->callTool($write, 'batch_posts', ['action' => 'approve', 'post_ids' => [$post->id], 'signatures' => [(string) $post->id => $sig]]);
    app('auth')->forgetGuards();
    $this->withToken($write)->postJson('/api/v1/posts/batch', ['action' => 'approve', 'post_ids' => [$post->id], 'signatures' => [$post->id => $sig]])->assertForbidden();
    expect([$e1, $e2, $e3])->toBe([true, true, true])->and($post->fresh()->approval_status)->toBe('pendiente');

    $approve = $this->approver->createToken('a', ['posts:approve'])->plainTextToken;
    [$ok] = $this->callTool($approve, 'approve_post', ['post_id' => $post->id, 'signature' => $sig]);
    expect($ok)->toBeFalse()->and($post->fresh()->approval_status)->toBe('aprobado');
    expect(Abilities::keys())->toContain('posts:approve');
});

test('3. reschedule de una ocurrencia la marca como excepcion y la serie no la devuelve a su fecha', function () {
    $series = app(SeriesService::class)->create($this->owner, $this->brand, [
        'frequency' => 'diaria', 'time_local' => '10:00', 'starts_on' => '2026-11-10', 'max_occurrences' => 3, 'text' => 'S', 'media' => [],
        'targets' => [['account_id' => $this->fb->id, 'content_type' => 'facebook_text']],
    ]);
    $first = Post::withoutUserScope()->where('series_id', $series->id)->orderBy('scheduled_at')->first();

    app(PostService::class)->reschedule($this->owner, $first, '2026-11-20T08:00');
    expect($first->fresh()->series_exception)->toBeTrue();

    app(SeriesService::class)->update($this->owner, $series->fresh(), ['text' => 'Nuevo']);
    expect($first->fresh()->scheduled_at->format('Y-m-d H:i'))->toBe('2026-11-20 08:00')->and($first->fresh()->text)->toBe('S');
});

test('4. batch_posts e invitar por MCP comparten el limite de 10/min por usuario', function () {
    $key = $this->mcpKey($this->owner);
    $last = null;
    for ($i = 0; $i < 11; $i++) {
        $last = $this->callTool($key, 'batch_posts', ['action' => 'cancel', 'post_ids' => [999999]]);
    }
    expect($last[0])->toBeTrue()->and($last[1])->toContain('429');

    for ($i = 0; $i < 11; $i++) {
        $last = $this->callTool($key, 'manage_brand_collaborators', ['action' => 'invite', 'brand_id' => $this->brand->id, 'email' => "n$i@ejemplo.test"]);
    }
    expect($last[0])->toBeTrue()->and($last[1])->toContain('429');
});

test('5. una clave reservada sin respuesta por un proceso muerto caduca en 5 minutos', function () {
    $body = ['action' => 'cancel', 'post_ids' => [999999]];
    $row = IdempotencyKey::query()->create(['user_id' => $this->owner->id, 'scope' => 'post-batch', 'key' => 'muerta', 'request_hash' => hash('sha256', json_encode($body))]);

    $this->actingAs($this->owner)->postJson(route('workflow.batch'), $body, ['Idempotency-Key' => 'muerta'])->assertStatus(409);
    Carbon::setTestNow(now()->addMinutes(6));
    $this->actingAs($this->owner)->postJson(route('workflow.batch'), $body, ['Idempotency-Key' => 'muerta'])->assertOk()->assertHeader('Idempotency-Replayed', 'false');
    expect(IdempotencyKey::query()->where('key', 'muerta')->first()->response)->not->toBeNull()->and($row->fresh())->toBeNull();
});

test('6. starts_on no puede estar mas de un anio en el pasado y una serie antigua sin fin no itera desde el origen', function () {
    $input = ['brand_id' => $this->brand->id, 'frequency' => 'diaria', 'time_local' => '10:00', 'starts_on' => '2024-01-01', 'text' => 'S', 'media' => [], 'targets' => [['account_id' => $this->fb->id, 'content_type' => 'facebook_text']]];
    $res = $this->actingAs($this->owner)->postJson(route('workflow.series.store'), $input)->assertStatus(422);
    expect(json_encode($res->json('errors')))->toContain('anio');

    $series = app(SeriesService::class)->create($this->owner, $this->brand, ['starts_on' => '2025-12-01'] + $input);
    $dates = app(SeriesService::class)->dates($series, CarbonImmutable::parse('2026-11-12'));
    expect($dates[0])->toBeGreaterThanOrEqual('2026-11-02')->and(end($dates))->toBe('2026-11-12');
    expect(PostSeries::withoutUserScope()->count())->toBe(1);
});

test('invitar o retirar aprobadores por API y MCP exige posts:approve; posts:write no basta y listar sigue con posts:read', function () {
    $write = $this->owner->createToken('w', ['posts:write', 'posts:read'])->plainTextToken;
    $approve = $this->owner->createToken('a', ['posts:approve', 'posts:read'])->plainTextToken;
    $url = "/api/v1/brands/{$this->brand->id}/collaborators";

    app('auth')->forgetGuards();
    $this->withToken($write)->postJson($url, ['email' => 'nuevo@ejemplo.test'])->assertForbidden();
    app('auth')->forgetGuards();
    $this->withToken($write)->deleteJson("$url/1")->assertForbidden();
    [$denied] = $this->callTool($write, 'manage_brand_collaborators', ['action' => 'invite', 'brand_id' => $this->brand->id, 'email' => 'nuevo@ejemplo.test']);
    [$deniedRevoke] = $this->callTool($write, 'manage_brand_collaborators', ['action' => 'revoke', 'brand_id' => $this->brand->id, 'collaborator_id' => 1]);
    [$list] = $this->callTool($write, 'manage_brand_collaborators', ['action' => 'list', 'brand_id' => $this->brand->id]);
    app('auth')->forgetGuards();
    $this->withToken($write)->getJson($url)->assertOk();
    expect([$denied, $deniedRevoke, $list])->toBe([true, true, false]);

    app('auth')->forgetGuards();
    $this->withToken($approve)->postJson($url, ['email' => 'nuevo@ejemplo.test'])->assertStatus(202);
    [$ok] = $this->callTool($approve, 'manage_brand_collaborators', ['action' => 'invite', 'brand_id' => $this->brand->id, 'email' => 'otro@ejemplo.test']);
    expect($ok)->toBeFalse();
});
