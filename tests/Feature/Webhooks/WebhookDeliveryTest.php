<?php

use App\Enums\PostStatus;
use App\Events\OutboundDomainEvent;
use App\Jobs\DeliverWebhook;
use App\Models\Brand;
use App\Models\Post;
use App\Models\PostTarget;
use App\Models\SocialAccount;
use App\Models\User;
use App\Models\Webhook;
use App\Models\WebhookDelivery;
use App\Models\WebhookEvent;
use App\Services\Media\DiscardedBody;
use App\Services\Media\UrlGuard;
use App\Services\Posts\PostTransitions;
use App\Services\Webhooks\WebhookSubscriptions;
use GuzzleHttp\Exception\TransferException;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Encryption\Encrypter;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Queue\QueueManager;
use Illuminate\Queue\Worker;
use Illuminate\Queue\WorkerOptions;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Testing\Fakes\QueueFake;
use Illuminate\Validation\ValidationException;

beforeEach(function () {
    Carbon::setTestNow('2026-11-18 08:00:00 UTC');
    Http::preventStrayRequests();
    Queue::fake();
    app()->instance(UrlGuard::class, new UrlGuard(fn () => ['93.184.216.34']));
});

afterEach(fn () => Carbon::setTestNow());

test('WebhookDelivery: DNS runs before the delivery transaction and a pause during resolution cancels sending', function () {
    [, $hook, $delivery, $job] = preparedWebhookDelivery();
    $level = DB::transactionLevel();
    app()->instance(UrlGuard::class, new UrlGuard(function () use ($level, $hook) {
        expect(DB::transactionLevel())->toBe($level);
        $hook->forceFill(['enabled' => false])->save();

        return ['93.184.216.34'];
    }));
    app()->call([$job, 'handle']);
    expect($delivery->fresh()->status)->toBe('cancelled');
    Http::assertNothingSent();
});

test('WebhookDelivery: a worker infrastructure exception does not consume the five delivery attempts', function () {
    [, , $delivery] = preparedWebhookDelivery();
    [$manager, $worker] = nativeWebhookWorker();
    $calls = 0;
    Http::fake(function () use (&$calls) {
        if (++$calls === 1) {
            throw new RuntimeException('Transient executor failure, not an HTTP result.');
        }

        return Http::response('', 503);
    });
    $manager->connection('database')->push(new DeliverWebhook($delivery->id), queue: 'poll');
    for ($i = 0; $i < 7 && $delivery->fresh()->status === 'pending'; $i++) {
        $worker->runNextJob('database', 'poll', new WorkerOptions(sleep: 0, maxTries: 1));
        $available = DB::table('jobs')->where('queue', 'poll')->min('available_at');
        if ($available !== null) {
            Carbon::setTestNow(Carbon::createFromTimestamp($available));
        }
    }
    expect($calls)->toBe(6)->and($delivery->fresh()->attempts)->toBe(5)
        ->and($delivery->fresh()->status)->toBe('exhausted')->and($delivery->fresh()->last_error)->toBe('http_error');
});

test('WebhookDelivery: Guzzle transfer errors remain recorded transient delivery attempts', function () {
    [, , $delivery, $job] = preparedWebhookDelivery();
    Http::fake(fn () => throw new TransferException(
        'TLS detail must remain private.', new GuzzleHttp\Psr7\Request('POST', 'https://hooks.example.test/receiver'),
    ));
    $job->withFakeQueueInteractions();
    app()->call([$job, 'handle']);
    $job->assertReleased(60);
    expect($delivery->fresh()->attempts)->toBe(1)->and($delivery->fresh()->last_error)->toBe('connection_error')
        ->and($delivery->fresh()->toJson())->not->toContain('TLS detail');
});

