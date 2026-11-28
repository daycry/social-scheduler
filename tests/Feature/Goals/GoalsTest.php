<?php

use App\Connectors\Network;
use App\Models\Brand;
use App\Models\Goal;
use App\Models\SocialAccount;
use App\Models\User;
use App\Services\GoalService;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Illuminate\Validation\ValidationException;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    Http::preventStrayRequests();
    Notification::fake();
    Queue::fake();
});

test('Goals: an owner creates updates and deletes an isolated account goal without triggering providers or alerts', function () {
    $owner = User::factory()->create();
    $foreign = User::factory()->create();
    $brand = Brand::factory()->for($owner)->create();
    $account = SocialAccount::factory()->for($owner)->for($brand)->create(['network' => Network::Instagram]);
    $data = ['name' => 'Llegar a mil seguidores', 'kind' => 'milestone', 'metric' => 'followers',
        'threshold' => 1000, 'social_account_id' => $account->id, 'mail_enabled' => false, 'user_id' => $foreign->id];
    $this->actingAs($owner)->post('/brands/'.$brand->id.'/goals', $data)->assertRedirect()->assertSessionHasNoErrors();
    $goal = Goal::ownedBy($owner->id)->sole();
    expect($goal->user_id)->toBe($owner->id)->and($goal->brand_id)->toBe($brand->id)
        ->and($goal->social_account_id)->toBe($account->id)->and($goal->threshold)->toBe(1000.0)
        ->and($goal->enabled)->toBeTrue()->and($goal->mail_enabled)->toBeFalse();
    $this->put('/goals/'.$goal->id, array_replace($data, ['name' => 'Nuevo hito', 'threshold' => 1500, 'enabled' => false]))
        ->assertRedirect()->assertSessionHasNoErrors();
    expect($goal->fresh()->name)->toBe('Nuevo hito')->and($goal->fresh()->threshold)->toBe(1500.0)
        ->and($goal->fresh()->enabled)->toBeFalse()->and($goal->fresh()->user_id)->toBe($owner->id);
    $this->delete('/goals/'.$goal->id)->assertRedirect();
    expect(Goal::withoutUserScope()->count())->toBe(0)->and($account->fresh())->not->toBeNull();
    Http::assertNothingSent();
    Notification::assertNothingSent();
    Queue::assertNothingPushed();
});

test('Goals: a different owner cannot create read edit or delete goals in another owners brand', function () {
    $owner = User::factory()->create();
    $stranger = User::factory()->create();
    $brand = Brand::factory()->for($owner)->create();
    $data = ['name' => 'Solo mío', 'kind' => 'drop', 'metric' => 'followers', 'threshold' => 25];
    $this->actingAs($owner)->post('/brands/'.$brand->id.'/goals', $data)->assertRedirect()->assertSessionHasNoErrors();
    $goal = Goal::ownedBy($owner->id)->sole();
    $this->actingAs($stranger)->post('/brands/'.$brand->id.'/goals', $data)->assertNotFound();
    $this->get('/goals?brand_id='.$brand->id)->assertNotFound();
    $this->put('/goals/'.$goal->id, $data)->assertNotFound();
    $this->delete('/goals/'.$goal->id)->assertNotFound();
    expect($goal->fresh()->name)->toBe('Solo mío')->and(Goal::withoutUserScope()->count())->toBe(1);
});

test('Goals: an account from another brand is invalid even when it belongs to the same owner', function () {
    $owner = User::factory()->create();
    $brand = Brand::factory()->for($owner)->create();
    $other = Brand::factory()->for($owner)->create();
    $account = SocialAccount::factory()->for($owner)->for($other)->create();
    $this->actingAs($owner)->postJson('/brands/'.$brand->id.'/goals', [
        'name' => 'Cuenta incorrecta', 'kind' => 'milestone', 'metric' => 'followers',
        'threshold' => 1000, 'social_account_id' => $account->id,
    ])->assertUnprocessable()->assertJsonValidationErrors('social_account_id');
    expect(Goal::withoutUserScope()->count())->toBe(0);
});

test('Goals: an inconsistent imported account reference never exposes another owners account name', function () {
    $owner = User::factory()->create();
    $brand = Brand::factory()->for($owner)->create();
    $this->actingAs($owner)->post('/brands/'.$brand->id.'/goals', [
        'name' => 'Registro antiguo', 'kind' => 'drop', 'metric' => 'followers', 'threshold' => 20,
    ])->assertRedirect()->assertSessionHasNoErrors();
    $goal = Goal::ownedBy($owner->id)->sole();
    $foreign = SocialAccount::factory()->create(['name' => 'FOREIGN-PRIVATE-ACCOUNT']);
    $goal->forceFill(['social_account_id' => $foreign->id])->save();
    $response = $this->get('/goals?brand_id='.$brand->id);
    $response->assertOk()->assertInertia(fn (Assert $page) => $page->component('Goals/Index')
        ->where('goals.0.id', $goal->id)->where('goals.0.account_name', null));
    expect($response->getContent())->not->toContain('FOREIGN-PRIVATE-ACCOUNT');
});

