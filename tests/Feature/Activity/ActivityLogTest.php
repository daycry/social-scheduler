<?php

use App\Http\Controllers\Api\ApiController;
use App\Mcp\Abilities;
use App\Mcp\Tools\SchedulerTool;
use App\Models\AuditLog;
use App\Models\Brand;
use App\Models\Post;
use App\Models\User;
use App\Services\AuditLog as AuditWriter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request as HttpRequest;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use Laravel\Mcp\Request as McpRequest;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Sanctum\TransientToken;
use Tests\Support\McpClient;

uses(McpClient::class);

#[Name('activity_context_probe')]
class ActivityAuditProbeTool extends SchedulerTool
{
    protected function ability(): string
    {
        return Abilities::POSTS_READ;
    }

    protected function resource(): ?string
    {
        return 'post';
    }

    protected function run(McpRequest $request, User $user): array
    {
        foreach ($request->get('post_ids', []) as $id) {
            $this->owned(Post::class, $user, $id, 'Publicación');
        }

        if ($request->get('http_status')) {
            abort((int) $request->get('http_status'), 'Conflicto de prueba');
        }

        return ['id' => $request->get('result_id')];
    }
}

class ActivityApiProbeController extends ApiController
{
    public function execute(HttpRequest $request, array $brands): JsonResponse
    {
        // Real adapters can verify the brand before entering respond().
        foreach ($brands as $brand) {
            $this->ownedBrand($request, $brand);
        }

        return $this->respond($request, 'api_context_probe', 'post', null, fn () => ['id' => null]);
    }
}

test('Activity: an authenticated user can open an empty audit history', function () {
    $user = User::factory()->create();

    $this->actingAs($user)->get('/activity')->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component('Activity/Index')
            ->has('entries', 0)
            ->where('pagination.total', 0)
            ->where('retention_days', 365));
});

function activityEntry(User $actor, array $attributes = []): AuditLog
{
    return AuditLog::query()->create($attributes + [
        'user_id' => $actor->id, 'action' => 'get_post', 'outcome' => 'ok',
        'channel' => 'api', 'resource' => 'post', 'resource_id' => null,
        'created_at' => '2026-11-17 12:00:00',
    ]);
}

test('Activity: only personal actions and owned brands are visible and no private audit fields are sent', function () {
    $owner = User::factory()->create();
    $collaborator = User::factory()->create();
    $foreign = User::factory()->create();
    $brand = Brand::factory()->for($owner)->create();
    $foreignBrand = Brand::factory()->for($foreign)->create();
    $personal = activityEntry($owner, ['brand_id' => $brand->id, 'detail' => 'private detail', 'ip' => '192.0.2.42', 'token_id' => 876]);
    $shared = activityEntry($collaborator, ['brand_id' => $brand->id]);
    activityEntry($foreign, ['brand_id' => $foreignBrand->id, 'action' => 'foreign_secret']);
    activityEntry($collaborator, ['action' => 'collaborator_personal']);
    AuditLog::query()->create(['action' => 'anonymous_secret', 'outcome' => 'denied', 'channel' => 'mcp']);

    $props = $this->actingAs($owner)->get('/activity')->assertOk()->inertiaPage()['props'];
    expect(array_column($props['entries'], 'id'))->toBe([$shared->id, $personal->id]);
    expect(array_column($props['users'], 'id'))->toEqualCanonicalizing([$owner->id, $collaborator->id]);
    expect($props['actions'])->toBe(['get_post']);
    expect(array_keys($props['entries'][0]))->toEqualCanonicalizing([
        'id', 'action', 'outcome', 'channel', 'user', 'brand', 'created_at',
    ]);
    expect($props['entries'][0]['brand'])->toBe($brand->name);
    expect(json_encode($props))->not->toContain('private detail', '192.0.2.42', 'foreign_secret', 'anonymous_secret', 'collaborator_personal');
});

test('Activity: brand actor and action filters compose without widening authorization', function () {
    $owner = User::factory()->create();
    $actor = User::factory()->create();
    $brand = Brand::factory()->for($owner)->create();
    $otherBrand = Brand::factory()->for($owner)->create();
    $target = activityEntry($actor, ['brand_id' => $brand->id, 'action' => 'update_post']);
    activityEntry($actor, ['brand_id' => $brand->id, 'action' => 'get_post']);
    activityEntry($owner, ['brand_id' => $brand->id, 'action' => 'update_post']);
    activityEntry($actor, ['brand_id' => $otherBrand->id, 'action' => 'update_post']);

    $this->actingAs($owner)->get('/activity?'.http_build_query([
        'brand_id' => $brand->id, 'user_id' => $actor->id, 'action' => 'update_post',
    ]))->assertOk()->assertInertia(fn (Assert $page) => $page
        ->has('entries', 1)->where('entries.0.id', $target->id)->where('pagination.total', 1)
        ->where('filters.brand_id', $brand->id)->where('filters.user_id', $actor->id));
});

