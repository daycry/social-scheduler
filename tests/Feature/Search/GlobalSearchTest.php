<?php

use App\Enums\PostStatus;
use App\Models\Brand;
use App\Models\BrandCollaborator;
use App\Models\Campaign;
use App\Models\MediaAsset;
use App\Models\Post;
use App\Models\Site;
use App\Models\SmartLink;
use App\Models\Studio\Design;
use App\Models\Studio\DesignShare;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * T-85 (F-11) · búsqueda global: solo lo que el usuario puede ver, con la misma visibilidad que cada listado, límites,
 * comodines del LIKE escapados y un número de consultas que no depende del número de resultados.
 */
beforeEach(function () {
    $this->user = User::factory()->create();
    $this->other = User::factory()->create();
});

function searchDesign(User $user, Brand $brand, string $name): Design
{
    return Design::unguarded(fn () => Design::create(['user_id' => $user->id, 'brand_id' => $brand->id, 'name' => $name, 'document' => ['pages' => []], 'width' => 10, 'height' => 10, 'page_count' => 1, 'revision' => 1]));
}

/** Crea `$count` elementos de cada tipo buscable cuyo nombre contiene `$term`. */
function seedSearchable(User $user, string $term, int $count): void
{
    $slug = strtolower($term);
    $existing = Brand::ownedBy($user)->count();
    for ($i = $existing + 1; $i <= $existing + $count; $i++) {
        $brand = Brand::factory()->create(['user_id' => $user->id, 'name' => "$term marca $i", 'timezone' => 'UTC']);
        Post::factory()->create(['brand_id' => $brand->id, 'text' => "$term publicación $i"]);
        MediaAsset::factory()->create(['user_id' => $user->id, 'original_name' => "$term-foto-$i.jpg"]);
        searchDesign($user, $brand, "$term diseño $i");
        Campaign::factory()->create(['brand_id' => $brand->id, 'name' => "$term campaña $i"]);
        DB::table('post_templates')->insert(['user_id' => $user->id, 'brand_id' => $brand->id, 'name' => "$term plantilla $i"]);
        Site::create(['user_id' => $user->id, 'brand_id' => $brand->id, 'slug' => "$slug-sitio-$i-u{$user->id}", 'title' => "$term sitio $i", 'draft' => []]);
        (new SmartLink)->forceFill(['user_id' => $user->id, 'brand_id' => $brand->id, 'slug' => "$slug-link-$i-u{$user->id}", 'title' => "$term enlace $i"])->save();
    }
}

/** @return list<array<string, mixed>> */
function searchResults(mixed $test, string $term): array
{
    return $test->getJson(route('search', ['q' => $term], false))->assertOk()->json('results');
}

it('finds content of every type with its context and a link', function () {
    $brand = Brand::factory()->create(['user_id' => $this->user->id, 'name' => 'Cafetería Sol', 'timezone' => 'UTC']);
    $post = Post::factory()->create(['brand_id' => $brand->id, 'text' => 'Oferta de verano en Cafetería']);
    $media = MediaAsset::factory()->create(['user_id' => $this->user->id, 'original_name' => 'cafetería-fachada.jpg']);
    $design = searchDesign($this->user, $brand, 'Cartel cafetería');
    $campaign = Campaign::factory()->create(['brand_id' => $brand->id, 'name' => 'Campaña cafetería']);
    DB::table('post_templates')->insert(['user_id' => $this->user->id, 'brand_id' => $brand->id, 'name' => 'Plantilla cafetería']);
    $site = Site::create(['user_id' => $this->user->id, 'brand_id' => $brand->id, 'slug' => 'cafe-sitio', 'title' => 'Sitio cafetería', 'draft' => []]);
    (new SmartLink)->forceFill(['user_id' => $this->user->id, 'brand_id' => $brand->id, 'slug' => 'cafe-link', 'title' => 'Enlaces cafetería'])->save();

    $results = collect(searchResults($this->actingAs($this->user), 'cafetería'))->keyBy('type');

    expect($results->keys()->sort()->values()->all())->toBe(['brand', 'campaign', 'design', 'media', 'post', 'site', 'smartlink', 'template']);
    expect($results['post'])->toMatchArray(['id' => $post->id, 'title' => 'Oferta de verano en Cafetería', 'brand' => 'Cafetería Sol', 'status' => 'Borrador', 'url' => route('posts.edit', $post, false)]);
    expect($results['brand'])->toMatchArray(['id' => $brand->id, 'title' => 'Cafetería Sol', 'url' => route('brands.show', $brand, false)]);
    expect($results['media'])->toMatchArray(['id' => $media->id, 'title' => 'cafetería-fachada.jpg', 'external' => true, 'url' => route('media.file', $media, false)]);
    expect($results['design'])->toMatchArray(['id' => $design->id, 'brand' => 'Cafetería Sol', 'url' => route('studio.designs.edit', $design, false)]);
    expect($results['campaign'])->toMatchArray(['id' => $campaign->id, 'url' => route('campaigns.show', $campaign->id, false)]);
    expect($results['site'])->toMatchArray(['id' => $site->id, 'status' => 'Borrador', 'url' => route('sites.edit', $site, false)]);
    expect($results['template']['url'])->toBe(route('library.index', ['brand_id' => $brand->id], false));
    expect($results['smartlink']['url'])->toBe(route('smartlinks.index', ['brand_id' => $brand->id], false));
});