test('WebhookDelivery: a queued delivery survives an outage longer than three hours before its first attempt', function () {
    [, , $delivery] = preparedWebhookDelivery();
    [$manager, $worker] = nativeWebhookWorker();
    Http::fake(['https://hooks.example.test/*' => Http::response('', 204)]);
    $manager->connection('database')->push(new DeliverWebhook($delivery->id), queue: 'poll');
    Carbon::setTestNow(now()->addHours(4));
    $worker->runNextJob('database', 'poll', new WorkerOptions(sleep: 0, maxTries: 1));
    expect($delivery->fresh()->status)->toBe('delivered')->and($delivery->fresh()->attempts)->toBe(1);
    Http::assertSentCount(1);
});

test('WebhookDelivery: three executor exceptions stop a poison delivery independently of HTTP retries', function () {
    [, , $delivery] = preparedWebhookDelivery();
    [$manager, $worker] = nativeWebhookWorker();
    Http::fake(fn () => throw new RuntimeException('Executor failure.'));
    $manager->connection('database')->push(new DeliverWebhook($delivery->id), queue: 'poll');
    for ($i = 0; $i < 3; $i++) {
        $worker->runNextJob('database', 'poll', new WorkerOptions(sleep: 0, maxTries: 1));
        $available = DB::table('jobs')->where('queue', 'poll')->min('available_at');
        if ($available !== null) {
            Carbon::setTestNow(Carbon::createFromTimestamp($available));
        }
    }
    expect($delivery->fresh()->status)->toBe('exhausted')->and($delivery->fresh()->last_error)->toBe('worker_failed')
        ->and($delivery->fresh()->attempts)->toBe(0)->and(DB::table('jobs')->where('queue', 'poll')->count())->toBe(0);
});

test('WebhookDelivery: native worker preflight counts a third unfinished processing marker as executor failure', function () {
    [, , $delivery] = preparedWebhookDelivery();
    [$manager, $worker] = nativeWebhookWorker();
    $cache = Cache::store('array');
    Http::fake(['https://hooks.example.test/*' => Http::response('', 204)]);
    $manager->connection('database')->push(new DeliverWebhook($delivery->id), queue: 'poll');
    $payload = json_decode(DB::table('jobs')->where('queue', 'poll')->value('payload'), true, flags: JSON_THROW_ON_ERROR);
    // Reproduce las marcas de dos fallos previos y una ejecución que no terminó; no mata un proceso OS.
    $cache->put('job-exceptions:'.$payload['uuid'], 2, now()->addDay());
    $cache->put('job-processing:'.$payload['uuid'], true, now()->addDay());
    $worker->runNextJob('database', 'poll', new WorkerOptions(sleep: 0, maxTries: 1));
    expect($delivery->fresh()->status)->toBe('exhausted')->and($delivery->fresh()->last_error)->toBe('worker_failed')
        ->and($delivery->fresh()->attempts)->toBe(0);
    Http::assertNothingSent();
});

test('WebhookDelivery: an outbox write failure rolls back the terminal publication transition', function () {
    $owner = User::factory()->create();
    $brand = Brand::factory()->for($owner)->create();
    app(WebhookSubscriptions::class)->create($owner, $brand, [
        'name' => 'Atomic publication', 'url' => 'https://hooks.example.test/receiver',
        'events' => ['post.published'], 'secret' => str_repeat('a', 64),
    ]);
    $post = Post::factory()->for($brand)->create();
    $target = PostTarget::factory()->for($post)->status(PostStatus::Publishing)->create();
    WebhookEvent::creating(fn () => throw new RuntimeException('Outbox unavailable.'));
    try {
        expect(fn () => app(PostTransitions::class)->transition($target, PostStatus::Published))
            ->toThrow(RuntimeException::class, 'Outbox unavailable.');
        expect($target->fresh()->status)->toBe(PostStatus::Publishing)
            ->and(WebhookEvent::withoutUserScope()->count())->toBe(0)
            ->and(WebhookDelivery::withoutUserScope()->count())->toBe(0);
        Queue::assertNothingPushed();
    } finally {
        WebhookEvent::flushEventListeners();
    }
});

