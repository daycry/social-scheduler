<?php

use App\Jobs\SendPushNotification;
use App\Listeners\QueuePushNotification;
use App\Models\PushSubscription;
use App\Models\User;
use App\Notifications\PostWorkflowActivity;
use App\Services\Media\DiscardedBody;
use App\Services\Media\UrlGuard;
use App\Services\Push\PushConfiguration;
use App\Services\Push\PushResult;
use App\Services\Push\PushSender;
use App\Services\Push\PushSubscriptions;
use Illuminate\Notifications\Events\NotificationSent;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;

beforeEach(fn () => Http::preventStrayRequests());

test('PushSubscription: the public manifest describes an installable app without private start data', function () {
    $file = public_path('manifest.webmanifest');
    expect(is_file($file))->toBeTrue();
    $manifest = json_decode(file_get_contents($file), true, flags: JSON_THROW_ON_ERROR);
    expect($manifest['name'])->toBe('Social Scheduler')->and($manifest['start_url'])->toBe('/')
        ->and($manifest['scope'])->toBe('/')->and($manifest['display'])->toBe('standalone');
    $sizes = array_column($manifest['icons'], 'sizes');
    expect($sizes)->toContain('192x192', '512x512');
    foreach ($manifest['icons'] as $icon) {
        expect(is_file(public_path(ltrim($icon['src'], '/'))))->toBeTrue();
    }
});

test('PushSubscription: absent server keys disable push and explain the state on the settings page', function () {
    config()->set(['push.vapid.public_key' => '', 'push.vapid.private_key' => '']);
    $owner = User::factory()->create();
    $response = $this->actingAs($owner)->get('/profile/notifications')->assertOk();
    expect($response->inertiaProps('push.enabled'))->toBeFalse()
        ->and($response->inertiaProps('push.unavailable_reason'))->toBeString();
    Http::assertNothingSent();
});

test('PushSubscription: the service worker exists as a public static asset for nginx', function () {
    expect(is_file(public_path('sw.js')))->toBeTrue();
    $script = file_get_contents(public_path('sw.js'));
    expect($script)->toContain('notificationclick')->toContain('push');
    Http::assertNothingSent();
});

test('PushSubscription: device identification outlives thirty days and remains private', function () {
    $keys = pushTestKeys();
    app()->instance(UrlGuard::class, new UrlGuard(fn () => ['93.184.216.34']));
    $owner = User::factory()->create();
    $response = $this->actingAs($owner)->post('/profile/push-subscriptions', [
        'endpoint' => 'https://push.example.test/long-lived', 'keys' => $keys, 'device_name' => 'Duradero',
    ])->assertRedirect()->assertSessionHasNoErrors();
    $cookie = collect($response->headers->getCookies())->first(fn ($cookie) => $cookie->getName() === 'push_device');
    expect($cookie)->not->toBeNull()->and($cookie->getExpiresTime())->toBeGreaterThan(now()->addYear()->timestamp)
        ->and($cookie->isHttpOnly())->toBeTrue()->and($cookie->getSameSite())->toBe('lax');
});

test('PushSubscription: a foreign endpoint is rejected as a manageable validation error without transferring ownership', function () {
    $keys = pushTestKeys();
    app()->instance(UrlGuard::class, new UrlGuard(fn () => ['93.184.216.34']));
    $owner = User::factory()->create();
    $other = User::factory()->create();
    $data = ['endpoint' => 'https://push.example.test/shared-browser', 'keys' => $keys, 'device_name' => 'Uno'];
    $subscription = app(PushSubscriptions::class)->subscribe($owner, $data);
    $this->actingAs($other)->from('/profile/notifications')->post('/profile/push-subscriptions', $data)
        ->assertRedirect('/profile/notifications')->assertSessionHasErrors('endpoint')
        ->assertSessionMissing('_old_input.endpoint')->assertSessionMissing('_old_input.keys');
    expect($subscription->fresh()->user_id)->toBe($owner->id)->and(PushSubscription::withoutUserScope()->count())->toBe(1);
});

