<?php

use App\Models\Brand;
use App\Models\Post;
use App\Models\PostTemplate;
use App\Models\Report;
use App\Models\Studio\Design;
use App\Models\User;
use App\Services\Studio\StudioDocument;
use App\Support\CursorPage;
use Illuminate\Pagination\Cursor;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;

it('traverses more than 200 trashed posts, with a stable total and no duplicate rows', function () {
    $user = User::factory()->create();
    $brand = Brand::factory()->for($user)->create();
    $other = User::factory()->create();
    $otherBrand = Brand::factory()->for($other)->create();
    $deletedAt = now()->subDay();
    $posts = Post::factory()->for($user)->for($brand)->count(205)->create(['deleted_at' => $deletedAt]);
    Post::factory()->for($user)->for($brand)->create();
    Post::factory()->for($other)->for($otherBrand)->create(['deleted_at' => $deletedAt]);

    $this->actingAs($user);
    $ids = [];
    $cursor = null;
    foreach ([100, 100, 5] as $size) {
        $page = $this->get(route('trash.index', ['limit' => 100, 'cursor' => $cursor]))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('pagination.total', 205)
                ->has('posts', $size)
                ->where('pagination.has_more', $size === 100)
                ->etc())
            ->inertiaPage()['props'];

        array_push($ids, ...array_column($page['posts'], 'id'));
        $cursor = $page['pagination']['next_cursor'];
        expect($cursor === null)->toBe($size !== 100);
    }

    expect($ids)->toBe(array_reverse($posts->modelKeys()));
});

it('paginates notices with the same scoped cursor contract and rejects the cursor for another user', function () {
    $user = User::factory()->create();
    $other = User::factory()->create();
    $time = now();
    $ids = array_map(fn () => (string) Str::uuid(), range(1, 102));
    $oldest = 'ffffffff-ffff-4fff-8fff-ffffffffffff';
    DB::table('notifications')->insert(array_map(fn ($id) => [
        'id' => $id,
        'type' => 'test_notice',
        'notifiable_type' => $user->getMorphClass(),
        'notifiable_id' => $user->id,
        'data' => json_encode(['message' => $id]),
        'created_at' => $id === $oldest ? $time->copy()->subDay() : $time,
        'updated_at' => $time,
    ], [...$ids, $oldest]));

    $first = $this->actingAs($user)->get(route('notifications.index', ['limit' => 100]))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('pagination.total', 103)
            ->where('pagination.has_more', true)
            ->has('notifications', 100)
            ->etc())
        ->inertiaPage()['props'];

    $cursor = $first['pagination']['next_cursor'];
    expect($cursor)->toBeString();
    $last = $this->get(route('notifications.index', ['limit' => 100, 'cursor' => $cursor]))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('pagination.total', 103)
            ->where('pagination.has_more', false)
            ->where('pagination.next_cursor', null)
            ->has('notifications', 3)
            ->etc())
        ->inertiaPage()['props'];

    $actual = array_merge(array_column($first['notifications'], 'id'), array_column($last['notifications'], 'id'));
    rsort($ids, SORT_STRING);
    expect($actual)->toBe([...$ids, $oldest]);

    $encoded = (new Cursor(['id' => $ids[0]], true))->encode();
    $cursorWithoutOrderColumn = rtrim(strtr(base64_encode(json_encode(['s' => 'notifications:u'.$user->id, 'c' => $encoded])), '+/', '-_'), '=');
    $this->getJson(route('notifications.index', ['cursor' => $cursorWithoutOrderColumn]))
        ->assertUnprocessable()
        ->assertJsonValidationErrors('cursor');

    $this->actingAs($other)->getJson(route('notifications.index', ['cursor' => $cursor]))
        ->assertUnprocessable()
        ->assertJsonValidationErrors('cursor');
});

