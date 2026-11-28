<?php

use App\Connectors\Network;
use App\Enums\PostStatus;
use App\Events\OutboundDomainEvent;
use App\Jobs\DeliverWebhook;
use App\Models\Brand;
use App\Models\InboxComment;
use App\Models\Post;
use App\Models\PostTarget;
use App\Models\SocialAccount;
use App\Models\User;
use App\Models\WebhookEvent;
use App\Services\InboxService;
use App\Services\Media\UrlGuard;
use App\Services\Webhooks\WebhookSubscriptions;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\Support\FakeGraph;

beforeEach(function () {
    Http::preventStrayRequests();
    Queue::fake();
    app()->instance(UrlGuard::class, new UrlGuard(fn () => ['93.184.216.34']));
});

function webhookCommentFixture(): array
{
    $owner = User::factory()->create();
    $brand = Brand::factory()->for($owner)->create();
    app(WebhookSubscriptions::class)->create($owner, $brand, [
        'name' => 'Comentarios', 'url' => 'https://hooks.example.test/comments',
        'events' => ['comment.created'], 'secret' => str_repeat('a', 64),
    ]);
    $account = SocialAccount::factory()->for($owner)->for($brand)->create([
        'network' => Network::Instagram, 'username' => 'owner-account',
        'scopes' => ['instagram_manage_comments'],
    ]);
    $post = Post::factory()->for($brand)->create();
    PostTarget::factory()->for($post)->create([
        'social_account_id' => $account->id, 'network' => Network::Instagram,
        'status' => PostStatus::Published, 'external_id' => 'published-post', 'published_at' => now()->subDay(),
    ]);
    $graph = new FakeGraph;
    $graph->on('GET', '#^published-post/comments$#', ['data' => [
        ['id' => 'received-comment', 'text' => 'COMMENT-PRIVATE-TEXT', 'username' => 'reader', 'timestamp' => now()->subHour()->toIso8601String()],
        ['id' => 'own-reply', 'text' => 'Respuesta', 'username' => 'owner-account', 'timestamp' => now()->toIso8601String()],
    ]]);

    return [$owner, $account];
}

test('WebhookDomain: repeated real inbox sync emits only the new third-party comment and no sensitive text', function () {
    [$owner, $account] = webhookCommentFixture();
    expect(app(InboxService::class)->sync($account))->toBe(1)
        ->and(app(InboxService::class)->sync($account))->toBe(0);
    Queue::assertPushed(DeliverWebhook::class, 1);
    $event = WebhookEvent::ownedBy($owner->id)->sole();
    expect($event->event)->toBe('comment.created')->and($event->body)->not->toContain('COMMENT-PRIVATE-TEXT')
        ->and(InboxComment::ownedBy($owner->id)->count())->toBe(2);
});

test('WebhookDomain: an event persistence failure rolls back inbox insertion so retry can emit the missing event', function () {
    [$owner, $account] = webhookCommentFixture();
    $breakOutbox = true;
    Event::listen(OutboundDomainEvent::class, function (OutboundDomainEvent $event) use (&$breakOutbox): void {
        if ($breakOutbox && $event->name === 'comment.created') {
            throw new RuntimeException('test outbox write failure');
        }
    });
    expect(fn () => app(InboxService::class)->sync($account))->toThrow(RuntimeException::class, 'test outbox write failure');
    expect(InboxComment::ownedBy($owner->id)->where('is_own', false)->count())->toBe(0)
        ->and(WebhookEvent::ownedBy($owner->id)->count())->toBe(0);
    Queue::assertNothingPushed();
    $breakOutbox = false;
    expect(app(InboxService::class)->sync($account))->toBe(1);
    Queue::assertPushed(DeliverWebhook::class, 1);
});
