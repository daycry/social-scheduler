<?php

use App\Analytics\Metric;
use App\Connectors\Network;
use App\Enums\ContentType;
use App\Enums\FirstCommentStatus;
use App\Enums\PostStatus;
use App\Exceptions\OwnershipException;
use App\Models\Brand;
use App\Models\BrandCollaborator;
use App\Models\MediaAsset;
use App\Models\Post;
use App\Models\PostMetricSnapshot;
use App\Models\PostTarget;
use App\Models\SocialAccount;
use App\Models\User;
use App\Services\Posts\PostDuplicator;
use App\Services\PostService;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

beforeEach(function () {
    Http::preventStrayRequests();
    Queue::fake();
});

/** Publicación real de fábrica con contenido y trazas que un duplicado no debe heredar. */
function duplicateSource(User $owner, Brand $brand): array
{
    $account = SocialAccount::factory()->for($owner)->for($brand)->create(['network' => Network::Facebook]);
    $image = MediaAsset::factory()->for($owner)->create();
    $post = Post::factory()->for($owner)->for($brand)->create([
        'text' => 'Texto original', 'status' => PostStatus::Published, 'scheduled_at' => now()->subDay(),
    ]);
    $post->forceFill(['approval_status' => Post::APPROVAL_APPROVED])->save();
    $post->media()->attach($image->id, ['position' => 0]);
    $target = PostTarget::factory()->for($owner)->for($post)->create([
        'social_account_id' => $account->id, 'network' => Network::Facebook,
        'content_type' => ContentType::FacebookPhoto, 'text' => 'Texto propio de Facebook',
        'status' => PostStatus::Published, 'published_at' => now()->subDay(),
        'external_id' => 'published-original', 'external_url' => 'https://example.test/original',
        'attempts' => 3, 'error_message' => 'Error histórico que no se copia',
    ]);
    PostMetricSnapshot::withoutUserScope()->create([
        'user_id' => $owner->id, 'post_target_id' => $target->id,
        'social_account_id' => $account->id, 'network' => 'facebook',
        'date' => now()->subDay()->toDateString(),
        'metrics' => array_replace(Metric::blankPost(), [Metric::INTERACTIONS => 42]),
    ]);

    return [$post, $target, $image, $account];
}

test('DuplicatePost: published content becomes an independent unscheduled draft without publication history', function () {
    $owner = User::factory()->create();
    $brand = Brand::factory()->for($owner)->create();
    [$source, $originalTarget, $image, $account] = duplicateSource($owner, $brand);

    $response = $this->actingAs($owner)->post('/posts/'.$source->id.'/duplicate');
    $response->assertRedirect();
    $copy = Post::ownedBy($owner->id)->whereKeyNot($source->id)->sole();
    $response->assertRedirect(route('posts.edit', $copy));
    expect($copy->brand_id)->toBe($brand->id)
        ->and($copy->text)->toBe('Texto original')
        ->and($copy->status)->toBe(PostStatus::Draft)
        ->and($copy->scheduled_at)->toBeNull()
        ->and($copy->approval_status)->toBeNull()
        ->and($copy->media()->pluck('media_assets.id')->all())->toBe([$image->id]);
    $target = $copy->targets()->sole();
    expect($target->id)->not->toBe($originalTarget->id)
        ->and($target->social_account_id)->toBe($account->id)
        ->and($target->text)->toBe('Texto propio de Facebook')
        ->and($target->status)->toBe(PostStatus::Draft)
        ->and($target->external_id)->toBeNull()
        ->and($target->external_url)->toBeNull()
        ->and($target->published_at)->toBeNull()
        ->and($target->error_message)->toBeNull()
        ->and($target->attempts)->toBe(0);
    expect(PostMetricSnapshot::withoutUserScope()->where('post_target_id', $target->id)->exists())->toBeFalse();
    expect(PostMetricSnapshot::withoutUserScope()->where('post_target_id', $originalTarget->id)->sole()->metrics[Metric::INTERACTIONS])->toBe(42);
    $copy->update(['text' => 'Cambio independiente']);
    $copy->media()->detach();
    expect($source->fresh()->text)->toBe('Texto original')
        ->and($source->fresh()->status)->toBe(PostStatus::Published)
        ->and($source->media()->pluck('media_assets.id')->all())->toBe([$image->id]);
    Queue::assertNothingPushed();
});