it('keeps the selected brand and its total when paging publication templates', function () {
    $user = User::factory()->create();
    $brand = Brand::factory()->for($user)->create();
    $otherBrand = Brand::factory()->for($user)->create();
    $seed = fn (Brand $b, string $name) => PostTemplate::query()->forceCreate([
        'user_id' => $user->id,
        'brand_id' => $b->id,
        'name' => $name,
        'text' => 'Texto de plantilla.',
        'targets' => [],
    ]);
    $first = $seed($brand, 'Primera');
    // Older templates can have a null timestamp and must still appear on the next page.
    $first->forceFill(['created_at' => null])->save();
    $last = $seed($brand, 'Segunda');
    $seed($otherBrand, 'Otra marca');

    $page = $this->actingAs($user)->get(route('library.index', ['brand_id' => $brand->id, 'limit' => 1]))
        ->assertInertia(fn (Assert $page) => $page
            ->where('pagination.total', 2)
            ->where('pagination.has_more', true)
            ->where('templates.0.id', $last->id)
            ->etc())
        ->inertiaPage()['props'];
    $cursor = $page['pagination']['next_cursor'];

    $this->get(route('library.index', ['brand_id' => $brand->id, 'limit' => 1, 'cursor' => $cursor]))
        ->assertInertia(fn (Assert $page) => $page
            ->where('pagination.total', 2)
            ->where('pagination.next_cursor', null)
            ->where('templates.0.id', $first->id)
            ->etc());

    $this->getJson(route('library.index', ['brand_id' => $otherBrand->id, 'limit' => 1, 'cursor' => $cursor]))
        ->assertUnprocessable()
        ->assertJsonValidationErrors('cursor');
});

