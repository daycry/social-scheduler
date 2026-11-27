<?php

namespace App\Notifications;

use App\Models\Goal;
use App\Services\NotificationPreferences;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

final class GoalAlert extends Notification implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $backoff = 120;

    /** @param array<string, mixed> $payload */
    public function __construct(public readonly int $ownerId, public readonly array $payload, public readonly bool $mailOnly = false)
    {
        $this->onQueue((string) config('goals.mail_queue', 'poll'));
    }

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        if ((int) $notifiable->id !== $this->ownerId) {
            return [];
        }

        return $this->mailOnly ? ($this->shouldSend($notifiable, 'mail') ? ['mail'] : []) : ['database'];
    }

    public function shouldSend(object $notifiable, string $channel): bool
    {
        if ((int) $notifiable->id !== $this->ownerId) {
            return false;
        }
        if ($channel === 'database') {
            return ! $this->mailOnly;
        }

        return $channel === 'mail' && $this->mailOnly && $notifiable->hasVerifiedEmail()
            && NotificationPreferences::allows($notifiable, 'goal_alert', 'mail')
            && Goal::ownedBy($this->ownerId)->whereKey($this->payload['goal_id'])->where('enabled', true)->where('mail_enabled', true)->exists();
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)->subject('Objetivo: '.$this->payload['title'])
            ->line($this->payload['message'])
            ->action('Ver objetivos', url($this->payload['url']));
    }

    /** @return array<string, mixed> */
    public function toArray(object $notifiable): array
    {
        return ['type' => 'goal_alert'] + $this->payload;
    }
}