test('PushSubscription: device subscriptions are owner scoped encrypted and revocable without exposing endpoint keys', function () {
    $keys = pushTestKeys();
    app()->instance(UrlGuard::class, new UrlGuard(fn () => ['93.184.216.34']));
    $owner = User::factory()->create();
    $other = User::factory()->create();
    $endpoint = 'https://push.example.test/subscription-one';
    $this->actingAs($owner)->post('/profile/push-subscriptions', [
        'endpoint' => $endpoint, 'keys' => $keys, 'device_name' => 'Navegador uno', 'user_id' => $other->id,
    ])->assertRedirect()->assertSessionHasNoErrors();
    $subscription = PushSubscription::ownedBy($owner->id)->sole();
    expect($subscription->user_id)->toBe($owner->id)->and($subscription->endpoint)->toBe($endpoint)
        ->and($subscription->getRawOriginal('endpoint'))->not->toBe($endpoint)
        ->and($subscription->getRawOriginal('keys'))->not->toContain($keys['auth']);
    $response = $this->get('/profile/notifications')->assertOk();
    $row = $response->inertiaProps('subscriptions.0');
    expect($row['id'])->toBe($subscription->id)->and($row)->not->toHaveKey('endpoint')->not->toHaveKey('keys');
    $this->actingAs($other)->delete('/profile/push-subscriptions/'.$subscription->id)->assertNotFound();
    $this->actingAs($owner)->delete('/profile/push-subscriptions/'.$subscription->id)->assertRedirect();
    expect(PushSubscription::withoutUserScope()->count())->toBe(0);
    Http::assertNothingSent();
});

test('PushSubscription: device creation is bounded per owner', function () {
    $keys = pushTestKeys();
    config()->set('push.max_devices_per_user', 1);
    app()->instance(UrlGuard::class, new UrlGuard(fn () => ['93.184.216.34']));
    $owner = User::factory()->create();
    $data = ['endpoint' => 'https://push.example.test/one', 'keys' => $keys, 'device_name' => 'Uno'];
    $this->actingAs($owner)->post('/profile/push-subscriptions', $data)->assertRedirect()->assertSessionHasNoErrors();
    $this->postJson('/profile/push-subscriptions', array_replace($data, ['endpoint' => 'https://push.example.test/two']))
        ->assertUnprocessable()->assertJsonValidationErrors('device_name');
    expect(PushSubscription::ownedBy($owner->id)->count())->toBe(1);
});

test('PushSubscription: reactivating an owned endpoint restores a missing device cookie and logout preserves other devices', function () {
    $keys = pushTestKeys();
    app()->instance(UrlGuard::class, new UrlGuard(fn () => ['93.184.216.34']));
    $owner = User::factory()->create();
    $data = ['endpoint' => 'https://push.example.test/restore', 'keys' => $keys, 'device_name' => 'Actual'];
    $current = app(PushSubscriptions::class)->subscribe($owner, $data);
    $other = app(PushSubscriptions::class)->subscribe($owner, array_replace($data, ['endpoint' => 'https://push.example.test/other']));
    $this->actingAs($owner)->post('/profile/push-subscriptions', $data)->assertRedirect()->assertCookie('push_device', (string) $current->id);
    expect(PushSubscription::ownedBy($owner->id)->count())->toBe(2);
    $response = $this->withCookie('push_device', (string) $current->id)->get('/profile/notifications')->assertOk();
    expect(collect($response->inertiaProps('subscriptions'))->firstWhere('id', $current->id)['current'])->toBeTrue();
    $cookie = collect($response->headers->getCookies())->first(fn ($cookie) => $cookie->getName() === 'push_device');
    expect($cookie)->not->toBeNull()->and($cookie->getExpiresTime())->toBeGreaterThan(now()->addYear()->timestamp);
    $this->post('/logout')->assertRedirect();
    expect(PushSubscription::ownedBy($owner->id)->pluck('id')->all())->toBe([$other->id]);
});

test('PushSubscription: a validation failure never flashes endpoint or encryption keys', function () {
    $keys = pushTestKeys();
    $owner = User::factory()->create();
    $this->actingAs($owner)->from('/profile/notifications')->post('/profile/push-subscriptions', [
        'endpoint' => 'https://push.example.test/private-capability', 'keys' => $keys, 'device_name' => '',
    ])->assertRedirect('/profile/notifications')->assertSessionHasErrors('device_name')
        ->assertSessionMissing('_old_input.endpoint')->assertSessionMissing('_old_input.keys');
    expect(PushSubscription::ownedBy($owner->id)->count())->toBe(0);
});

