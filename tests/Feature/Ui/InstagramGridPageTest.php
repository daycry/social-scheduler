<?php

use App\Connectors\Network;
use App\Enums\ContentType;
use App\Enums\PostStatus;
use App\Models\Brand;
use App\Models\Post;
use App\Models\PostTarget;
use App\Models\SocialAccount;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\FakeGraph;

// Pagina «Cuadricula» de Instagram (C-02, CA-08..CA-10). Graph simulado.

beforeEach(function () {
    Carbon::setTestNow('2026-11-01 12:00:00');
    Cache::flush();
    config(['social.meta.graph_version' => 'v23.0']);
    $this->user = User::factory()->create();
    $this->brand = Brand::factory()->for($this->user)->create(['name' => 'Alfa']);
    $this->ig = SocialAccount::factory()->for($this->user)->create(['brand_id' => $this->brand->id, 'network' => Network::Instagram, 'external_id' => 'IGU1', 'name' => 'Alfa IG']);
});

it('renderiza Instagram/Grid con programadas y publicadas, y las cuentas de la marca', function () {
    (new FakeGraph)->on('GET', '#^IGU1/media$#', ['data' => [['id' => 'P1', 'media_type' => 'IMAGE', 'media_url' => 'https://cdn/P1.jpg', 'permalink' => 'https://instagram.com/p/P1/', 'timestamp' => '2026-10-20T10:00:00+0000']]]);
    $post = Post::factory()->create(['brand_id' => $this->brand->id, 'user_id' => $this->user->id, 'status' => PostStatus::Scheduled, 'scheduled_at' => '2026-11-05 10:00:00']);
    PostTarget::factory()->create(['post_id' => $post->id, 'user_id' => $this->user->id, 'social_account_id' => $this->ig->id, 'network' => Network::Instagram, 'content_type' => ContentType::InstagramImage, 'status' => PostStatus::Scheduled]);

    $this->actingAs($this->user)->get(route('instagram.grid'))->assertOk()
        ->assertInertia(fn (Assert $p) => $p->component('Instagram/Grid')
            ->where('brand.name', 'Alfa')->where('account.id', $this->ig->id)->has('accounts', 1)
            ->has('items', 2)->where('items.0.kind', 'scheduled')->where('items.1.kind', 'published')->where('items.1.permalink', 'https://instagram.com/p/P1/')
            ->where('error', null)->where('timezone', $this->brand->timezone));
});

it('sin cuentas de Instagram renderiza el estado vacio', function () {
    $this->ig->delete();

    $this->actingAs($this->user)->get(route('instagram.grid'))->assertOk()
        ->assertInertia(fn (Assert $p) => $p->component('Instagram/Grid')->where('account', null)->has('accounts', 0)->has('items', 0));
});

it('un error de Graph llega a la pagina sin romperla', function () {
    (new FakeGraph)->on('GET', '#^IGU1/media$#', FakeGraph::error(190, 'caducado', null, 401));

    $this->actingAs($this->user)->get(route('instagram.grid'))->assertOk()
        ->assertInertia(fn (Assert $p) => $p->component('Instagram/Grid')->has('items', 0)->where('error', fn ($e) => str_contains($e, 'Reconecta')));
});

it('no da acceso a la cuenta de otro usuario (404) ni a una marca ajena', function () {
    $other = User::factory()->create();
    $ob = Brand::factory()->for($other)->create();
    $oa = SocialAccount::factory()->for($other)->create(['brand_id' => $ob->id, 'network' => Network::Instagram, 'external_id' => 'IGX']);
    $http = (new FakeGraph)->on('GET', '#.#', ['data' => []]);

    $this->actingAs($this->user)->get(route('instagram.grid', ['account_id' => $oa->id]))->assertNotFound();
    $this->actingAs($this->user)->get(route('instagram.grid', ['brand_id' => $ob->id]))->assertOk()
        ->assertInertia(fn (Assert $p) => $p->where('brand.id', $this->brand->id)->where('account.id', $this->ig->id));
    expect($http->callsTo('GET', '#^IGX#'))->toBeEmpty();
});

it('una cuenta propia de otra marca cae a la primera de la marca actual', function () {
    (new FakeGraph)->on('GET', '#.#', ['data' => []]);
    $b2 = Brand::factory()->for($this->user)->create(['name' => 'Zeta']);
    $ig2 = SocialAccount::factory()->for($this->user)->create(['brand_id' => $b2->id, 'network' => Network::Instagram, 'external_id' => 'IGU2']);

    $this->actingAs($this->user)->get(route('instagram.grid', ['brand_id' => $this->brand->id, 'account_id' => $ig2->id]))->assertOk()
        ->assertInertia(fn (Assert $p) => $p->where('account.id', $this->ig->id));
});

it('exige sesion', function () {
    $this->get(route('instagram.grid'))->assertRedirect(route('login'));
});