test('WebhookDelivery: a real publication transition queues one owner delivery for a matching subscription', function () {
    $owner = User::factory()->create();
    $brand = Brand::factory()->for($owner)->create();
    $this->actingAs($owner)->post('/brands/'.$brand->id.'/webhooks', [
        'name' => 'Publicadas', 'url' => 'https://hooks.example.test/receiver',
        'events' => ['post.published'], 'secret' => str_repeat('a', 64),
    ])->assertRedirect()->assertSessionHasNoErrors();
    $post = Post::factory()->for($brand)->create();
    $target = PostTarget::factory()->for($post)->status(PostStatus::Publishing)->create();
    app(PostTransitions::class)->transition($target, PostStatus::Published, ['external_id' => 'published-test']);
    Queue::assertPushed(DeliverWebhook::class, 1);
    Http::assertNothingSent();
});

test('WebhookDelivery: an owner manages subscriptions without exposing the encrypted secret or accepting a client owner', function () {
    $owner = User::factory()->create();
    $other = User::factory()->create();
    $brand = Brand::factory()->for($owner)->create();
    $data = ['name' => 'Publicaciones', 'url' => 'https://hooks.example.test/receiver',
        'events' => ['post.published'], 'secret' => str_repeat('a', 64), 'user_id' => $other->id];
    $this->actingAs($owner)->post('/brands/'.$brand->id.'/webhooks', $data)
        ->assertRedirect()->assertSessionHasNoErrors();
    $hook = Webhook::ownedBy($owner->id)->sole();
    expect($hook->user_id)->toBe($owner->id)->and($hook->brand_id)->toBe($brand->id)
        ->and($hook->secret)->toBe($data['secret'])->and($hook->getRawOriginal('secret'))->not->toBe($data['secret']);
    $response = $this->get('/webhooks?brand_id='.$brand->id)->assertOk();
    expect($response->getContent())->not->toContain($data['secret'])->not->toContain($hook->getRawOriginal('secret'));
    $this->put('/webhooks/'.$hook->id, ['name' => 'Pausado', 'events' => ['post.failed'], 'enabled' => false])
        ->assertRedirect()->assertSessionHasNoErrors();
    expect($hook->fresh()->enabled)->toBeFalse()->and($hook->fresh()->events)->toBe(['post.failed']);
    $this->actingAs($other)->delete('/webhooks/'.$hook->id)->assertNotFound();
    $this->actingAs($owner)->delete('/webhooks/'.$hook->id)->assertRedirect();
    expect(Webhook::withoutUserScope()->count())->toBe(0);
    Http::assertNothingSent();
    Queue::assertNothingPushed();
});

/** @return array{QueueManager, Worker} Cola database y Worker reales, sin daemon ni señal de proceso. */
function nativeWebhookWorker(): array
{
    $manager = Queue::getFacadeRoot()->queue;
    expect($manager)->toBeInstanceOf(QueueManager::class);
    Queue::swap($manager);
    $worker = new Worker($manager, app('events'), app(ExceptionHandler::class), fn () => false);
    $worker->setCache(Cache::store('array'));

    return [$manager, $worker];
}

function preparedWebhookDelivery(): array
{
    $owner = User::factory()->create();
    $brand = Brand::factory()->for($owner)->create();
    $hook = app(WebhookSubscriptions::class)->create($owner, $brand, [
        'name' => 'Firmado', 'url' => 'https://hooks.example.test/receiver',
        'events' => ['post.published'], 'secret' => str_repeat('a', 64),
    ]);
    $post = Post::factory()->for($brand)->create();
    $target = PostTarget::factory()->for($post)->status(PostStatus::Publishing)->create(['progress' => ['upload_url' => 'PROVIDER-SECRET']]);
    app(PostTransitions::class)->transition($target, PostStatus::Published, ['external_id' => 'published-test']);

    return [$owner, $hook, WebhookDelivery::ownedBy($owner->id)->sole(), Queue::pushed(DeliverWebhook::class)->sole()];
}