test('Activity: foreign and nonexistent brand or actor selectors have identical 404 responses', function () {
    $owner = User::factory()->create();
    $foreign = User::factory()->create();
    $foreignBrand = Brand::factory()->for($foreign)->create();
    $this->actingAs($owner);
    foreach (['brand_id' => $foreignBrand->id, 'user_id' => $foreign->id] as $key => $id) {
        $this->getJson('/activity?'.$key.'='.$id)->assertNotFound();
        $this->getJson('/activity?'.$key.'=9999999')->assertNotFound();
    }
});

test('Activity: dates cover the complete local day including a 25 hour DST day', function () {
    $owner = User::factory()->create();
    $brand = Brand::factory()->for($owner)->create(['timezone' => 'Europe/Madrid']);
    $rows = collect(['2026-10-24 21:59:59', '2026-10-24 22:00:00', '2026-10-25 22:59:59', '2026-10-25 23:00:00'])
        ->map(fn ($time) => activityEntry($owner, ['brand_id' => $brand->id, 'created_at' => $time]));
    $props = $this->actingAs($owner)->get('/activity?'.http_build_query([
        'brand_id' => $brand->id, 'from' => '2026-10-25', 'to' => '2026-10-25',
    ]))->assertOk()->inertiaPage()['props'];
    expect(array_column($props['entries'], 'id'))->toBe([$rows[2]->id, $rows[1]->id]);
    expect($props['timezone'])->toBe('Europe/Madrid');
});

test('Activity: invalid filters are rejected', function (array $query, string $error) {
    $this->actingAs(User::factory()->create())->getJson('/activity?'.http_build_query($query))
        ->assertUnprocessable()->assertJsonValidationErrors($error);
})->with([
    [['from' => '2026-02-30'], 'from'],
    [['from' => '2026-11-18', 'to' => '2026-11-17'], 'to'],
    [['limit' => 101], 'limit'],
    [['brand_id' => 0], 'brand_id'],
    [['user_id' => 0], 'user_id'],
]);

test('Activity: cursor pages are stable and bound to both filters and authenticated user', function () {
    $owner = User::factory()->create();
    $other = User::factory()->create();
    $rows = collect(range(1, 5))->map(fn () => activityEntry($owner));
    $this->actingAs($owner);
    $ids = [];
    $cursor = null;
    $firstCursor = null;
    foreach ([2, 2, 1] as $size) {
        $props = $this->get('/activity?'.http_build_query(['limit' => 2, 'cursor' => $cursor]))->assertOk()->inertiaPage()['props'];
        expect($props['pagination']['total'])->toBe(5);
        expect($props['entries'])->toHaveCount($size);
        array_push($ids, ...array_column($props['entries'], 'id'));
        $cursor = $props['pagination']['next_cursor'];
        $firstCursor ??= $cursor;
    }
    expect($ids)->toBe($rows->pluck('id')->reverse()->values()->all());
    expect($cursor)->toBeNull();
    $this->getJson('/activity?'.http_build_query(['cursor' => $firstCursor, 'action' => 'get_post']))
        ->assertUnprocessable()->assertJsonValidationErrors('cursor');
    $this->actingAs($other)->getJson('/activity?'.http_build_query(['cursor' => $firstCursor]))
        ->assertUnprocessable()->assertJsonValidationErrors('cursor');
});

test('Activity: configured and disabled retention are reflected without making up an expiry date', function (int $days) {
    config(['retention.audit_logs_days' => $days]);
    $this->actingAs(User::factory()->create())->get('/activity')->assertOk()
        ->assertInertia(fn (Assert $page) => $page->where('retention_days', $days));
})->with([0, 45]);

test('Activity: history requires a verified authenticated account', function () {
    $this->get('/activity')->assertRedirect('/login');
    $this->actingAs(User::factory()->unverified()->create())->get('/activity')->assertRedirect('/verify-email');
});

test('Activity: the writer attributes known resources and preserves their brand after removal', function () {
    $owner = User::factory()->create();
    $foreign = User::factory()->create();
    $brand = Brand::factory()->for($owner)->create();
    $foreignBrand = Brand::factory()->for($foreign)->create();
    $post = Post::factory()->for($owner)->for($brand)->create();
    $foreignPost = Post::factory()->for($foreign)->for($foreignBrand)->create();
    $writer = app(AuditWriter::class);
    $writer->record($owner, 'get_post', AuditWriter::OK, 'post', $post->id, brandId: $brand->id);
    $writer->record($owner, 'get_post', AuditWriter::DENIED, 'post', $foreignPost->id, brandId: $foreignBrand->id);
    $writer->record($owner, 'get_brand', AuditWriter::OK, 'brand', $brand->id, brandId: $brand->id);
    $writer->record($owner, 'api_key.created', AuditWriter::OK, 'api_key', 123);
    expect(AuditLog::query()->orderBy('id')->pluck('brand_id')->all())->toBe([$brand->id, null, $brand->id, null]);
    DB::table('posts')->where('id', $post->id)->delete();
    expect(AuditLog::query()->first()->brand_id)->toBe($brand->id);
    $brand->delete();
    expect(AuditLog::query()->whereNotNull('brand_id')->count())->toBe(0);
});