test('PushSubscription: a storage outage does not prevent logout or retain the device cookie', function () {
    $keys = pushTestKeys();
    app()->instance(UrlGuard::class, new UrlGuard(fn () => ['93.184.216.34']));
    $owner = User::factory()->create();
    $current = app(PushSubscriptions::class)->subscribe($owner, [
        'endpoint' => 'https://push.example.test/logout', 'keys' => $keys, 'device_name' => 'Actual',
    ]);
    Log::spy();
    DB::connection()->beforeExecuting(function (string $query) {
        if (str_starts_with(strtolower($query), 'delete') && str_contains($query, 'push_subscriptions')) {
            throw new RuntimeException('Private storage failure detail.');
        }
    });
    $this->actingAs($owner)->withCookie('push_device', (string) $current->id)->post('/logout')
        ->assertRedirect()->assertCookieExpired('push_device');
    $this->assertGuest();
    expect($current->fresh())->not->toBeNull();
    Log::shouldHaveReceived('warning')->with('No se pudo revocar el dispositivo push al cerrar sesión.', ['exception_class' => RuntimeException::class])->once();
});

test('PushDelivery: the listener ignores non database channels unverified users and disabled push', function (string $condition) {
    $keys = pushTestKeys();
    app()->instance(UrlGuard::class, new UrlGuard(fn () => ['93.184.216.34']));
    $owner = User::factory()->create();
    app(PushSubscriptions::class)->subscribe($owner, ['endpoint' => 'https://push.example.test/ignored', 'keys' => $keys, 'device_name' => 'Uno']);
    $message = new PostWorkflowActivity('approval_requested', null, null, 'Private Brand', 'Private message');
    $record = $owner->notifications()->create(['id' => (string) Str::uuid(), 'type' => PostWorkflowActivity::class, 'data' => []]);
    if ($condition === 'unverified') {
        $owner->forceFill(['email_verified_at' => null])->save();
    }
    if ($condition === 'disabled') {
        config()->set('push.enabled', false);
    }
    Queue::fake();
    app(QueuePushNotification::class)->handle(new NotificationSent(
        $owner, $message, $condition === 'mail' ? 'mail' : 'database', $record,
    ));
    Queue::assertNothingPushed();
    Http::assertNothingSent();
})->with(['unverified', 'disabled', 'mail']);

test('PushSubscription: configuration writes have a per-owner rate budget', function () {
    $keys = pushTestKeys();
    config()->set('push.settings_per_minute', 1);
    app()->instance(UrlGuard::class, new UrlGuard(fn () => ['93.184.216.34']));
    $owner = User::factory()->create();
    $other = User::factory()->create();
    $data = ['endpoint' => 'https://push.example.test/one', 'keys' => $keys, 'device_name' => 'Uno'];
    $this->actingAs($owner)->post('/profile/push-subscriptions', $data)->assertRedirect()->assertSessionHasNoErrors();
    $this->postJson('/profile/push-subscriptions', $data)->assertTooManyRequests();
    $this->actingAs($other)->post('/profile/push-subscriptions', array_replace($data, ['endpoint' => 'https://push.example.test/two']))
        ->assertRedirect()->assertSessionHasNoErrors();
});

test('PushSubscription: logout revokes only this consenting device and leaves other devices intact', function () {
    $keys = pushTestKeys();
    app()->instance(UrlGuard::class, new UrlGuard(fn () => ['93.184.216.34']));
    $owner = User::factory()->create();
    $data = ['endpoint' => 'https://push.example.test/one', 'keys' => $keys, 'device_name' => 'Uno'];
    $this->actingAs($owner)->post('/profile/push-subscriptions', $data)->assertRedirect();
    $current = PushSubscription::ownedBy($owner->id)->sole();
    $this->post('/profile/push-subscriptions', array_replace($data, ['endpoint' => 'https://push.example.test/two']))->assertRedirect();
    $this->withCookie('push_device', (string) $current->id)->post('/logout')->assertRedirect();
    expect(PushSubscription::ownedBy($owner->id)->pluck('endpoint')->all())->toBe(['https://push.example.test/two']);
});

