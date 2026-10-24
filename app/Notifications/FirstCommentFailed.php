<?php

namespace App\Notifications;

use App\Models\Brand;
use App\Models\Post;
use App\Models\PostTarget;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification;

/**
 * Avisa (solo in-app) de que el primer comentario de un destino ya publicado no se pudo dejar, con el motivo legible. La
 * publicacion sigue publicada. Como `PublishFailed`: va por cola y guarda datos escalares, no modelos.
 */
class FirstCommentFailed extends Notification implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $backoff = 120;

    public readonly int $targetId;

    public readonly int $postId;

    public readonly int $brandId;

    public readonly string $brandName;

    public readonly string $networkValue;

    public readonly string $networkLabel;

    public readonly string $accountName;

    public readonly string $excerpt;

    public function __construct(PostTarget $target, public readonly string $reason)
    {
        $post = Post::withoutUserScope()->findOrFail($target->post_id);
        $brand = Brand::withoutUserScope()->find($post->brand_id);

        $this->targetId = $target->id;
        $this->postId = $post->id;
        $this->brandId = $post->brand_id;
        $this->brandName = $brand?->name ?? '';
        $this->networkValue = $target->network->value;
        $this->networkLabel = $target->network->label();
        $this->accountName = $target->account?->name ?? 'cuenta eliminada';
        $this->excerpt = mb_substr((string) $target->first_comment, 0, 80);
        $this->onQueue((string) config('publishing.poll_queue', 'poll'));
    }

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return ['database'];
    }

    /** @return array<string, mixed> */
    public function toArray(object $notifiable): array
    {
        return [
            'type' => 'first_comment_failed',
            'post_id' => $this->postId,
            'post_target_id' => $this->targetId,
            'brand_id' => $this->brandId,
            'brand_name' => $this->brandName,
            'network' => $this->networkValue,
            'network_label' => $this->networkLabel,
            'account_name' => $this->accountName,
            'message' => $this->reason,
            'excerpt' => $this->excerpt,
        ];
    }
}
