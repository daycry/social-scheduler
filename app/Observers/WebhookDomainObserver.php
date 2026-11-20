<?php

namespace App\Observers;

use App\Enums\PostStatus;
use App\Events\OutboundDomainEvent;
use App\Models\InboxComment;
use App\Models\Post;
use App\Models\PostTarget;
use App\Models\SocialAccount;
use App\Models\Webhook;
use Illuminate\Support\Facades\Event;

/** Adapta hechos persistidos a eventos de dominio; no modifica conectores ni realiza peticiones. */
final class WebhookDomainObserver
{
    public function updated(PostTarget|InboxComment $model): void
    {
        if (! $model instanceof PostTarget || ! $model->wasChanged('status') || ! in_array($model->status, [PostStatus::Published, PostStatus::Failed], true)) {
            return;
        }
        $post = Post::ownedBy($model->user_id)->withTrashed()->find($model->post_id);
        $event = $model->status === PostStatus::Published ? 'post.published' : 'post.failed';
        if ($post === null || ! $this->hasSubscribers((int) $model->user_id, (int) $post->brand_id, $event)
            || ($model->social_account_id !== null && ! $this->ownsAccount((int) $model->user_id, (int) $post->brand_id, $model->social_account_id))) {
            return;
        }
        Event::dispatch(new OutboundDomainEvent((int) $model->user_id, (int) $post->brand_id,
            $event, [
                'post_id' => (int) $post->id, 'target_id' => (int) $model->id,
                'network' => $model->network->value, 'status' => $model->status->value,
            ]));
    }

    public function created(PostTarget|InboxComment $model): void
    {
        if (! $model instanceof InboxComment || $model->is_own
            || ! $this->hasSubscribers((int) $model->user_id, (int) $model->brand_id, 'comment.created')
            || ! $this->ownsAccount((int) $model->user_id, (int) $model->brand_id, $model->social_account_id)) {
            return;
        }
        $target = PostTarget::ownedBy($model->user_id)->whereKey($model->post_target_id)->where('social_account_id', $model->social_account_id)->first();
        if ($target === null || ! Post::ownedBy($model->user_id)->whereKey($target->post_id)->where('brand_id', $model->brand_id)->exists()) {
            return;
        }
        Event::dispatch(new OutboundDomainEvent((int) $model->user_id, (int) $model->brand_id, 'comment.created', [
            'comment_id' => (int) $model->id, 'target_id' => (int) $target->id,
            'post_id' => (int) $target->post_id, 'network' => $model->network->value,
        ]));
    }

    private function ownsAccount(int $userId, int $brandId, ?int $accountId): bool
    {
        return $accountId !== null && SocialAccount::ownedBy($userId)->whereKey($accountId)->where('brand_id', $brandId)->exists();
    }

    private function hasSubscribers(int $userId, int $brandId, string $event): bool
    {
        return Webhook::ownedBy($userId)->where('brand_id', $brandId)->where('enabled', true)
            ->whereJsonContains('events', $event)->exists();
    }
}
