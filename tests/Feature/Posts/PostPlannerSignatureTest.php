<?php

use App\Connectors\Network;
use App\Enums\PostStatus;
use App\Exceptions\PostValidationException;
use App\Models\Brand;
use App\Models\Post;
use App\Models\SocialAccount;
use App\Models\User;
use App\Services\Posts\PostPlanner;
use App\Services\Posts\PostSignature;
use App\Services\PostService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Queue;

// T-40: PostPlanner (plan/fill) y PostSignature se prueban directamente, sin pasar por la fachada PostService.

beforeEach(function () {
    Carbon::setTestNow('2026-11-01 12:00:00');
    Queue::fake();
    $this->user = User::factory()->create();
    $this->brand = Brand::factory()->for($this->user)->create(['timezone' => 'Europe/Madrid']);
    $this->fb = SocialAccount::factory()->for($this->user)->create(['brand_id' => $this->brand->id, 'network' => Network::Facebook]);
    $this->planner = app(PostPlanner::class);
    $this->signature = app(PostSignature::class);
});

function plannerData(array $extra = []): array
{
    return $extra + ['text' => 'Hola', 'targets' => [['account_id' => test()->fb->id, 'content_type' => 'facebook_text']]];
}

test('PostPlanner::plan normaliza un borrador sin tocar la base de datos', function () {
    $plan = $this->planner->plan($this->user, $this->brand, plannerData());

    expect($plan['status'])->toBe(PostStatus::Draft)
        ->and($plan['scheduled_at'])->toBeNull()
        ->and($plan['campaign_id'])->toBeFalse()
        ->and($plan['targets'])->toHaveCount(1)
        ->and(Post::withoutUserScope()->count())->toBe(0);
});

test('PostPlanner::plan interpreta la fecha sin zona en la zona de la marca y la guarda en UTC', function () {
    $plan = $this->planner->plan($this->user, $this->brand, plannerData(['schedule' => true, 'scheduled_at' => '2026-11-05 10:00']));

    expect($plan['status'])->toBe(PostStatus::Scheduled)
        ->and($plan['scheduled_at']->toIso8601String())->toBe('2026-11-05T09:00:00+00:00');
});

test('PostPlanner::plan rechaza una fecha pasada, una cuenta ajena y agrupa los errores por campo', function () {
    $other = SocialAccount::factory()->create(['network' => Network::Facebook]);

    try {
        $this->planner->plan($this->user, $this->brand, ['schedule' => true, 'scheduled_at' => '2026-10-01 10:00', 'targets' => [['account_id' => $other->id, 'content_type' => 'facebook_text']]]);
        $this->fail('Debia lanzar PostValidationException');
    } catch (PostValidationException $e) {
        expect($e->errors())->toHaveKeys(['scheduled_at', 'targets.0']);
    }
});

test('PostPlanner::fill crea los destinos del plan y los conserva al repetirse', function () {
    $post = new Post(['text' => 'Hola']);
    $post->user_id = $this->user->id;
    $post->brand_id = $this->brand->id;
    $post->save();

    $this->planner->fill($post, $this->planner->plan($this->user, $this->brand, plannerData()));
    $first = $post->targets()->firstOrFail();

    $existing = $post->targets()->get()->keyBy(fn ($t) => $t->social_account_id.'|'.$t->content_type->value);
    $this->planner->fill($post, $this->planner->plan($this->user, $this->brand, plannerData()), $existing, ['text' => 'Hola', 'scheduled_at' => null, 'media' => []]);

    expect($post->targets()->count())->toBe(1)
        ->and($post->targets()->firstOrFail()->id)->toBe($first->id);
});

test('PostPlanner::normalizeOptions convierte a booleano real las opciones booleanas', function () {
    $options = PostPlanner::normalizeOptions(['made_for_kids' => '1', 'disable_duet' => 0, 'tags' => ['a']]);

    expect($options['made_for_kids'])->toBeTrue()
        ->and($options['disable_duet'])->toBeFalse()
        ->and($options['tags'])->toBe(['a']);
});

test('PostSignature es estable y cambia con el texto o los destinos, nunca con la fecha', function () {
    $post = app(PostService::class)->create($this->user, $this->brand, plannerData(['schedule' => true, 'scheduled_at' => '2026-11-05 10:00']));
    $before = $this->signature->ofPost($post);

    expect($this->signature->ofPost($post))->toBe($before)
        ->and($this->signature->ofPost($post->fresh()))->toBe($before);

    $post->forceFill(['scheduled_at' => Carbon::parse('2026-11-09 10:00')])->save();
    expect($this->signature->ofPost($post->fresh()))->toBe($before);

    $post->forceFill(['text' => 'Otro'])->save();
    expect($this->signature->ofPost($post->fresh()))->not->toBe($before);
});

test('PostSignature::ofPlan coincide con ofPost del post que deja ese plan', function () {
    $data = plannerData(['schedule' => true, 'scheduled_at' => '2026-11-05 10:00']);
    $post = app(PostService::class)->create($this->user, $this->brand, $data);

    expect($this->signature->ofPlan($this->planner->plan($this->user, $this->brand, $data)))->toBe($this->signature->ofPost($post));
});
