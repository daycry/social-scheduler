<?php

namespace App\Jobs;

use App\Models\PushSubscription;
use App\Models\User;
use App\Services\NotificationPreferences;
use App\Services\Push\PushConfiguration;
use App\Services\Push\PushResult;
use App\Services\Push\PushSender;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/** La cola solo transporta identificadores; el worker vuelve a comprobar consentimiento y propietario. */
final class SendPushNotification implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $timeout = 20;

    public int $uniqueFor = 600;

    private ?string $pushOnlyType = null;

    public function __construct(
        public readonly int $userId,
        public readonly int $subscriptionId,
        public readonly string $notificationId,
        ?string $pushOnlyType = null,
    ) {
        $this->pushOnlyType = $pushOnlyType;
        $this->onQueue(config('push.queue', 'poll'));
    }

    public function uniqueId(): string
    {
        return $this->subscriptionId.':'.$this->notificationId;
    }

    public function handle(PushSender $sender, PushConfiguration $configuration): void
    {
        if (! $configuration->enabled()) {
            return;
        }
        DB::transaction(function () use ($sender): void {
            $user = User::find($this->userId);
            $subscription = PushSubscription::ownedBy($this->userId)->whereKey($this->subscriptionId)->lockForUpdate()->first();
            if ($user === null || ! $user->hasVerifiedEmail() || $subscription === null
                || $subscription->last_notification_id === $this->notificationId || ! $this->allowsPush($user)) {
                return;
            }
            // Marca técnica para adquirir el bloqueo de escritura SQLite antes de HTTP.
            // Si hay Retry se revierte: no representa un historial durable de intentos.
            $subscription->forceFill(['last_attempted_at' => now()])->save();
            $result = $sender->send($subscription);
            if ($result === PushResult::Retry) {
                throw new RuntimeException('Entrega push temporalmente no disponible.');
            }
            if ($result === PushResult::Expired) {
                $subscription->delete();

                return;
            }
            $subscription->forceFill([
                'last_notification_id' => $this->notificationId,
                'last_sent_at' => $result === PushResult::Delivered ? now() : $subscription->last_sent_at,
            ])->save();
        });
    }

    private function allowsPush(User $user): bool
    {
        $type = $this->pushOnlyType;
        if ($type === null) {
            $notice = $user->unreadNotifications()->whereKey($this->notificationId)->first();
            if ($notice === null) {
                return false;
            }
            $type = NotificationPreferences::typeFor($notice->type, $notice->data['type'] ?? '');
        }

        return NotificationPreferences::allows($user, $type, 'push');
    }

    public function backoff(): array
    {
        return [30, 120];
    }
}