test('DuplicatePost: another owned brand uses only compatible destination accounts', function () {
    $owner = User::factory()->create();
    $origin = Brand::factory()->for($owner)->create();
    $destination = Brand::factory()->for($owner)->create();
    [$source, $originalTarget, $image] = duplicateSource($owner, $origin);
    $facebook = SocialAccount::factory()->for($owner)->for($destination)->create(['network' => Network::Facebook]);
    SocialAccount::factory()->for($owner)->for($destination)->create(['network' => Network::Instagram]);

    $this->actingAs($owner)->post('/posts/'.$source->id.'/duplicate', ['brand_id' => $destination->id])->assertRedirect();
    $copy = Post::ownedBy($owner->id)->where('brand_id', $destination->id)->sole();
    $target = $copy->targets()->sole();
    expect($copy->status)->toBe(PostStatus::Draft)
        ->and($copy->scheduled_at)->toBeNull()
        ->and($copy->text)->toBe($source->text)
        ->and($copy->media()->pluck('media_assets.id')->all())->toBe([$image->id])
        ->and($target->social_account_id)->toBe($facebook->id)
        ->and($target->content_type)->toBe(ContentType::FacebookPhoto)
        ->and($target->text)->toBe($originalTarget->text)
        ->and($target->external_id)->toBeNull();
    Queue::assertNothingPushed();
});

test('DuplicatePost: neither a stranger nor an invited approver gains permission to copy another owners content', function () {
    $owner = User::factory()->create();
    $guest = User::factory()->create();
    $brand = Brand::factory()->for($owner)->create();
    [$source] = duplicateSource($owner, $brand);
    $guestBrand = Brand::factory()->for($guest)->create();
    $this->actingAs($guest)->postJson('/posts/'.$source->id.'/duplicate', ['brand_id' => $guestBrand->id])->assertNotFound();
    BrandCollaborator::query()->create([
        'brand_id' => $brand->id, 'owner_id' => $owner->id,
        'user_id' => $guest->id, 'role' => BrandCollaborator::ROLE_APPROVER,
    ]);
    $this->actingAs($guest)->postJson('/posts/'.$source->id.'/duplicate', ['brand_id' => $guestBrand->id])->assertNotFound();
    $this->actingAs($owner)->postJson('/posts/'.$source->id.'/duplicate', ['brand_id' => $guestBrand->id])->assertNotFound();
    expect(Post::withoutUserScope()->count())->toBe(1);
    Queue::assertNothingPushed();
});

test('DuplicatePost: incompatible account capabilities omit only that target with an explicit notice', function () {
    config(['social.linkedin.enabled' => true, 'services.linkedin.client_id' => 'duplicate-test-client']);
    $owner = User::factory()->create();
    $origin = Brand::factory()->for($owner)->create();
    $destination = Brand::factory()->for($owner)->create();
    [$source] = duplicateSource($owner, $origin);
    $sourceLinkedIn = SocialAccount::factory()->for($owner)->for($origin)->create(['network' => Network::LinkedIn, 'meta' => ['kind' => 'profile']]);
    PostTarget::factory()->for($owner)->for($source)->create([
        'social_account_id' => $sourceLinkedIn->id, 'network' => Network::LinkedIn,
        'content_type' => ContentType::LinkedInText, 'media' => [], 'options' => ['linkedin_visibility' => 'CONNECTIONS'],
    ]);
    $facebook = SocialAccount::factory()->for($owner)->for($destination)->create(['network' => Network::Facebook]);
    SocialAccount::factory()->for($owner)->for($destination)->create(['network' => Network::LinkedIn, 'meta' => ['kind' => 'page']]);

    $response = $this->actingAs($owner)->post('/posts/'.$source->id.'/duplicate', ['brand_id' => $destination->id]);
    $response->assertSessionHasNoErrors();
    $copy = Post::ownedBy($owner->id)->where('brand_id', $destination->id)->sole();
    $response->assertRedirect(route('posts.edit', $copy))->assertSessionHas('success', fn ($text) => str_contains($text, '1 destino'));
    expect($copy->targets()->sole()->social_account_id)->toBe($facebook->id)
        ->and($copy->text)->toBe($source->text);
    Queue::assertNothingPushed();
});

