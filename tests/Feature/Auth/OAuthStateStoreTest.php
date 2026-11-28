<?php

use App\Support\OAuthStateStore;
use Illuminate\Support\Facades\Cache;

test('the OAuth state store consumes only the matching attempt and enforces expiry', function () {
    $this->freezeTime();
    $store = app(OAuthStateStore::class);
    $key = 'test.oauth.state';
    $ttl = 30;
    $first = ['state' => 'first', 'user_id' => 1, 'issued_at' => now()->timestamp];
    $second = ['state' => 'second', 'user_id' => 1, 'issued_at' => now()->timestamp - 1];

    $store->remember($key, 'first', $first, $ttl, 10);
    $store->remember($key, 'second', $second, $ttl, 10);
    expect($store->consume($key, 'wrong', 1, $ttl, verifyStoredState: true))->toBeNull()
        ->and($store->consume($key, 'first', 1, $ttl, verifyStoredState: true))->toBe($first)
        ->and($store->consume($key, 'first', 1, $ttl, verifyStoredState: true))->toBeNull();

    $this->travel(29)->seconds();
    expect(Cache::get($key)[hash('sha256', 'second')] ?? null)->toBe($second);
    expect($store->consume($key, 'second', 1, $ttl, verifyStoredState: true))->toBeNull();
});

test('the state store caps attempts and binds encrypted-verifier attempts to an owner', function () {
    $this->freezeTime();
    $store = app(OAuthStateStore::class);
    $key = 'test.oauth.verifier';
    $ttl = 30;
    $attempt = ['user_id' => 1, 'issued_at' => now()->timestamp, 'verifier' => 'ciphertext'];
    foreach (['discarded', 'wrong-owner', 'valid'] as $state) {
        $store->remember($key, $state, $attempt, $ttl, 2);
    }

    expect($store->consume($key, 'discarded', 1, $ttl, verifyStoredState: false))->toBeNull()
        ->and($store->consume($key, 'wrong-owner', 2, $ttl, verifyStoredState: false))->toBeNull()
        ->and($store->consume($key, 'wrong-owner', 1, $ttl, verifyStoredState: false))->toBeNull()
        ->and($store->consume($key, 'valid', 1, $ttl, verifyStoredState: false))->toBe($attempt);
});

test('social OAuth verifies the stored state even when the lookup hash matches', function () {
    $store = app(OAuthStateStore::class);
    $key = 'test.oauth.stored-state';
    $attempt = ['state' => 'mismatch', 'user_id' => 1, 'issued_at' => now()->timestamp];

    $store->remember($key, 'lookup-state', $attempt, 30, 10);

    expect($store->consume($key, 'lookup-state', 1, 30, verifyStoredState: true))->toBeNull()
        ->and($store->consume($key, 'lookup-state', 1, 30, verifyStoredState: false))->toBe($attempt);
});

test('the OAuth state store rejects attempts without owner or issue date', function (array $attempt) {
    $store = app(OAuthStateStore::class);
    $key = 'test.oauth.malformed';

    expect(fn () => $store->remember($key, 'state', $attempt, 30, 10))
        ->toThrow(InvalidArgumentException::class);
    expect(Cache::has($key))->toBeFalse();
})->with([
    'missing owner' => [['issued_at' => 1]],
    'missing timestamp' => [['user_id' => 1]],
    'empty owner' => [['user_id' => '', 'issued_at' => 1]],
    'wrong timestamp type' => [['user_id' => 1, 'issued_at' => '1']],
]);