it('reaches older report rows and keeps the total for the account across brands', function () {
    $user = User::factory()->create();
    $brand = Brand::factory()->for($user)->create();
    $otherBrand = Brand::factory()->for($user)->create();
    $old = Report::factory()->for($user)->for($otherBrand)->create(['status' => Report::STATUS_FAILED]);
    $recent = Report::factory()->for($user)->for($brand)->count(22)->create(['status' => Report::STATUS_FAILED]);
    Report::factory()->create(['status' => Report::STATUS_FAILED]);

    $page = $this->actingAs($user)->get(route('reports.index', ['brand_id' => $brand->id]))
        ->assertInertia(fn (Assert $page) => $page
            ->where('pagination.total', 23)
            ->where('pagination.has_more', true)
            ->has('reports', 20)
            ->etc())
        ->inertiaPage()['props'];
    $cursor = $page['pagination']['next_cursor'];

    $counts = [];
    DB::listen(function ($query) use (&$counts) {
        if (preg_match('/\bcount\s*\(/i', $query->sql) && preg_match('/\bfrom\s+["`]?reports\b/i', $query->sql)) {
            $counts[] = $query->sql;
        }
    });
    $assertCursorPage = fn (Assert $page) => $page
        ->where('pagination.cursor', $cursor)
        ->where('pagination.total', 23)
        ->where('pagination.next_cursor', null)
        ->has('reports', 3)
        ->where('reports.2.id', $old->id)
        ->etc();

    $this->get(route('reports.index', ['brand_id' => $brand->id, 'cursor' => $cursor]))
        ->assertInertia(function (Assert $page) use ($assertCursorPage, &$counts) {
            $assertCursorPage($page);
            expect($counts)->toHaveCount(1);
            $counts = [];

            $page->reloadOnly(['reports', 'polling', 'pagination'], $assertCursorPage);
            expect($counts)->toHaveCount(1);
        });

    $this->actingAs(User::factory()->create())->getJson(route('reports.index', ['cursor' => $cursor]))
        ->assertUnprocessable()
        ->assertJsonValidationErrors('cursor');

    foreach ([1 => 1, 100 => 23] as $limit => $size) {
        $this->actingAs($user)->get(route('reports.index', ['limit' => $limit]))
            ->assertInertia(fn (Assert $page) => $page
                ->has('reports', $size)
                ->where('pagination.total', 23)
                ->where('pagination.has_more', $limit === 1)
                ->where('pagination.next_cursor', fn ($next) => $limit === 1 ? is_string($next) && $next !== '' : $next === null)
                ->etc());
    }

    $seen = [];
    $next = null;
    foreach ([7, 7, 7, 2] as $size) {
        $result = $this->get(route('reports.index', ['limit' => 7, 'cursor' => $next]))->assertOk()->inertiaPage()['props'];
        expect($result['pagination']['total'])->toBe(23);
        expect($result['reports'])->toHaveCount($size);
        $seen = [...$seen, ...array_column($result['reports'], 'id')];
        $next = $result['pagination']['next_cursor'];
    }

    expect($next)->toBeNull();
    expect($seen)->toBe([...$recent->pluck('id')->reverse()->values(), $old->id]);

    $this->getJson(route('notifications.index', ['cursor' => $cursor]))
        ->assertUnprocessable()
        ->assertJsonValidationErrors('cursor');
    foreach ([0, 101] as $limit) {
        $this->getJson(route('reports.index', ['limit' => $limit]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('limit');
    }
});

it('counts the matching Studio designs and preserves filters behind the same cursor contract', function () {
    $user = User::factory()->create();
    $brand = Brand::factory()->for($user)->create();
    $seed = fn (string $name) => Design::query()->create([
        'user_id' => $user->id,
        'brand_id' => $brand->id,
        'name' => $name,
        'document' => StudioDocument::blank(),
        'width' => 1080,
        'height' => 1080,
        'page_count' => 1,
        'revision' => 1,
    ]);
    $first = $seed('Campaña inicial');
    $last = $seed('Campaña final');
    $seed('Otro diseño');

    $filters = ['brand_id' => $brand->id, 'query' => 'Campaña', 'limit' => 1];
    $page = $this->actingAs($user)->get(route('studio.index', $filters))
        ->assertInertia(fn (Assert $page) => $page
            ->where('designs.total', 2)
            ->where('designs.has_more', true)
            ->where('designs.designs.0.id', $last->id)
            ->etc())
        ->inertiaPage()['props'];

    $this->get(route('studio.index', $filters + ['cursor' => $page['designs']['next_cursor']]))
        ->assertInertia(fn (Assert $page) => $page
            ->where('designs.total', 2)
            ->where('designs.has_more', false)
            ->where('designs.next_cursor', null)
            ->where('designs.designs.0.id', $first->id)
            ->etc());
});

it('skips the report history count when an Inertia partial reload omits pagination', function () {
    $user = User::factory()->create();
    $brand = Brand::factory()->for($user)->create();
    Report::factory()->for($user)->for($brand)->create(['status' => Report::STATUS_PENDING]);
    $counts = [];
    DB::listen(function ($query) use (&$counts) {
        if (preg_match('/\bcount\s*\(/i', $query->sql) && preg_match('/\bfrom\s+["`]?reports\b/i', $query->sql)) {
            $counts[] = $query->sql;
        }
    });

    $this->actingAs($user)->get(route('reports.index'))
        ->assertInertia(function (Assert $page) use (&$counts) {
            expect($counts)->toHaveCount(1);
            $counts = [];

            $page->where('pagination.total', 1)->reloadOnly(['reports', 'polling'], fn (Assert $partial) => $partial
                ->where('polling', true)
                ->has('reports', 1)
                ->missing('pagination')
                ->etc());

            expect($counts)->toBe([]);
        });
});

it('acepta cursores del servidor para orden por clave cualificada o un orden total sin la clave primaria', function (string $column) {
    $user = User::factory()->create();
    $brand = Brand::factory()->for($user)->create();
    $first = Post::factory()->for($user)->for($brand)->create(['created_at' => now()->subDay()]);
    $last = Post::factory()->for($user)->for($brand)->create(['created_at' => now()]);
    $query = Post::ownedBy($user->id)->orderByDesc($column);

    [$page, $next] = CursorPage::fetch($query, 1, null, 'posts:unit');
    expect($page->modelKeys())->toBe([$last->id])->and($next['next_cursor'])->toBeString();
    [$page, $end] = CursorPage::fetch($query, 1, $next['next_cursor'], 'posts:unit');
    expect($page->modelKeys())->toBe([$first->id])->and($end['next_cursor'])->toBeNull();
})->with(['posts.id', 'posts.created_at']);

it('rechaza cursores con valores inválidos', function (string $scope, array $values, bool $forward = true) {
    $user = User::factory()->create();
    $this->actingAs($user);
    $encoded = (new Cursor($values, $forward))->encode();
    $cursor = rtrim(strtr(base64_encode(json_encode(['s' => $scope.':u'.$user->id, 'c' => $encoded])), '+/', '-_'), '=');

    $this->getJson(route($scope.'.index', ['cursor' => $cursor]))
        ->assertUnprocessable()->assertJsonValidationErrors('cursor');
})->with([
    'id no numérico' => ['trash', ['id' => 'abc']],
    'id ausente' => ['trash', ['id' => null]],
    'en sentido anterior' => ['trash', ['id' => 1], false],
    'fecha no válida' => ['notifications', ['id' => 'ffffffff-ffff-4fff-8fff-ffffffffffff', 'created_at' => 'garbage']],
    'id de aviso no válido' => ['notifications', ['id' => 'no-es-uuid', 'created_at' => '2026-11-17 11:05:00']],
]);

it('vuelve al comienzo cuando un cursor válido ya no tiene filas en papelera o plantillas', function (string $route) {
    $user = User::factory()->create();
    $brand = Brand::factory()->for($user)->create();
    if ($route === 'trash.index') {
        [$old, $present] = Post::factory()->for($user)->for($brand)->count(2)->create(['deleted_at' => now()]);
        $filters = ['limit' => 1];
    } else {
        [$old, $present] = collect(['Primera', 'Segunda'])->map(fn (string $name) => PostTemplate::query()->forceCreate([
            'user_id' => $user->id, 'brand_id' => $brand->id, 'name' => $name, 'targets' => [],
        ]));
        $filters = ['brand_id' => $brand->id, 'limit' => 1];
    }

    $initial = route($route, $filters);
    $cursor = $this->actingAs($user)->get($initial)->assertOk()->inertiaPage()['props']['pagination']['next_cursor'];
    $remaining = $route === 'trash.index' ? 'posts' : 'templates';
    $route === 'trash.index' ? $old->forceDelete() : $old->delete();

    $this->get(route($route, $filters + ['cursor' => $cursor]))->assertRedirect($initial);
    $this->get($initial)->assertOk()->assertInertia(fn (Assert $page) => $page
        ->where('pagination.total', 1)->where($remaining.'.0.id', $present->id)->etc());
})->with(['trash.index', 'library.index']);

it('muestra un informe recién generado al volver desde una página posterior', function () {
    Queue::fake();
    $user = User::factory()->create();
    $brand = Brand::factory()->for($user)->create();
    Report::factory()->for($user)->for($brand)->count(2)->create(['status' => Report::STATUS_FAILED]);
    $filters = ['brand_id' => $brand->id, 'limit' => 1];

    $cursor = $this->actingAs($user)->get(route('reports.index', $filters))
        ->assertOk()->inertiaPage()['props']['pagination']['next_cursor'];
    $previousPage = route('reports.index', $filters + ['cursor' => $cursor]);

    $this->from($previousPage)->post(route('reports.store'), [
        'brand_id' => $brand->id, 'from' => now()->subDays(2)->toDateString(), 'to' => now()->subDay()->toDateString(),
        'formats' => ['csv'], 'limit' => 1,
    ])->assertRedirect(route('reports.index', ['brand_id' => $brand->id, 'limit' => 1]));
    $new = Report::ownedBy($user->id)->latest('id')->firstOrFail();
    $this->get(route('reports.index', $filters))->assertOk()->assertInertia(fn (Assert $page) => $page
        ->where('reports.0.id', $new->id)->where('reports.0.status', Report::STATUS_PENDING)->where('polling', true)->etc());
});

it('rechaza la estructura interna incompleta de un cursor', function (string $json) {
    $user = User::factory()->create();
    $inner = rtrim(strtr(base64_encode($json), '+/', '-_'), '=');
    $cursor = rtrim(strtr(base64_encode(json_encode(['s' => 'trash:u'.$user->id, 'c' => $inner])), '+/', '-_'), '=');

    $this->actingAs($user)->getJson(route('trash.index', ['cursor' => $cursor]))
        ->assertUnprocessable()->assertJsonValidationErrors('cursor');
})->with([
    'objeto sin dirección' => '{"id":1}',
    'lista' => '[1]',
    'número' => '1',
    'cadena' => '"texto"',
    'nulo' => 'null',
]);