test('DuplicatePost: an ambiguous destination account is not guessed and is explained', function () {
    $owner = User::factory()->create();
    $origin = Brand::factory()->for($owner)->create();
    $destination = Brand::factory()->for($owner)->create();
    [$source] = duplicateSource($owner, $origin);
    SocialAccount::factory()->count(2)->for($owner)->for($destination)->create(['network' => Network::Facebook]);
    $response = $this->actingAs($owner)->post('/posts/'.$source->id.'/duplicate', ['brand_id' => $destination->id]);
    $copy = Post::ownedBy($owner->id)->where('brand_id', $destination->id)->sole();
    $response->assertRedirect(route('posts.edit', $copy))->assertSessionHas('success', fn ($text) => str_contains($text, '1 destino'));
    expect($copy->targets()->count())->toBe(0)->and($copy->status)->toBe(PostStatus::Draft);
});

test('DuplicatePost: multiple source accounts are not collapsed arbitrarily into one destination', function () {
    $owner = User::factory()->create();
    $origin = Brand::factory()->for($owner)->create();
    $destination = Brand::factory()->for($owner)->create();
    [$source] = duplicateSource($owner, $origin);
    $second = SocialAccount::factory()->for($owner)->for($origin)->create(['network' => Network::Facebook]);
    PostTarget::factory()->for($owner)->for($source)->create([
        'social_account_id' => $second->id, 'network' => Network::Facebook,
        'content_type' => ContentType::FacebookPhoto, 'text' => 'Otro texto que no se elige a ciegas',
    ]);
    SocialAccount::factory()->for($owner)->for($destination)->create(['network' => Network::Facebook]);
    $response = $this->actingAs($owner)->post('/posts/'.$source->id.'/duplicate', ['brand_id' => $destination->id]);
    $copy = Post::ownedBy($owner->id)->where('brand_id', $destination->id)->sole();
    expect($copy->targets()->count())->toBe(0);
    $response->assertSessionHas('success', fn ($text) => str_contains($text, '2 destinos'));
});

test('DuplicatePost: a Pinterest board belonging to the source account never travels to another brand', function () {
    config(['social.pinterest.enabled' => true, 'services.pinterest.client_id' => 'duplicate-test-client']);
    $owner = User::factory()->create();
    $origin = Brand::factory()->for($owner)->create();
    $destination = Brand::factory()->for($owner)->create();
    [$source] = duplicateSource($owner, $origin);
    $pinterest = SocialAccount::factory()->for($owner)->for($origin)->create(['network' => Network::Pinterest, 'meta' => ['boards' => [['id' => '123', 'name' => 'Origen']]]]);
    PostTarget::factory()->for($owner)->for($source)->create([
        'social_account_id' => $pinterest->id, 'network' => Network::Pinterest, 'content_type' => ContentType::PinterestPin,
        'options' => ['pinterest_board_id' => '123', 'pinterest_title' => 'Título original'],
    ]);
    $facebook = SocialAccount::factory()->for($owner)->for($destination)->create(['network' => Network::Facebook]);
    SocialAccount::factory()->for($owner)->for($destination)->create(['network' => Network::Pinterest, 'meta' => ['boards' => [['id' => '999', 'name' => 'Destino']]]]);
    $response = $this->actingAs($owner)->post('/posts/'.$source->id.'/duplicate', ['brand_id' => $destination->id]);
    $copy = Post::ownedBy($owner->id)->where('brand_id', $destination->id)->sole();
    expect($copy->targets()->count())->toBe(1);
    expect($copy->targets()->sole()->social_account_id)->toBe($facebook->id);
    $response->assertSessionHas('success', fn ($text) => str_contains($text, '1 destino'));
});