test('Activity: historical references stay personal because their brand cannot be proved', function () {
    $owner = User::factory()->create();
    $actor = User::factory()->create();
    $brand = Brand::factory()->for($owner)->create();
    $post = Post::factory()->for($owner)->for($brand)->create();
    $foreign = activityEntry($actor, ['brand_id' => null, 'resource_id' => $post->id]);
    $ownError = activityEntry($owner, ['brand_id' => null, 'resource_id' => $post->id, 'outcome' => 'error']);
    activityEntry($actor, ['brand_id' => null, 'resource_id' => $post->id, 'outcome' => 'denied']);
    $missing = activityEntry($owner, ['brand_id' => null, 'resource_id' => 9999999]);
    $props = $this->actingAs($owner)->get('/activity')->assertOk()->inertiaPage()['props'];
    expect(array_column($props['entries'], 'id'))->toBe([$missing->id, $ownError->id]);
    expect(array_column($props['entries'], 'brand'))->toBe([null, null]);
    expect(array_column($props['entries'], 'id'))->not->toContain($foreign->id);
    $this->get('/activity?brand_id='.$brand->id)->assertOk()
        ->assertInertia(fn (Assert $page) => $page->has('entries', 0));
});

test('Activity: a successful audit with a foreign resource id never discloses its actor to that brand', function () {
    $actor = User::factory()->create();
    $foreign = User::factory()->create();
    $foreignBrand = Brand::factory()->for($foreign)->create();
    $foreignPost = Post::factory()->for($foreign)->for($foreignBrand)->create();
    app(AuditWriter::class)->record($actor, 'get_calendar', AuditWriter::OK, 'post', $foreignPost->id);

    expect(AuditLog::query()->first()->brand_id)->toBeNull();
    $this->actingAs($foreign)->get('/activity')->assertOk()
        ->assertInertia(fn (Assert $page) => $page->has('entries', 0)->has('users', 0)->has('actions', 0));
    $this->actingAs($actor)->get('/activity')->assertOk()
        ->assertInertia(fn (Assert $page) => $page->has('entries', 1));
});

test('Activity: most recent means the event date with id as a stable tie breaker', function () {
    $owner = User::factory()->create();
    $newest = activityEntry($owner, ['created_at' => '2026-11-17 12:00:00']);
    $oldest = activityEntry($owner, ['created_at' => '2026-11-17 10:00:00']);
    $middle = activityEntry($owner, ['created_at' => '2026-11-17 11:00:00']);
    $props = $this->actingAs($owner)->get('/activity')->assertOk()->inertiaPage()['props'];
    expect(array_column($props['entries'], 'id'))->toBe([$newest->id, $middle->id, $oldest->id]);
});

test('Activity: MCP attribution uses the verified brand rather than a colliding resource id', function () {
    $owner = User::factory()->create();
    $selectedBrand = Brand::factory()->for($owner)->create();
    $otherBrand = Brand::factory()->for($owner)->create();
    Post::factory()->for($owner)->for($otherBrand)->create(['id' => $selectedBrand->id]);
    [$error] = $this->callTool($this->mcpKey($owner), 'get_calendar', ['brand_id' => $selectedBrand->id]);
    expect($error)->toBeFalse();
    expect(AuditLog::query()->where('action', 'get_calendar')->first()->brand_id)->toBe($selectedBrand->id);
});

test('Activity: reused MCP tools reset context and never guess from result ids or multiple brands', function () {
    $owner = User::factory()->create();
    $firstBrand = Brand::factory()->for($owner)->create();
    $secondBrand = Brand::factory()->for($owner)->create();
    $first = Post::factory()->for($owner)->for($firstBrand)->create();
    $second = Post::factory()->for($owner)->for($secondBrand)->create();
    $this->actingAs($owner->withAccessToken(new TransientToken));
    $tool = new ActivityAuditProbeTool;
    foreach ([[$first->id], [$second->id], [], [$first->id, $second->id]] as $ids) {
        $tool->handle(new McpRequest(['post_ids' => $ids, 'result_id' => $first->id]));
    }
    expect(AuditLog::query()->orderBy('id')->pluck('brand_id')->all())->toBe([$firstBrand->id, $secondBrand->id, null, null]);
});