test('WebhookDelivery: exact stored bytes carry a verifiable timestamped HMAC and a repeated job does not resend success', function () {
    [$owner, $hook, $delivery, $job] = preparedWebhookDelivery();
    Http::fake(function () use ($delivery) {
        expect($delivery->fresh()->attempts)->toBe(1);

        return Http::response('', 204);
    });
    app()->call([$job, 'handle']);
    app()->call([$job, 'handle']);
    Http::assertSentCount(1);
    Http::assertSent(function (Request $request) use ($hook) {
        $body = $request->body();
        $data = json_decode($body, true, flags: JSON_THROW_ON_ERROR);
        $timestamp = (string) now()->timestamp;
        $signature = 't='.$timestamp.',v1='.hash_hmac('sha256', $timestamp.'.'.$body, $hook->secret);

        return $request->method() === 'POST' && $request->url() === $hook->url
            && $request->hasHeader('X-SocialScheduler-Signature', $signature)
            && $request->hasHeader('X-SocialScheduler-Event', 'post.published')
            && $request->hasHeader('X-SocialScheduler-Event-Id', $data['id'])
            && $data['event'] === 'post.published' && isset($data['occurred_at'])
            && ! str_contains($body, 'PROVIDER-SECRET') && ! str_contains($body, $hook->secret);
    });
    expect($delivery->fresh()->status)->toBe('delivered')->and($delivery->fresh()->attempts)->toBe(1)
        ->and($delivery->fresh()->last_http_status)->toBe(204)->and($delivery->fresh()->delivered_at)->not->toBeNull();
    $response = $this->actingAs($owner)->get('/webhooks?brand_id='.$hook->brand_id)->assertOk();
    expect($response->inertiaProps('webhooks.0.last_delivery.status'))->toBe('delivered')
        ->and($response->inertiaProps('webhooks.0.last_delivery.attempts'))->toBe(1);
});

test('WebhookDelivery: transient responses retain five attempts with increasing backoff and stop after exhaustion', function () {
    [, , $delivery] = preparedWebhookDelivery();
    Http::fake(['https://hooks.example.test/*' => Http::response('REMOTE-PRIVATE-BODY', 503)]);
    foreach ([60, 300, 900, 3600, null] as $index => $delay) {
        $job = (new DeliverWebhook($delivery->id))->withFakeQueueInteractions();
        app()->call([$job, 'handle']);
        $fresh = $delivery->fresh();
        expect($fresh->attempts)->toBe($index + 1)->and($fresh->last_http_status)->toBe(503)
            ->and($fresh->last_error)->toBe('http_error');
        if ($delay !== null) {
            $job->assertReleased($delay);
            expect($fresh->status)->toBe('pending')->and($fresh->next_attempt_at->equalTo(now()->addSeconds($delay)))->toBeTrue();
            Carbon::setTestNow(now()->addSeconds($delay));
        } else {
            $job->assertNotReleased();
            expect($fresh->status)->toBe('exhausted')->and($fresh->next_attempt_at)->toBeNull()
                ->and($fresh->finished_at)->not->toBeNull();
        }
    }
    app()->call([new DeliverWebhook($delivery->id), 'handle']);
    Http::assertSentCount(5);
    expect($delivery->fresh()->toJson())->not->toContain('REMOTE-PRIVATE-BODY');
});

test('WebhookDelivery: a redirect is a terminal blocked attempt and the guarded request pins DNS without a proxy', function () {
    [, , $delivery, $job] = preparedWebhookDelivery();
    $options = null;
    Http::fake(function (Request $request, array $requestOptions) use (&$options) {
        $options = $requestOptions;

        return Http::response('', 302, ['Location' => 'http://127.0.0.1/private']);
    });
    app()->call([$job, 'handle']);
    Http::assertSentCount(1);
    expect($options['allow_redirects'])->toBeFalse()->and($options['proxy'])->toBe('')
        ->and($options['curl'][CURLOPT_RESOLVE])->toBe(['hooks.example.test:443:93.184.216.34'])
        ->and($options)->not->toHaveKey('stream')->and($options['sink'])->toBeInstanceOf(DiscardedBody::class)
        ->and($delivery->fresh()->status)->toBe('blocked')->and($delivery->fresh()->last_error)->toBe('redirect')
        ->and($delivery->fresh()->attempts)->toBe(1)->and($delivery->fresh()->next_attempt_at)->toBeNull();
});