test('PushDelivery: database notifications enqueue consented devices without leaking notification content into the job', function () {
    $keys = pushTestKeys();
    app()->instance(UrlGuard::class, new UrlGuard(fn () => ['93.184.216.34']));
    $owner = User::factory()->create();
    $other = User::factory()->create();
    $data = ['endpoint' => 'https://push.example.test/one', 'keys' => $keys, 'device_name' => 'Uno'];
    $this->actingAs($owner)->post('/profile/push-subscriptions', $data)->assertRedirect();
    $ownDevice = PushSubscription::ownedBy($owner->id)->sole();
    $this->actingAs($other)->post('/profile/push-subscriptions', array_replace($data, ['endpoint' => 'https://push.example.test/two']))->assertRedirect();
    Queue::fake();
    $owner->notify(new PostWorkflowActivity('approval_requested', null, null, 'Private Brand', 'Private message', 'Private post'));
    Queue::assertPushed(SendPushNotification::class, function ($job) use ($owner, $ownDevice) {
        expect($job->userId)->toBe($owner->id)->and($job->subscriptionId)->toBe($ownDevice->id);
        $serialized = serialize($job);
        expect($serialized)->not->toContain('Private message')->not->toContain('Private post')->not->toContain('push.example.test');

        return true;
    });
    Queue::assertPushed(SendPushNotification::class, 1);
    expect($owner->notifications()->count())->toBe(1);
    Http::assertNothingSent();
});

test('PushDelivery: queued delivery uses the sender and removes expired devices or retries only transient failures', function (string $outcome) {
    $keys = pushTestKeys();
    app()->instance(UrlGuard::class, new UrlGuard(fn () => ['93.184.216.34']));
    $owner = User::factory()->create();
    $subscription = app(PushSubscriptions::class)->subscribe($owner, [
        'endpoint' => 'https://push.example.test/one', 'keys' => $keys, 'device_name' => 'Uno',
    ]);
    Queue::fake();
    $owner->notify(new PostWorkflowActivity('approval_requested', null, null, 'Private Brand', 'Private message'));
    $notification = $owner->notifications()->sole();
    $sender = Mockery::mock(PushSender::class);
    $sender->shouldReceive('send')->once()->withArgs(fn ($device) => $device->id === $subscription->id)
        ->andReturn(PushResult::from($outcome));
    $job = new SendPushNotification($owner->id, $subscription->id, $notification->id);
    if ($outcome === 'retry') {
        expect(fn () => $job->handle($sender, app(PushConfiguration::class)))->toThrow(RuntimeException::class, 'Entrega push temporalmente no disponible.');
    } else {
        $job->handle($sender, app(PushConfiguration::class));
    }
    $fresh = PushSubscription::ownedBy($owner->id)->find($subscription->id);
    if ($outcome === 'expired') {
        expect($fresh)->toBeNull();
    } elseif ($outcome === 'delivered') {
        expect($fresh->last_sent_at)->not->toBeNull()->and($fresh->last_notification_id)->toBe($notification->id);
        // La redelivery del mismo job no vuelve a enviar.
        $job->handle($sender, app(PushConfiguration::class));
    } else {
        expect($fresh->last_sent_at)->toBeNull();
    }
    Http::assertNothingSent();
})->with(['delivered', 'expired', 'retry', 'rejected']);

test('PushDelivery: workers recheck consent notification ownership and verification before sending', function (string $condition) {
    $keys = pushTestKeys();
    app()->instance(UrlGuard::class, new UrlGuard(fn () => ['93.184.216.34']));
    $owner = User::factory()->create();
    $other = User::factory()->create();
    $subscription = app(PushSubscriptions::class)->subscribe($owner, [
        'endpoint' => 'https://push.example.test/one', 'keys' => $keys, 'device_name' => 'Uno',
    ]);
    Queue::fake();
    $notificationOwner = $condition === 'foreign_notification' ? $other : $owner;
    $notificationOwner->notify(new PostWorkflowActivity('approval_requested', null, null, 'Private Brand', 'Private message'));
    $notification = $notificationOwner->notifications()->sole();
    match ($condition) {
        'revoked' => $subscription->delete(),
        'unverified' => $owner->forceFill(['email_verified_at' => null])->save(),
        'disabled' => config()->set('push.enabled', false),
        'read' => $notification->markAsRead(),
        default => null,
    };
    $sender = Mockery::mock(PushSender::class);
    $sender->shouldNotReceive('send');
    (new SendPushNotification($condition === 'foreign_device' ? $other->id : $owner->id, $subscription->id, $notification->id))
        ->handle($sender, app(PushConfiguration::class));
    Http::assertNothingSent();
})->with(['revoked', 'foreign_notification', 'foreign_device', 'unverified', 'disabled', 'read']);