test('Activity: denied MCP calls and inconsistent foreign brands remain personal', function () {
    $owner = User::factory()->create();
    $other = User::factory()->create();
    $ownBrand = Brand::factory()->for($owner)->create();
    $foreignBrand = Brand::factory()->for($other)->create();
    $ownPost = Post::factory()->for($owner)->for($ownBrand)->create();
    $foreignPost = Post::factory()->for($other)->for($foreignBrand)->create();
    $inconsistent = Post::factory()->for($owner)->for($foreignBrand)->create();
    $this->actingAs($owner->withAccessToken(new TransientToken));
    $tool = new ActivityAuditProbeTool;
    $tool->handle(new McpRequest(['post_ids' => [$ownPost->id, $foreignPost->id]]));
    $tool->handle(new McpRequest(['post_ids' => [$inconsistent->id]]));
    expect(AuditLog::query()->orderBy('id')->pluck('brand_id')->all())->toBe([null, null]);
    expect(AuditLog::query()->first()->outcome)->toBe('denied');
    [$error] = $this->callTool($this->mcpKey($owner, ['reports:read']), 'get_calendar', ['brand_id' => $ownBrand->id]);
    expect($error)->toBeTrue();
    expect(AuditLog::query()->latest('id')->first()->brand_id)->toBeNull();
});

test('Activity: the writer checks explicit brand context even on a successful call', function () {
    $owner = User::factory()->create();
    $foreign = User::factory()->create();
    $foreignBrand = Brand::factory()->for($foreign)->create();
    app(AuditWriter::class)->record($owner, 'get_post', AuditWriter::OK, 'post', 123, brandId: $foreignBrand->id);
    expect(AuditLog::query()->first()->brand_id)->toBeNull();
});

test('Activity: REST brand lookup supplies verified audit context', function () {
    $owner = User::factory()->create();
    $brand = Brand::factory()->for($owner)->create();
    $this->getJson('/api/v1/brands/'.$brand->id.'/posts', ['Authorization' => 'Bearer '.$this->mcpKey($owner)])
        ->assertOk();
    expect(AuditLog::query()->where('action', 'list_posts')->where('channel', 'api')->first()->brand_id)->toBe($brand->id);
});

test('Activity: either date boundary can be used independently', function (array $query) {
    $this->actingAs(User::factory()->create())->get('/activity?'.http_build_query($query))->assertOk();
})->with([
    [['from' => '2026-11-01']],
    [['to' => '2026-11-30']],
]);

test('Activity: reused API controllers isolate requests and decline multiple verified brands', function () {
    $owner = User::factory()->create();
    $one = Brand::factory()->for($owner)->create();
    $two = Brand::factory()->for($owner)->create();
    $controller = new ActivityApiProbeController(app(AuditWriter::class));
    foreach ([[$one->id], [], [$one->id, $two->id]] as $brands) {
        $request = HttpRequest::create('/api/context-probe');
        $request->setUserResolver(fn () => $owner);
        expect($controller->execute($request, $brands)->status())->toBe(200);
    }
    expect(AuditLog::query()->orderBy('id')->pluck('brand_id')->all())->toBe([$one->id, null, null]);
});

test('Activity: MCP HTTP errors retain only verified non-denied context', function (int $status) {
    $owner = User::factory()->create();
    $brand = Brand::factory()->for($owner)->create();
    $post = Post::factory()->for($owner)->for($brand)->create();
    $this->actingAs($owner->withAccessToken(new TransientToken));
    (new ActivityAuditProbeTool)->handle(new McpRequest(['post_ids' => [$post->id], 'http_status' => $status]));
    $entry = AuditLog::query()->sole();
    expect($entry->outcome)->toBe($status === 404 ? 'denied' : 'error')
        ->and($entry->brand_id)->toBe($status === 404 ? null : $brand->id);
})->with([403, 409, 429, 404]);

test('Activity: REST get_post provides the actual verified model brand', function () {
    $owner = User::factory()->create();
    $brand = Brand::factory()->for($owner)->create();
    $post = Post::factory()->for($owner)->for($brand)->create();
    $this->getJson('/api/v1/posts/'.$post->id, ['Authorization' => 'Bearer '.$this->mcpKey($owner)])
        ->assertOk();
    expect(AuditLog::query()->where('action', 'get_post')->where('channel', 'api')->sole()->brand_id)->toBe($brand->id);
});

test('Activity: MCP brand-name lookup provides the verified brand context', function () {
    $owner = User::factory()->create();
    $brand = Brand::factory()->for($owner)->create(['name' => 'Marca verificada']);
    [$error] = $this->callTool($this->mcpKey($owner), 'get_calendar', ['brand' => '  MARCA verificada  ']);
    expect($error)->toBeFalse();
    expect(AuditLog::query()->where('action', 'get_calendar')->sole()->brand_id)->toBe($brand->id);
});