test('Goals: cursor pages preserve their navigation state and cannot cross brands', function () {
    $owner = User::factory()->create();
    $brand = Brand::factory()->for($owner)->create();
    $other = Brand::factory()->for($owner)->create();
    foreach (['Primero', 'Segundo'] as $name) {
        app(GoalService::class)->create($owner, $brand, [
            'name' => $name, 'kind' => 'milestone', 'metric' => 'followers', 'threshold' => 1000,
        ]);
    }
    $first = $this->actingAs($owner)->get('/goals?brand_id='.$brand->id.'&limit=1')->assertOk();
    $cursor = $first->inertiaProps('pagination.next_cursor');
    expect($cursor)->toBeString();
    $this->get('/goals?'.http_build_query(['brand_id' => $brand->id, 'limit' => 1, 'cursor' => $cursor]))
        ->assertOk()->assertInertia(fn (Assert $page) => $page->component('Goals/Index')
        ->has('goals', 1)->where('goals.0.name', 'Primero')->where('pagination.cursor', $cursor)
        ->where('pagination.limit', '1')->where('pagination.has_more', false));
    $this->getJson('/goals?'.http_build_query(['brand_id' => $other->id, 'cursor' => $cursor]))->assertUnprocessable();
});

test('Goals: changing an evaluated rule clears its obsolete observation while renaming preserves it', function () {
    $owner = User::factory()->create();
    $brand = Brand::factory()->for($owner)->create();
    $data = ['name' => 'Seguidores', 'kind' => 'milestone', 'metric' => 'followers', 'threshold' => 1000];
    $service = app(GoalService::class);
    $goal = $service->create($owner, $brand, $data);
    $goal->forceFill(['last_value' => 1200, 'last_period' => '2026-11-17', 'last_evaluated_at' => now()])->save();
    $renamed = $service->update($owner, $goal, array_replace($data, ['name' => 'Nombre nuevo']));
    expect($renamed->last_value)->toBe(1200.0)->and($renamed->last_evaluated_at)->not->toBeNull();
    $changed = $service->update($owner, $renamed, array_replace($data, ['metric' => 'engagement', 'threshold' => 5]));
    expect($changed->last_value)->toBeNull()->and($changed->last_period)->toBeNull()
        ->and($changed->last_evaluated_at)->toBeNull();
});

test('Goals: names cannot inject mail header lines or become empty after normalization', function (string $name) {
    $owner = User::factory()->create();
    $brand = Brand::factory()->for($owner)->create();
    expect(fn () => app(GoalService::class)->create($owner, $brand, [
        'name' => $name, 'kind' => 'milestone', 'metric' => 'followers', 'threshold' => 1000,
    ]))->toThrow(ValidationException::class);
    expect(Goal::withoutUserScope()->count())->toBe(0);
})->with(['header newline' => ["Hito\r\nBcc: nobody@example.test"], 'whitespace' => ['   ']]);

test('Goals: disconnected accounts cannot be selected for a new rule or a different existing rule', function (bool $update) {
    $owner = User::factory()->create();
    $brand = Brand::factory()->for($owner)->create();
    $account = SocialAccount::factory()->for($owner)->for($brand)->create(['status' => SocialAccount::STATUS_DISCONNECTED]);
    $data = ['name' => 'No elegir desconectada', 'kind' => 'milestone', 'metric' => 'followers', 'threshold' => 1000, 'social_account_id' => $account->id];
    if ($update) {
        $goal = app(GoalService::class)->create($owner, $brand, array_replace($data, ['social_account_id' => null]));
        $this->actingAs($owner)->putJson('/goals/'.$goal->id, $data)->assertUnprocessable()->assertJsonValidationErrors('social_account_id');
        expect($goal->fresh()->social_account_id)->toBeNull();
    } else {
        $this->actingAs($owner)->postJson('/brands/'.$brand->id.'/goals', $data)->assertUnprocessable()->assertJsonValidationErrors('social_account_id');
        expect(Goal::withoutUserScope()->count())->toBe(0);
    }
})->with([false, true]);

test('Goals: a rule keeps its own now-disconnected account when pausing or renaming without selecting a new disconnected account', function () {
    $owner = User::factory()->create();
    $brand = Brand::factory()->for($owner)->create();
    $account = SocialAccount::factory()->for($owner)->for($brand)->create();
    $data = ['name' => 'Regla existente', 'kind' => 'milestone', 'metric' => 'followers', 'threshold' => 1000, 'social_account_id' => $account->id];
    $goal = app(GoalService::class)->create($owner, $brand, $data);
    $account->forceFill(['status' => SocialAccount::STATUS_DISCONNECTED])->save();
    $this->actingAs($owner)->put('/goals/'.$goal->id, array_replace($data, ['enabled' => false, 'name' => 'Pausado sin perder referencia']))->assertRedirect()->assertSessionHasNoErrors();
    expect($goal->fresh()->enabled)->toBeFalse()->and($goal->fresh()->social_account_id)->toBe($account->id);
});