test('PushDelivery: native adapter encrypts a generic payload and signs VAPID using only an isolated HTTP fake', function (int $status, string $result) {
    $keys = pushTestKeys();
    app()->instance(UrlGuard::class, new UrlGuard(fn () => ['93.184.216.34']));
    $owner = User::factory()->create();
    $subscription = app(PushSubscriptions::class)->subscribe($owner, [
        'endpoint' => 'https://push.example.test/one', 'keys' => $keys, 'device_name' => 'Uno',
    ]);
    Http::fake(['https://push.example.test/*' => Http::response('Untrusted receiver text', $status)]);
    $actual = app(PushSender::class)->send($subscription);
    expect($actual->value)->toBe($result);
    Http::assertSent(function ($request) {
        expect($request->method())->toBe('POST')->and($request->header('Content-Encoding'))->toBe(['aes128gcm'])
            ->and($request->header('Authorization')[0])->toStartWith('vapid t=')
            ->and(strlen($request->body()))->toBeGreaterThan(100)
            ->and($request->body())->not->toContain('Social Scheduler')->not->toContain('Tienes nuevos avisos');

        return $request->url() === 'https://push.example.test/one';
    });
    Http::assertSentCount(1);
})->with(['accepted' => [201, 'delivered'], 'gone' => [410, 'expired'], 'missing' => [404, 'expired'], 'rate limited' => [429, 'retry'], 'bad request' => [400, 'rejected'], 'redirect' => [302, 'rejected']]);

test('PushSubscription: malformed filled VAPID settings are disabled without exposing the private key', function () {
    config()->set(['push.enabled' => true, 'push.vapid.subject' => 'invalid contact', 'push.vapid.public_key' => 'not-a-key', 'push.vapid.private_key' => 'private-secret-invalid']);
    $owner = User::factory()->create();
    $response = $this->actingAs($owner)->get('/profile/notifications')->assertOk();
    expect($response->inertiaProps('push.enabled'))->toBeFalse()
        ->and($response->inertiaProps('push.public_key'))->toBeNull()
        ->and(json_encode($response->inertiaProps()))->not->toContain('private-secret-invalid');
    Http::assertNothingSent();
});

test('PushDelivery: adapter revalidates DNS at delivery and refuses a private destination without sending', function () {
    $keys = pushTestKeys();
    $private = false;
    app()->instance(UrlGuard::class, new UrlGuard(function () use (&$private) {
        return [$private ? '127.0.0.1' : '93.184.216.34'];
    }));
    $owner = User::factory()->create();
    $subscription = app(PushSubscriptions::class)->subscribe($owner, ['endpoint' => 'https://push.example.test/one', 'keys' => $keys, 'device_name' => 'Uno']);
    $private = true;
    expect(app(PushSender::class)->send($subscription))->toBe(PushResult::Rejected);
    Http::assertNothingSent();
});

test('PushDelivery: transport pins the public IP and disables proxies redirects and response buffering', function () {
    $keys = pushTestKeys();
    app()->instance(UrlGuard::class, new UrlGuard(fn () => ['93.184.216.34']));
    $owner = User::factory()->create();
    $subscription = app(PushSubscriptions::class)->subscribe($owner, ['endpoint' => 'https://push.example.test/one', 'keys' => $keys, 'device_name' => 'Uno']);
    $observed = null;
    Http::fake(function ($request, $options) use (&$observed) {
        $observed = $options;

        return Http::response('', 302, ['Location' => 'https://127.0.0.1/private']);
    });
    expect(app(PushSender::class)->send($subscription))->toBe(PushResult::Rejected);
    expect($observed['proxy'])->toBe('')->and($observed['allow_redirects'])->toBeFalse()
        ->and($observed)->not->toHaveKey('stream')->and($observed['sink'])->toBeInstanceOf(DiscardedBody::class)
        ->and($observed['timeout'])->toBe(10)
        ->and($observed['connect_timeout'])->toBe(3)
        ->and($observed['curl'][CURLOPT_RESOLVE])->toBe(['push.example.test:443:93.184.216.34']);
    Http::assertSentCount(1);
});
