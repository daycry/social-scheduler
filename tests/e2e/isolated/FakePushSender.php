<?php

declare(strict_types=1);

use App\Models\PushSubscription;
use App\Services\Push\PushResult;
use App\Services\Push\PushSender;

/** Never contacts a provider: only the disposable E2E bootstrap may bind it. */
final class GeneralFakePushSender implements PushSender
{
    public function __construct(private readonly string $root) {}

    public function send(PushSubscription $subscription): PushResult
    {
        if (! app()->environment('e2e') || realpath(storage_path()) !== realpath($this->root)
            || ! is_file($this->root.'/.general-run') || ! str_starts_with($subscription->endpoint, 'https://push.example.test/')) {
            throw new RuntimeException('Fake push sender requires its own disposable fixture.');
        }
        file_put_contents($this->root.'/push-receiver.ndjson', json_encode([
            'user_id' => $subscription->user_id, 'subscription_id' => $subscription->id, 'result' => 'delivered',
        ], JSON_THROW_ON_ERROR)."\n", FILE_APPEND | LOCK_EX);

        return PushResult::Delivered;
    }
}