it('never returns content of another user, not even indirectly', function () {
    $mine = Brand::factory()->create(['user_id' => $this->user->id, 'name' => 'Mi marca', 'timezone' => 'UTC']);
    Post::factory()->create(['brand_id' => $mine->id, 'text' => 'secreto-propio']);
    seedSearchable($this->other, 'secreto', 3);

    $response = $this->actingAs($this->user)->getJson(route('search', ['q' => 'secreto'], false))->assertOk();

    expect(collect($response->json('results'))->pluck('title')->all())->toBe(['secreto-propio']);
    expect(array_keys($response->json()))->toBe(['results']);
});

it('hides trashed posts and designs and unfinished media, and marks archived campaigns', function () {
    $brand = Brand::factory()->create(['user_id' => $this->user->id, 'name' => 'Marca', 'timezone' => 'UTC']);
    Post::factory()->create(['brand_id' => $brand->id, 'text' => 'zeta borrada'])->delete();
    searchDesign($this->user, $brand, 'zeta diseño borrado')->delete();
    MediaAsset::factory()->create(['user_id' => $this->user->id, 'original_name' => 'zeta-pendiente.jpg', 'status' => MediaAsset::STATUS_PENDING]);
    Campaign::factory()->create(['brand_id' => $brand->id, 'name' => 'zeta archivada', 'archived_at' => now()]);

    $results = searchResults($this->actingAs($this->user), 'zeta');

    expect(collect($results)->pluck('type')->all())->toBe(['campaign']);
    expect($results[0]['status'])->toBe('Archivada');
});

it('matches the collaborator visibility of the approvals inbox and the studio, neither more nor less', function () {
    $theirs = Brand::factory()->create(['user_id' => $this->other->id, 'name' => 'Marca ajena', 'timezone' => 'UTC']);
    $unrelated = Brand::factory()->create(['user_id' => $this->other->id, 'name' => 'Otra ajena', 'timezone' => 'UTC']);
    BrandCollaborator::create(['brand_id' => $theirs->id, 'owner_id' => $this->other->id, 'user_id' => $this->user->id, 'role' => BrandCollaborator::ROLE_APPROVER]);

    $pending = Post::factory()->create(['brand_id' => $theirs->id, 'text' => 'vis pendiente de aprobar', 'approval_status' => Post::APPROVAL_PENDING]);
    Post::factory()->create(['brand_id' => $theirs->id, 'text' => 'vis borrador del dueño']);
    Post::factory()->create(['brand_id' => $theirs->id, 'text' => 'vis ya aprobado', 'approval_status' => Post::APPROVAL_APPROVED]);
    Post::factory()->create(['brand_id' => $unrelated->id, 'text' => 'vis pendiente de otra marca', 'approval_status' => Post::APPROVAL_PENDING]);
    $design = searchDesign($this->other, $theirs, 'vis diseño de la marca aprobada');
    searchDesign($this->other, $unrelated, 'vis diseño de otra marca');
    $shared = searchDesign($this->other, $unrelated, 'vis diseño compartido');
    DesignShare::create(['design_id' => $shared->id, 'user_id' => $this->user->id, 'owner_id' => $this->other->id, 'role' => 'reader']);
    Campaign::factory()->create(['brand_id' => $theirs->id, 'name' => 'vis campaña ajena']);
    MediaAsset::factory()->create(['user_id' => $this->other->id, 'original_name' => 'vis-ajena.jpg']);
    Brand::factory()->create(['user_id' => $this->other->id, 'name' => 'vis marca ajena', 'timezone' => 'UTC']);

    $results = collect(searchResults($this->actingAs($this->user), 'vis'));

    expect($results->pluck('title')->sort()->values()->all())->toBe(['vis diseño compartido', 'vis diseño de la marca aprobada', 'vis pendiente de aprobar']);
    expect($results->firstWhere('type', 'post'))->toMatchArray(['id' => $pending->id, 'status' => 'Pendiente de aprobar', 'url' => route('approvals.index', absolute: false)]);
    expect($results->where('type', 'design')->firstWhere('id', $design->id)['status'])->toBe('Compartido');
});

