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
use Illuminate\Testing\TestResponse;

// E-09: GET /api/v1/approvals (api.approvals.pending): solo ve lo suyo y lo de las marcas donde es aprobador, y exige posts:read.

beforeEach(function () {
    Http::preventStrayRequests();
    Queue::fake();
    Carbon::setTestNow('2026-11-09 12:00:00');
    $this->owner = User::factory()->create();
    $this->approver = User::factory()->create();
    $this->stranger = User::factory()->create();
    $this->brand = Brand::factory()->for($this->owner)->create(['timezone' => 'UTC']);
    $this->fb = SocialAccount::factory()->for($this->owner)->create(['brand_id' => $this->brand->id, 'network' => Network::Facebook]);
    BrandCollaborator::query()->create(['brand_id' => $this->brand->id, 'owner_id' => $this->owner->id, 'user_id' => $this->approver->id, 'role' => 'aprobador']);
});

function pendingPost(User $user, Brand $brand, SocialAccount $account, string $text = 'Hola'): Post
{
    return app(PostService::class)->create($user, $brand, [
        'text' => $text, 'schedule' => false, 'scheduled_at' => '2026-11-10T10:00', 'media' => [], 'requires_approval' => true,
        'targets' => [['account_id' => $account->id, 'content_type' => 'facebook_text']],
    ]);
}

function pendingApi(User $user, array $abilities): TestResponse
{
    $key = $user->createToken('k', $abilities)->plainTextToken;
    app('auth')->forgetGuards();

    return test()->withToken($key)->getJson(route('api.approvals.pending'));
}

test('api.approvals.pending: el propietario y el aprobador invitado ven los pendientes de la marca', function () {
    $post = pendingPost($this->owner, $this->brand, $this->fb);

    pendingApi($this->owner, ['posts:read'])->assertOk()->assertJsonCount(1, 'pending')->assertJsonPath('pending.0.id', $post->id);
    pendingApi($this->approver, ['posts:read'])->assertOk()->assertJsonCount(1, 'pending')->assertJsonPath('pending.0.id', $post->id);
});

test('api.approvals.pending: un usuario ajeno no ve los pendientes de otro y recibe la lista vacia', function () {
    pendingPost($this->owner, $this->brand, $this->fb);

    pendingApi($this->stranger, ['posts:read'])->assertOk()->assertExactJson(['pending' => []]);
});

test('api.approvals.pending: el aprobador solo ve las marcas donde fue invitado', function () {
    $mine = pendingPost($this->owner, $this->brand, $this->fb);
    $otherBrand = Brand::factory()->for($this->owner)->create(['timezone' => 'UTC']);
    $otherFb = SocialAccount::factory()->for($this->owner)->create(['brand_id' => $otherBrand->id, 'network' => Network::Facebook]);
    pendingPost($this->owner, $otherBrand, $otherFb, 'Otra marca');

    $ids = collect(pendingApi($this->approver, ['posts:read'])->assertOk()->json('pending'))->pluck('id')->all();

    expect($ids)->toBe([$mine->id]);
});

test('api.approvals.pending: solo lista los pendientes, no los aprobados', function () {
    $post = pendingPost($this->owner, $this->brand, $this->fb);
    Post::withoutUserScope()->whereKey($post->id)->update(['approval_status' => 'aprobado']);

    pendingApi($this->owner, ['posts:read'])->assertOk()->assertJsonCount(0, 'pending');
});

test('api.approvals.pending: una key sin posts:read se rechaza y sin key no hay acceso', function () {
    pendingPost($this->owner, $this->brand, $this->fb);

    pendingApi($this->owner, ['posts:write', 'posts:approve'])->assertForbidden();
    app('auth')->forgetGuards();
    $this->withoutToken()->getJson(route('api.approvals.pending'))->assertUnauthorized();
});