test('WebhookDelivery: retryable client responses retain a recorded attempt and backoff', function (int $status) {
    [, , $delivery, $job] = preparedWebhookDelivery();
    Http::fake(['https://hooks.example.test/*' => Http::response('', $status)]);
    $job->withFakeQueueInteractions();
    app()->call([$job, 'handle']);
    $job->assertReleased(60);
    expect($delivery->fresh()->status)->toBe('pending')->and($delivery->fresh()->attempts)->toBe(1)
        ->and($delivery->fresh()->last_http_status)->toBe($status);
})->with([408, 425, 429]);

test('WebhookDelivery: an undecryptable signing key is blocked without an HTTP request', function () {
    [, $hook, $delivery, $job] = preparedWebhookDelivery();
    DB::table('webhooks')->where('id', $hook->id)->update(['secret' => 'invalid-ciphertext-fixture']);
    app()->call([$job, 'handle']);
    expect($delivery->fresh()->status)->toBe('blocked')->and($delivery->fresh()->last_error)->toBe('invalid_secret')
        ->and($delivery->fresh()->attempts)->toBe(1)->and($delivery->fresh()->last_http_status)->toBeNull();
    Http::assertNothingSent();
});

test('WebhookDelivery: DNS is revalidated at delivery and a changed private address receives no request', function () {
    [, , $delivery, $job] = preparedWebhookDelivery();
    app()->instance(UrlGuard::class, new UrlGuard(fn () => ['10.0.0.2']));
    app()->call([$job, 'handle']);
    expect($delivery->fresh()->status)->toBe('blocked')->and($delivery->fresh()->last_error)->toBe('unsafe_url')
        ->and($delivery->fresh()->attempts)->toBe(1);
    Http::assertNothingSent();
});

test('WebhookDelivery: a rejected web form never flashes its signing secret into session input', function () {
    $owner = User::factory()->create();
    $brand = Brand::factory()->for($owner)->create();
    $this->actingAs($owner)->from('/webhooks')->post('/brands/'.$brand->id.'/webhooks', [
        'name' => 'Rechazado', 'url' => 'https://127.0.0.1/private',
        'events' => ['post.published'], 'secret' => str_repeat('b', 64),
    ])->assertRedirect('/webhooks')->assertSessionHasErrors('url')->assertSessionMissing('_old_input.secret');
});

test('WebhookDelivery: a failed owned post with a deleted account still emits its terminal event', function () {
    $owner = User::factory()->create();
    $brand = Brand::factory()->for($owner)->create();
    app(WebhookSubscriptions::class)->create($owner, $brand, [
        'name' => 'Fallos', 'url' => 'https://hooks.example.test/receiver',
        'events' => ['post.failed'], 'secret' => str_repeat('a', 64),
    ]);
    $post = Post::factory()->for($brand)->create();
    $target = PostTarget::factory()->for($post)->status(PostStatus::Publishing)->create();
    SocialAccount::ownedBy($owner->id)->findOrFail($target->social_account_id)->delete();
    app(PostTransitions::class)->transition($target->fresh(), PostStatus::Failed);
    Queue::assertPushed(DeliverWebhook::class, 1);
});