test('DuplicatePost: TikTok visibility must be chosen again while sponsored content is retained', function (bool $branded) {
    $owner = User::factory()->create();
    $brand = Brand::factory()->for($owner)->create();
    [$source] = duplicateSource($owner, $brand);
    $account = SocialAccount::factory()->for($owner)->for($brand)->create(['network' => Network::TikTok, 'meta' => ['direct_post' => true]]);
    $video = MediaAsset::factory()->video()->for($owner)->create();
    PostTarget::factory()->for($owner)->for($source)->create([
        'social_account_id' => $account->id, 'network' => Network::TikTok, 'content_type' => ContentType::TikTokVideo,
        'media' => [['id' => $video->id, 'alt' => null, 'cover_id' => null]],
        'text' => 'Texto patrocinado propio',
        'options' => ['privacy_level' => 'PUBLIC_TO_EVERYONE', 'brand_content_toggle' => $branded],
    ]);
    $response = $this->actingAs($owner)->post('/posts/'.$source->id.'/duplicate')->assertRedirect()->assertSessionHasNoErrors();
    $copy = Post::ownedBy($owner->id)->whereKeyNot($source->id)->sole();
    $target = $copy->targets()->where('network', Network::TikTok)->sole();
    expect($target->options ?? [])->not->toHaveKey('privacy_level')
        ->and($target->media[0]['id'])->toBe($video->id)
        ->and($target->text)->toBe('Texto patrocinado propio')
        ->and($target->options['brand_content_toggle'])->toBe($branded);
    $response->assertSessionHas('success', fn ($message) => str_contains($message, 'visibilidad de TikTok') && ! str_contains($message, 'sin copiar'));
    expect($source->targets()->where('network', Network::TikTok)->sole()->options['privacy_level'])->toBe('PUBLIC_TO_EVERYONE');
})->with(['ordinary' => false, 'branded' => true]);

test('DuplicatePost: own target media and content are independent while transient publication state is reset', function () {
    $owner = User::factory()->create();
    $brand = Brand::factory()->for($owner)->create();
    [$source, $originalTarget] = duplicateSource($owner, $brand);
    $ownImage = MediaAsset::factory()->for($owner)->create();
    $originalTarget->forceFill([
        'media' => [['id' => $ownImage->id, 'alt' => 'Descripción propia', 'cover_id' => null]],
        'first_comment' => 'Primer comentario original', 'first_comment_status' => FirstCommentStatus::Published,
        'first_comment_external_id' => 'old-comment', 'external_reference' => 'old-container',
        'progress' => ['uploaded' => true], 'error_code' => 'OLD_ERROR', 'next_attempt_at' => now()->addHour(),
    ])->save();
    $this->actingAs($owner)->post('/posts/'.$source->id.'/duplicate')->assertRedirect()->assertSessionHasNoErrors();
    $copy = Post::ownedBy($owner->id)->whereKeyNot($source->id)->sole();
    $target = $copy->targets()->sole();
    expect($target->media)->toBe([['id' => $ownImage->id, 'alt' => 'Descripción propia', 'cover_id' => null]])
        ->and($target->first_comment)->toBe('Primer comentario original')
        ->and($target->first_comment_external_id)->toBeNull()
        ->and($target->external_reference)->toBeNull()->and($target->progress)->toBeNull()
        ->and($target->error_code)->toBeNull()->and($target->next_attempt_at)->toBeNull()
        ->and($copy->design_export_id)->toBeNull();
    $target->forceFill(['media' => []])->save();
    expect($originalTarget->fresh()->media[0]['id'])->toBe($ownImage->id);
    Queue::assertNothingPushed();
});

