<?php

namespace App\Support;

use Illuminate\Support\Facades\Cache;
use InvalidArgumentException;

/** Stores bounded sets of one-use attempts under caller-owned session or user keys. */
final class OAuthStateStore
{
    private const LOCK_SECONDS = 5;

    /** @param array{user_id: int|string, issued_at: int, state?: string, ...} $attempt */
    public function remember(string $key, string $state, array $attempt, int $ttl, int $limit): void
    {
        $userId = $attempt['user_id'] ?? null;
        $validOwner = (is_int($userId) && $userId > 0) || (is_string($userId) && $userId !== '');
        if (! $validOwner || ! is_int($attempt['issued_at'] ?? null)) {
            throw new InvalidArgumentException('An OAuth attempt requires an owner and an issue timestamp.');
        }

        Cache::lock($key.'.lock', self::LOCK_SECONDS)->block(self::LOCK_SECONDS, function () use ($key, $state, $attempt, $ttl, $limit): void {
            $validAfter = now()->timestamp - $ttl;
            $attempts = array_filter($this->attempts($key), fn ($entry) => is_array($entry) && ($entry['issued_at'] ?? 0) > $validAfter);
            $attempts[hash('sha256', $state)] = $attempt;

            Cache::put($key, array_slice($attempts, -$limit, null, true), $ttl);
        });
    }

    /** The hash identifies the attempt. Social flows also verify their stored raw state. */
    public function consume(string $key, string $state, int|string $userId, int $ttl, bool $verifyStoredState): ?array
    {
        if ($state === '' || strlen($state) > 128) {
            return null;
        }

        return Cache::lock($key.'.lock', self::LOCK_SECONDS)->block(self::LOCK_SECONDS, function () use ($key, $state, $userId, $ttl, $verifyStoredState): ?array {
            $attempts = $this->attempts($key);
            $hash = hash('sha256', $state);
            $attempt = $attempts[$hash] ?? null;
            if (! is_array($attempt)
                || ($verifyStoredState && (! is_string($attempt['state'] ?? null) || ! hash_equals($attempt['state'], $state)))) {
                return null;
            }

            unset($attempts[$hash]);
            Cache::put($key, $attempts, $ttl);

            if (($attempt['user_id'] ?? null) !== $userId || ($attempt['issued_at'] ?? 0) <= now()->timestamp - $ttl) {
                return null;
            }

            return $attempt;
        });
    }

    private function attempts(string $key): array
    {
        $attempts = Cache::get($key, []);

        return is_array($attempts) ? $attempts : [];
    }
}