test('WebhookDelivery: a queue outage keeps the committed event and the scheduled sweep recovers its delivery', function () {
    $owner = User::factory()->create();
    $brand = Brand::factory()->for($owner)->create();
    app(WebhookSubscriptions::class)->create($owner, $brand, [
        'name' => 'Recuperación', 'url' => 'https://hooks.example.test/receiver',
        'events' => ['post.published'], 'secret' => str_repeat('a', 64),
    ]);
    $fake = new class(app()) extends QueueFake
    {
        public bool $available = false;

        public function push($job, $data = '', $queue = null)
        {
            if (! $this->available) {
                throw new RuntimeException('test queue unavailable');
            }

            return parent::push($job, $data, $queue);
        }
    };
    Queue::swap($fake);
    Event::dispatch(new OutboundDomainEvent($owner->id, $brand->id, 'post.published', ['target_id' => 123]));
    expect(WebhookDelivery::ownedBy($owner->id)->sole()->status)->toBe('pending');
    Queue::assertNothingPushed();
    $fake->available = true;
    $event = collect(app(Schedule::class)->events())
        ->first(fn ($event) => $event->description === 'dispatch-pending-webhooks');
    expect($event)->not->toBeNull();
    $event->run(app());
    $repair = Queue::pushed('App\Jobs\DispatchPendingWebhooks')->sole();
    app()->call([$repair, 'handle']);
    Queue::assertPushed(DeliverWebhook::class, 1);
    Http::assertNothingSent();
});

test('WebhookDelivery: a per-owner subscription cap bounds fanout across all owned brands', function () {
    config()->set('webhooks.max_per_user', 1);
    $owner = User::factory()->create();
    $first = Brand::factory()->for($owner)->create();
    $second = Brand::factory()->for($owner)->create();
    $service = app(WebhookSubscriptions::class);
    $data = ['name' => 'Límite', 'url' => 'https://hooks.example.test/receiver', 'events' => ['post.published'], 'secret' => str_repeat('a', 64)];
    $service->create($owner, $first, $data);
    expect(fn () => $service->create($owner, $second, $data))->toThrow(ValidationException::class);
    expect(Webhook::ownedBy($owner->id)->count())->toBe(1);
});

test('WebhookDelivery: settings throttling is per owner and cannot consume another owners budget', function () {
    config()->set('webhooks.settings_per_minute', 1);
    $owner = User::factory()->create();
    $other = User::factory()->create();
    $brand = Brand::factory()->for($owner)->create();
    $otherBrand = Brand::factory()->for($other)->create();
    $data = ['name' => 'Ritmo', 'url' => 'https://hooks.example.test/receiver', 'events' => ['post.published'], 'secret' => str_repeat('a', 64)];
    $this->actingAs($owner)->post('/brands/'.$brand->id.'/webhooks', $data)->assertRedirect()->assertSessionHasNoErrors();
    $this->postJson('/brands/'.$brand->id.'/webhooks', $data)->assertTooManyRequests();
    $this->actingAs($other)->post('/brands/'.$otherBrand->id.'/webhooks', $data)->assertRedirect()->assertSessionHasNoErrors();
    expect(Webhook::withoutUserScope()->count())->toBe(2);
});

test('WebhookDelivery: an early duplicate job preserves the retry time without consuming another attempt', function () {
    [, , $delivery, $job] = preparedWebhookDelivery();
    Http::fake(['https://hooks.example.test/*' => Http::response('', 503)]);
    app()->call([$job->withFakeQueueInteractions(), 'handle']);
    $early = (new DeliverWebhook($delivery->id))->withFakeQueueInteractions();
    app()->call([$early, 'handle']);
    $early->assertReleased(60);
    Http::assertSentCount(1);
    expect($delivery->fresh()->attempts)->toBe(1);
});

test('WebhookDelivery: disabling or unsubscribing cancels pending work before HTTP', function (array $changes) {
    [, $hook, $delivery, $job] = preparedWebhookDelivery();
    $hook->forceFill($changes)->save();
    app()->call([$job, 'handle']);
    Http::assertNothingSent();
    expect($delivery->fresh()->status)->toBe('cancelled')->and($delivery->fresh()->attempts)->toBe(0);
})->with(['paused' => [['enabled' => false]], 'unsubscribed' => [['events' => ['post.failed']]]]);