test('DuplicatePost: invalid common media aborts atomically rather than silently losing content', function () {
    $owner = User::factory()->create();
    $brand = Brand::factory()->for($owner)->create();
    [$source] = duplicateSource($owner, $brand);
    $foreign = MediaAsset::factory()->create();
    $source->media()->sync([$foreign->id => ['position' => 0]]);
    $this->actingAs($owner)->post('/posts/'.$source->id.'/duplicate')->assertRedirect()->assertSessionHasErrorsIn('duplicate', ['media']);
    expect(Post::withoutUserScope()->count())->toBe(1)
        ->and($source->media()->pluck('media_assets.id')->all())->toBe([$foreign->id]);
    Queue::assertNothingPushed();
});

test('DuplicatePost: the service itself rejects inconsistent source brand ownership without relying on HTTP auth', function () {
    $owner = User::factory()->create();
    $other = User::factory()->create();
    $brand = Brand::factory()->for($owner)->create();
    [$source] = duplicateSource($owner, $brand);
    $foreignBrand = Brand::factory()->for($other)->create();
    $source->forceFill(['brand_id' => $foreignBrand->id])->save();
    expect(fn () => app(PostDuplicator::class)->duplicate($owner, $source->fresh(), $brand))
        ->toThrow(OwnershipException::class);
    expect(Post::withoutUserScope()->count())->toBe(1);
    Queue::assertNothingPushed();
});

test('DuplicatePost: malformed destination input never creates a draft', function (mixed $value) {
    $owner = User::factory()->create();
    $brand = Brand::factory()->for($owner)->create();
    [$source] = duplicateSource($owner, $brand);
    $this->actingAs($owner)->postJson('/posts/'.$source->id.'/duplicate', ['brand_id' => $value])
        ->assertUnprocessable()->assertJsonValidationErrors('brand_id');
    expect(Post::withoutUserScope()->count())->toBe(1);
})->with(['array' => [[1]], 'zero' => [0], 'non-integer' => ['abc']]);

test('DuplicatePost: native Instagram audio is reselected across accounts while media and content are preserved', function () {
    $owner = User::factory()->create();
    $origin = Brand::factory()->for($owner)->create();
    $destination = Brand::factory()->for($owner)->create();
    [$source] = duplicateSource($owner, $origin);
    $video = MediaAsset::factory()->video()->for($owner)->create();
    $account = SocialAccount::factory()->for($owner)->for($origin)->create(['network' => Network::Instagram, 'refresh_token' => 'fake-refresh']);
    $destinationAccount = SocialAccount::factory()->for($owner)->for($destination)->create(['network' => Network::Instagram, 'refresh_token' => 'fake-refresh']);
    $target = PostTarget::factory()->for($owner)->for($source)->create([
        'social_account_id' => $account->id, 'network' => Network::Instagram, 'content_type' => ContentType::InstagramReel,
        'media' => [['id' => $video->id, 'alt' => null, 'cover_id' => null]],
        'options' => ['instagram_audio' => ['audio_id' => '12345', 'audio_volume' => 75]],
    ]);
    $response = $this->actingAs($owner)->post('/posts/'.$source->id.'/duplicate', ['brand_id' => $destination->id]);
    $copy = Post::ownedBy($owner->id)->where('brand_id', $destination->id)->sole();
    $copiedTarget = $copy->targets()->where('network', Network::Instagram)->sole();
    expect($copiedTarget->social_account_id)->toBe($destinationAccount->id)
        ->and($copiedTarget->options ?? [])->not->toHaveKey('instagram_audio')
        ->and($copiedTarget->media[0]['id'])->toBe($video->id)
        ->and($target->fresh()->options['instagram_audio']['audio_id'])->toBe('12345');
    $response->assertSessionHas('success', fn ($text) => str_contains($text, 'audio de Instagram'));
    Http::assertNothingSent();
});

