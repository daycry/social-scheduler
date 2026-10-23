<?php

namespace App\Notifications;

use App\Models\Brand;
use App\Models\Post;
use App\Models\PostTarget;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Avisa de que una publicacion ha fallado definitivamente, con el motivo legible. Nunca incluye tokens ni URLs firmadas.
 *
 * Como `ReconnectRequired`: va por cola (un job por canal, `database` primero) y guarda datos escalares, no
 * modelos (el worker no tiene usuario autenticado y el UserScope impediria restaurarlos).
 */
class PublishFailed extends Notification implements ShouldQueue
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

    public readonly ?string $scheduledLocal;

    public readonly ?string $errorCode;

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
        $this->excerpt = mb_substr((string) $post->text, 0, 80);
        $this->scheduledLocal = $post->scheduled_at?->copy()->setTimezone($brand?->timezone ?: 'UTC')->format('d/m/Y H:i');
        $this->errorCode = $target->error_code;
        $this->onQueue((string) config('publishing.poll_queue', 'poll'));
    }

    /** @return list<string> `database` primero: es el aviso que no puede perderse. */
    public function via(object $notifiable): array
    {
        return ['database', 'mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $mail = (new MailMessage)
            ->subject("No se pudo publicar en {$this->networkLabel}")
            ->line("La publicacion de {$this->brandName} en {$this->networkLabel} «{$this->accountName}»".($this->scheduledLocal ? " programada para el {$this->scheduledLocal}" : '').' no se ha podido publicar.')
            ->line("Motivo: {$this->reason}");

        if ($this->excerpt !== '') {
            $mail->line("Texto: «{$this->excerpt}»");
        }

        return $mail->action('Revisar la publicacion', route('posts.edit', $this->postId));
    }

    /** @return array<string, mixed> */
    public function toArray(object $notifiable): array
    {
        return [
            'type' => 'publish_failed',
            'post_id' => $this->postId,
            'post_target_id' => $this->targetId,
            'brand_id' => $this->brandId,
            'brand_name' => $this->brandName,
            'network' => $this->networkValue,
            'network_label' => $this->networkLabel,
            'account_name' => $this->accountName,
            'error_code' => $this->errorCode,
            'message' => $this->reason,
            'excerpt' => $this->excerpt,
            'scheduled_at' => $this->scheduledLocal,
        ];
    }
}