test('WebhookDelivery: connection failures retry without persisting private exception text', function () {
    [, , $delivery, $job] = preparedWebhookDelivery();
    Http::fake(fn () => throw new ConnectionException('PRIVATE-REMOTE-URL-WITH-SECRET'));
    app()->call([$job->withFakeQueueInteractions(), 'handle']);
    $job->assertReleased(60);
    expect($delivery->fresh()->last_error)->toBe('connection_error')
        ->and($delivery->fresh()->last_http_status)->toBeNull()
        ->and($delivery->fresh()->toJson())->not->toContain('PRIVATE-REMOTE-URL-WITH-SECRET');
});

test('WebhookDelivery: a permanent HTTP refusal stops and a failed worker callback cannot overwrite success', function () {
    [, , $delivery, $job] = preparedWebhookDelivery();
    Http::fake(['https://hooks.example.test/*' => Http::response('', 401)]);
    app()->call([$job->withFakeQueueInteractions(), 'handle']);
    $job->assertNotReleased();
    expect($delivery->fresh()->status)->toBe('failed')->and($delivery->fresh()->attempts)->toBe(1);
    $delivery->forceFill(['status' => 'delivered', 'delivered_at' => now()])->save();
    $job->failed(new RuntimeException('PRIVATE-WORKER-TEXT'));
    expect($delivery->fresh()->status)->toBe('delivered')->and($delivery->fresh()->toJson())->not->toContain('PRIVATE-WORKER-TEXT');
});

test('WebhookDelivery: replaying one internal event is idempotent and a foreign brand event cannot fan out', function () {
    [$owner, $hook] = preparedWebhookDelivery();
    $event = new OutboundDomainEvent($owner->id, $hook->brand_id, 'post.published', ['target_id' => 99]);
    Event::dispatch($event);
    Event::dispatch($event);
    $other = User::factory()->create();
    Event::dispatch(new OutboundDomainEvent($other->id, $hook->brand_id, 'post.published', ['target_id' => 99]));
    expect(WebhookDelivery::ownedBy($owner->id)->count())->toBe(2)
        ->and(WebhookEvent::ownedBy($owner->id)->count())->toBe(2)
        ->and(WebhookDelivery::ownedBy($other->id)->count())->toBe(0);
    Queue::assertPushed(DeliverWebhook::class, 2);
});

test('WebhookDelivery: application key rotation covers the signing secret and is idempotent', function () {
    $old = new Encrypter(str_repeat('o', 32), config('app.cipher'));
    $current = new Encrypter(str_repeat('n', 32), config('app.cipher'));
    Crypt::swap($current->previousKeys([$old->getKey()]));
    $owner = User::factory()->create();
    $brand = Brand::factory()->for($owner)->create();
    $hook = app(WebhookSubscriptions::class)->create($owner, $brand, [
        'name' => 'Rotación', 'url' => 'https://hooks.example.test/receiver',
        'events' => ['post.published'], 'secret' => str_repeat('a', 64),
    ]);
    $previous = $old->encryptString(str_repeat('a', 64));
    DB::table('webhooks')->where('id', $hook->id)->update(['secret' => $previous]);
    $this->artisan('app:reencrypt --dry-run')->expectsOutput('Registros que se recifrarían: 1')->assertSuccessful();
    expect($hook->fresh()->getRawOriginal('secret'))->toBe($previous);
    $this->artisan('app:reencrypt')->expectsOutput('Registros recifrados: 1')->assertSuccessful();
    expect($current->decryptString($hook->fresh()->getRawOriginal('secret')))->toBe(str_repeat('a', 64));
    $ciphertext = $hook->fresh()->getRawOriginal('secret');
    $this->artisan('app:reencrypt')->expectsOutput('Registros recifrados: 0')->assertSuccessful();
    expect($hook->fresh()->getRawOriginal('secret'))->toBe($ciphertext);
});
