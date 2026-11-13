<?php

use App\Connectors\Network;
use App\Enums\PostStatus;
use App\Models\Brand;
use App\Models\Post;
use App\Models\PostTarget;
use App\Models\SocialAccount;
use App\Models\User;
use App\Services\PublishOutcomes;
use Illuminate\Support\Facades\Notification;

beforeEach(function () {
    Notification::fake();
    $this->user = User::factory()->create();
    $this->brand = Brand::factory()->for($this->user)->create();
});

function scrubTarget(array $progress): PostTarget
{
    $account = SocialAccount::factory()->create(['user_id' => test()->user->id, 'brand_id' => test()->brand->id, 'network' => Network::Pinterest, 'external_id' => 'pin1']);
    $post = Post::factory()->create(['brand_id' => test()->brand->id, 'user_id' => test()->user->id, 'status' => PostStatus::Publishing]);

    return PostTarget::factory()->create([
        'post_id' => $post->id, 'user_id' => test()->user->id, 'social_account_id' => $account->id, 'network' => $account->network,
        'content_type' => 'pinterest_video', 'status' => PostStatus::Publishing, 'attempts' => 4, 'started_at' => now(), 'progress' => $progress,
    ]);
}

test('un destino que acaba en fallida pierde de su progreso las URLs firmadas y conserva el resto', function () {
    $target = scrubTarget([
        'pinterest_upload' => ['url' => 'https://pinterest-media-upload.s3.amazonaws.com/?X-Amz-Signature=SECRETO', 'parameters' => ['policy' => 'P'], 'issued_at' => 1],
        'pinterest_registrations' => 1,
        'upload_url' => 'https://upload.tiktokapis.com/?upload_id=SECRETO', 'phase' => 'uploading', 'next' => 3,
    ]);

    $failed = app(PublishOutcomes::class)->failed($target, 'media_no_disponible', 'El video no esta disponible');

    $progress = PostTarget::withoutUserScope()->find($failed->id)->progress;
    expect($progress)->toBe(['pinterest_registrations' => 1, 'phase' => 'uploading', 'next' => 3])
        ->and(json_encode(PostTarget::withoutUserScope()->find($failed->id)->getAttributes()))->not->toContain('SECRETO');
});

test('el transitorio convertido en reintentos_agotados tambien limpia la URL firmada', function () {
    $target = scrubTarget(['pinterest_upload' => ['url' => 'https://x.amazonaws.com/?sig=SECRETO', 'parameters' => [], 'issued_at' => 1]]);

    $failed = app(PublishOutcomes::class)->transient($target, 'sin tiempo', 'sin_tiempo');

    expect($failed->status)->toBe(PostStatus::Failed)
        ->and(json_encode(PostTarget::withoutUserScope()->find($failed->id)->getAttributes()))->not->toContain('SECRETO');
});
