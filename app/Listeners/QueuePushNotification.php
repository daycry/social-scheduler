<?php

namespace App\Listeners;

use App\Jobs\SendPushNotification;
use App\Models\PushSubscription;
use App\Models\User;
use App\Services\NotificationPreferences;
use App\Services\Push\PushConfiguration;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Notifications\Events\NotificationSent;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

final class QueuePushNotification
{
    public function __construct(private readonly PushConfiguration $configuration) {}

    public function handle(NotificationSent $event): void
    {
        $user = $event->notifiable;
        if ($event->channel !== 'database' || ! $user instanceof User || ! $event->response instanceof DatabaseNotification
            || ! NotificationPreferences::allows($user, NotificationPreferences::typeFor($event->notification), 'push')) {
            return;
        }

        $this->queue($user, (string) $event->response->id);
    }

    /** Los avisos solo push llevan su tipo porque no tienen un aviso asociado en la bandeja. */
    public function queue(User $user, string $notificationId, ?string $pushOnlyType = null): void
    {
        if (! $user->hasVerifiedEmail() || ! $this->configuration->enabled()) {
            return;
        }
        $userId = (int) $user->id;
        DB::afterCommit(function () use ($userId, $notificationId, $pushOnlyType): void {
            try {
                PushSubscription::ownedBy($userId)->select('id')->eachById(fn ($subscription) => SendPushNotification::dispatch($userId, (int) $subscription->id, $notificationId, $pushOnlyType));
            } catch (Throwable $error) {
                Log::warning('No se pudo encolar el aviso push.', ['exception_class' => $error::class]);
            }
        });
    }
}