it('stops showing collaborator content when the grant is revoked', function () {
    $theirs = Brand::factory()->create(['user_id' => $this->other->id, 'name' => 'Marca', 'timezone' => 'UTC']);
    $grant = BrandCollaborator::create(['brand_id' => $theirs->id, 'owner_id' => $this->other->id, 'user_id' => $this->user->id, 'role' => BrandCollaborator::ROLE_APPROVER]);
    searchDesign($this->other, $theirs, 'revocable');

    expect(searchResults($this->actingAs($this->user), 'revocable'))->toHaveCount(1);
    $grant->delete();
    expect(searchResults($this, 'revocable'))->toBe([]);
});

it('treats % and _ as literal characters', function () {
    $brand = Brand::factory()->create(['user_id' => $this->user->id, 'timezone' => 'UTC']);
    Post::factory()->create(['brand_id' => $brand->id, 'text' => 'rebaja 100% real']);
    Post::factory()->create(['brand_id' => $brand->id, 'text' => 'rebaja 100 xx real']);
    Post::factory()->create(['brand_id' => $brand->id, 'text' => 'fichero a_b final']);
    Post::factory()->create(['brand_id' => $brand->id, 'text' => 'fichero aXb final']);
    Post::factory()->create(['brand_id' => $brand->id, 'text' => 'barra ! invertida']);

    $this->actingAs($this->user);

    expect(collect(searchResults($this, '100%'))->pluck('title')->all())->toBe(['rebaja 100% real']);
    expect(collect(searchResults($this, 'a_b'))->pluck('title')->all())->toBe(['fichero a_b final']);
    expect(searchResults($this, '%%'))->toBe([]);
    expect(searchResults($this, '!!'))->toBe([]);
    expect(collect(searchResults($this, '! i'))->pluck('title')->all())->toBe(['barra ! invertida']);
});

it('ignores letter case', function () {
    $brand = Brand::factory()->create(['user_id' => $this->user->id, 'name' => 'Marca Mayusculas', 'timezone' => 'UTC']);

    expect(collect(searchResults($this->actingAs($this->user), 'MARCA mayus'))->pluck('id')->all())->toBe([$brand->id]);
});

it('caps results per type and in total, sharing the total between types', function () {
    seedSearchable($this->user, 'tope', 7);

    $results = collect(searchResults($this->actingAs($this->user), 'tope'));

    expect($results)->toHaveCount(20);
    expect($results->countBy('type')->max())->toBeLessThanOrEqual(5);
    expect($results->pluck('type')->unique())->toHaveCount(8);
});

it('validates the minimum and maximum query length', function () {
    $this->actingAs($this->user);

    $this->getJson(route('search', [], false))->assertJsonValidationErrors('q');
    $this->getJson(route('search', ['q' => 'a'], false))->assertJsonValidationErrors('q');
    $this->getJson(route('search', ['q' => ' a '], false))->assertJsonValidationErrors('q');
    $this->getJson(route('search', ['q' => str_repeat('a', 101)], false))->assertJsonValidationErrors('q');
    $this->getJson(route('search', ['q' => ['a', 'b']], false))->assertJsonValidationErrors('q');
    $this->getJson(route('search', ['q' => 'ab'], false))->assertOk();
    $this->getJson(route('search', ['q' => str_repeat('a', 100)], false))->assertOk();
});

it('requires a session', function () {
    $this->getJson(route('search', ['q' => 'marca'], false))->assertUnauthorized();
    $this->get(route('search', ['q' => 'marca'], false))->assertRedirect(route('login'));
});

it('has its own named throttle', function () {
    config(['rate_limits.limits.search' => 2]);
    $this->actingAs($this->user);

    $this->getJson(route('search', ['q' => 'marca'], false))->assertOk();
    $this->getJson(route('search', ['q' => 'marca'], false))->assertOk();
    $this->getJson(route('search', ['q' => 'marca'], false))->assertStatus(429);
});

it('runs a fixed number of queries whatever the number of results', function () {
    $count = function (): int {
        $queries = 0;
        DB::listen(function () use (&$queries) {
            $queries++;
        });
        $this->getJson(route('search', ['q' => 'fijo'], false))->assertOk();

        return $queries;
    };
    $this->actingAs($this->user);
    $this->getJson(route('search', ['q' => 'calentar'], false))->assertOk();

    seedSearchable($this->user, 'fijo', 1);
    $few = $count();
    seedSearchable($this->user, 'fijo', 6);
    $many = $count();

    expect($many)->toBe($few)->and($few)->toBeLessThanOrEqual(10);
});

it('formats post dates in the timezone of the brand', function () {
    $brand = Brand::factory()->create(['user_id' => $this->user->id, 'timezone' => 'America/Mexico_City']);
    Post::factory()->create(['brand_id' => $brand->id, 'text' => 'zona horaria', 'status' => PostStatus::Scheduled, 'scheduled_at' => '2026-11-20 02:30:00']);

    $result = searchResults($this->actingAs($this->user), 'zona horaria')[0];

    expect($result)->toMatchArray(['date' => '2026-11-19 20:30', 'status' => 'Programada']);
});
