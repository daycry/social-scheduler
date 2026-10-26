<?php

use App\Connectors\ConnectorRegistry;
use App\Connectors\Network;
use App\Enums\FirstCommentStatus;
use App\Enums\PostStatus;
use App\Models\Brand;
use App\Models\Post;
use App\Models\PostTarget;
use App\Models\SocialAccount;
use App\Models\User;
use App\Notifications\FirstCommentFailed;
use App\Services\PublishRecovery;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Notification;
use Tests\Support\FakeCommenter;

// C-01b: paso 6 de PublishRecovery — relanza comentarios colgados y pendientes sin job vivo.

beforeEach(function () {
    Carbon::setTestNow('2026-11-01 12:00:00');
    config(['publishing.stuck_after_minutes' => 10]);
    $this->user = User::factory()->create();
    $this->brand = Brand::factory()->for($this->user)->create();
    $this->account = SocialAccount::factory()->for($this->user)->create(['brand_id' => $this->brand->id, 'network' => Network::Instagram]);
    $this->net = new FakeCommenter;
    $this->app->instance(ConnectorRegistry::class, (new ConnectorRegistry)->registerPublisher(Network::Instagram, $this->net));
});

function recTarget(FirstCommentStatus $status, array $attrs = []): PostTarget
{
    $post = Post::factory()->create(['brand_id' => test()->brand->id, 'user_id' => test()->user->id, 'status' => PostStatus::Published]);

    return PostTarget::factory()->create(array_merge([
        'post_id' => $post->id, 'user_id' => test()->user->id, 'social_account_id' => test()->account->id, 'status' => PostStatus::Published,
        'external_id' => 'MEDIA1', 'first_comment' => 'Hola', 'first_comment_status' => $status, 'first_comment_attempts' => 1,
    ], $attrs));
}

test('relanza el comentario colgado en comentando pasado el plazo', function () {
    $t = recTarget(FirstCommentStatus::Commenting, ['first_comment_at' => now()->subMinutes(30)]);

    $r = app(PublishRecovery::class)->run();

    expect($r['comments'])->toBe(1)->and($t->fresh()->first_comment_status)->toBe(FirstCommentStatus::Published);
});

test('relanza el pendiente sin job vivo desde hace mas de 10 minutos', function () {
    $t = recTarget(FirstCommentStatus::Pending);
    PostTarget::withoutUserScope()->whereKey($t->id)->update(['updated_at' => now()->subMinutes(20)]);

    expect(app(PublishRecovery::class)->run()['comments'])->toBe(1)
        ->and($t->fresh()->first_comment_status)->toBe(FirstCommentStatus::Published);
});

test('no toca lo reciente ni lo ya resuelto', function () {
    recTarget(FirstCommentStatus::Commenting, ['first_comment_at' => now()->subMinute()]);
    recTarget(FirstCommentStatus::Pending);
    recTarget(FirstCommentStatus::Failed, ['first_comment_at' => now()->subDay()]);
    recTarget(FirstCommentStatus::Published, ['first_comment_external_id' => 'x']);

    expect(app(PublishRecovery::class)->run()['comments'])->toBe(0)->and($this->net->commentCalls)->toBe(0);
});

test('un pendiente o colgado sin external_id se cierra en fallido con motivo y no se relanza en cada pasada', function (FirstCommentStatus $status) {
    Notification::fake();
    $t = recTarget($status, ['external_id' => null, 'first_comment_at' => now()->subMinutes(30)]);
    PostTarget::withoutUserScope()->whereKey($t->id)->update(['updated_at' => now()->subMinutes(30)]);

    expect(app(PublishRecovery::class)->run()['comments'])->toBe(0);

    $t = $t->fresh();
    expect($t->first_comment_status)->toBe(FirstCommentStatus::Failed)->and($t->first_comment_error)->toContain('identificador de la publicacion')
        ->and($this->net->commentCalls)->toBe(0)->and($this->net->findCommentCalls)->toBe(0);
    Notification::assertSentToTimes($this->user, FirstCommentFailed::class, 1);
    expect(app(PublishRecovery::class)->run()['comments'])->toBe(0);
})->with([[FirstCommentStatus::Pending], [FirstCommentStatus::Commenting]]);