test('DuplicatePost: nested target validation errors omit that target while common content remains', function () {
    $owner = User::factory()->create();
    $brand = Brand::factory()->for($owner)->create();
    [$source, , $image] = duplicateSource($owner, $brand);
    $real = app(PostService::class);
    $posts = Mockery::mock(PostService::class);
    $posts->shouldReceive('validate')->once()->andReturn(['targets.0.media' => ['Media propia inválida.']]);
    $posts->shouldReceive('create')->once()->andReturnUsing(fn ($user, $destination, $data) => $real->create($user, $destination, $data));
    app()->instance(PostService::class, $posts);
    $response = $this->actingAs($owner)->post('/posts/'.$source->id.'/duplicate')->assertRedirect()->assertSessionHasNoErrors();
    $copy = Post::ownedBy($owner->id)->whereKeyNot($source->id)->sole();
    expect($copy->targets()->count())->toBe(0)->and($copy->media()->pluck('media_assets.id')->all())->toBe([$image->id]);
    $response->assertSessionHas('success', fn ($text) => str_contains($text, 'contenido u opciones'));
});

test('DuplicatePost: a target omitted for invalid content does not advertise choices on a nonexistent copied target', function () {
    $owner = User::factory()->create();
    $origin = Brand::factory()->for($owner)->create();
    $destination = Brand::factory()->for($owner)->create();
    [$source] = duplicateSource($owner, $origin);
    $account = SocialAccount::factory()->for($owner)->for($origin)->create(['network' => Network::Instagram, 'refresh_token' => 'fake-refresh']);
    SocialAccount::factory()->for($owner)->for($destination)->create(['network' => Network::Facebook]);
    SocialAccount::factory()->for($owner)->for($destination)->create(['network' => Network::Instagram, 'refresh_token' => 'fake-refresh']);
    $foreignVideo = MediaAsset::factory()->video()->create();
    PostTarget::factory()->for($owner)->for($source)->create([
        'social_account_id' => $account->id, 'network' => Network::Instagram, 'content_type' => ContentType::InstagramReel,
        'media' => [['id' => $foreignVideo->id, 'alt' => null, 'cover_id' => null]],
        'options' => ['instagram_audio' => ['audio_id' => '12345']],
    ]);
    $response = $this->actingAs($owner)->post('/posts/'.$source->id.'/duplicate', ['brand_id' => $destination->id]);
    $copy = Post::ownedBy($owner->id)->where('brand_id', $destination->id)->sole();
    expect($copy->targets()->count())->toBe(1)->and($copy->targets()->sole()->network)->toBe(Network::Facebook);
    $response->assertSessionHas('success', fn ($message) => str_contains($message, '1 destino') && ! str_contains($message, 'audio de Instagram'));
});

test('DuplicatePost: composer preserves branded drafts and enforces explicit non-private visibility when scheduling', function (bool $schedule, ?string $privacy, bool $valid) {
    $owner = User::factory()->create();
    $brand = Brand::factory()->for($owner)->create();
    $account = SocialAccount::factory()->for($owner)->for($brand)->create(['network' => Network::TikTok, 'meta' => ['direct_post' => true]]);
    $video = MediaAsset::factory()->video()->for($owner)->create();
    $errors = app(PostService::class)->validate($owner, $brand, [
        'text' => 'Colaboración de pago', 'schedule' => $schedule, 'scheduled_at' => now()->addDay()->toIso8601String(),
        'media' => [$video->id], 'targets' => [[
            'account_id' => $account->id, 'content_type' => 'tiktok_video',
            'options' => ['brand_content_toggle' => true, 'privacy_level' => $privacy],
        ]],
    ]);
    expect($errors)->when($valid, fn ($value) => $value->toBe([]));
    if (! $valid) {
        expect($errors)->toHaveKey('targets.0');
        expect(implode(' ', $errors['targets.0']))->toContain($privacy === 'SELF_ONLY' ? 'no puede ser privado' : 'Elige la visibilidad');
    }
})->with([
    'draft awaiting choice' => [false, null, true],
    'draft private forbidden' => [false, 'SELF_ONLY', false],
    'scheduled private forbidden' => [true, 'SELF_ONLY', false],
    'scheduled missing forbidden' => [true, null, false],
    'scheduled public allowed' => [true, 'PUBLIC_TO_EVERYONE', true],
]);
