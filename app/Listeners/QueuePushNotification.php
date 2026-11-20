<?php

namespace App\Listeners;

use App\Jobs\SendPushNotification;
use App\Models\PushSubscription;
use App\Models\User;
use App\Services\Push\PushConfiguration;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Notifications\Events\NotificationSent;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/** Push complementa el aviso en la base de datos y nunca sustituye su entrega. */
final class QueuePushNotification
{
    public function __construct(private readonly PushConfiguration $configuration) {}

    public function handle(NotificationSent $event): void
    {
        $user = $event->notifiable;
        if ($event->channel !== 'database' || ! $user instanceof User || ! $user->hasVerifiedEmail()
            || ! $event->response instanceof DatabaseNotification || ! $this->configuration->enabled()) {
            return;
        }
        $userId = (int) $user->id;
        $notificationId = (string) $event->response->id;
        DB::afterCommit(function () use ($userId, $notificationId): void {
            try {
                PushSubscription::ownedBy($userId)->select('id')->eachById(fn ($subscription) => SendPushNotification::dispatch($userId, (int) $subscription->id, $notificationId));
            } catch (Throwable $error) {
                Log::warning('No se pudo encolar el aviso push.', ['exception_class' => $error::class]);
            }
        });
    }
}
