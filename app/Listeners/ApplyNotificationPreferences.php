<?php

namespace App\Listeners;

use App\Models\User;
use App\Services\NotificationPreferences;
use Illuminate\Notifications\Events\NotificationSending;

final class ApplyNotificationPreferences
{
    public function __construct(private readonly QueuePushNotification $push) {}

    public function handle(NotificationSending $event): ?bool
    {
        if (! $event->notifiable instanceof User) {
            return null;
        }

        $user = $event->notifiable;
        $type = NotificationPreferences::typeFor($event->notification);
        if (NotificationPreferences::allows($user, $type, $event->channel)) {
            return null;
        }

        if ($event->channel === 'database' && NotificationPreferences::allows($user, $type, 'push')) {
            $this->push->queue($user, $event->notification->id, $type);
        }

        return false;
    }
}
