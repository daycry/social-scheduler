<?php

use App\Connectors\ConnectorRegistry;
use App\Connectors\Data\RefreshResult;
use App\Connectors\Exceptions\ConnectorException;
use App\Connectors\Exceptions\ReconnectRequiredException;
use App\Connectors\Network;
use App\Jobs\RefreshSocialAccount;
use App\Jobs\RefreshTokens;
use App\Models\Brand;
use App\Models\SocialAccount;
use App\Models\User;
use App\Notifications\ReconnectRequired;
use App\Services\SocialAccountService;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\ChannelManager;
use Illuminate\Notifications\SendQueuedNotifications;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Tests\Support\FakeConnector;

beforeEach(function () {
    Notification::fake();
    $this->user = User::factory()->create();
    $this->brand = Brand::factory()->for($this->user)->create();
    $this->fake = new FakeConnector('meta', [Network::Instagram, Network::Facebook], margin: 168);
    $this->app->instance(ConnectorRegistry::class, (new ConnectorRegistry)->register($this->fake));
});

/** Ejecuta el job de UNA cuenta (como lo haria el worker). */
function refreshAccount(SocialAccount|int $account): void
{
    (new RefreshSocialAccount($account instanceof SocialAccount ? $account->id : $account))
        ->handle(app(ConnectorRegistry::class), app(SocialAccountService::class));
}

/** Ejecuta una pasada completa: despachador + un job por cuenta (cola sincrona de los tests). */
function runRefresh(): void
{
    (new RefreshTokens)->handle(app(ConnectorRegistry::class));
}

test('RefreshTokens renews tokens inside the margin without user intervention', function () {
    $due = SocialAccount::factory()->for($this->brand)->create(['token_expires_at' => now()->addDays(3), 'access_token' => 'OLD', 'refresh_token' => 'OLD-R', 'refresh_failures' => 3]);
    $this->fake->refreshResult = new RefreshResult('NEW', 'NEW-R', now()->addDays(60), null, now()->addDays(90));

    runRefresh();

    $due->refresh();
    expect($due->access_token)->toBe('NEW')->and($due->refresh_token)->toBe('NEW-R')
        ->and($due->token_expires_at->isFuture())->toBeTrue()->and($due->data_access_expires_at->isFuture())->toBeTrue()
        ->and($due->status)->toBe('activa')->and($due->last_refreshed_at)->not->toBeNull()
        ->and($due->refresh_failures)->toBe(0);
    Notification::assertNothingSent();
});

test('RefreshTokens dispatches one job per due account and skips the rest', function () {
    Queue::fake();
    $due1 = SocialAccount::factory()->for($this->brand)->create(['token_expires_at' => now()->addDays(3)]);
    $due2 = SocialAccount::factory()->for($this->brand)->create(['token_expires_at' => now()->subHour()]);
    SocialAccount::factory()->for($this->brand)->create(['token_expires_at' => now()->addDays(40)]);
    SocialAccount::factory()->for($this->brand)->create(['token_expires_at' => null]);
    SocialAccount::factory()->for($this->brand)->create(['status' => 'desconectada', 'token_expires_at' => now()->addHour()]);
    SocialAccount::factory()->for($this->brand)->network(Network::TikTok)->create(['token_expires_at' => now()->addHour()]);

    runRefresh();

    Queue::assertPushed(RefreshSocialAccount::class, 2);
    Queue::assertPushed(RefreshSocialAccount::class, fn ($j) => $j->accountId === $due1->id && $j->queue === 'poll');
    Queue::assertPushed(RefreshSocialAccount::class, fn ($j) => $j->accountId === $due2->id);
});

test('RefreshTokens skips accounts that are not due, non expiring, disconnected or without a registered connector', function () {
    $far = SocialAccount::factory()->for($this->brand)->create(['token_expires_at' => now()->addDays(40)]);
    $never = SocialAccount::factory()->for($this->brand)->create(['token_expires_at' => null]);
    $off = SocialAccount::factory()->for($this->brand)->create(['status' => 'desconectada', 'token_expires_at' => now()->addHour()]);
    $noConnector = SocialAccount::factory()->for($this->brand)->network(Network::TikTok)->create(['token_expires_at' => now()->addHour()]);

    runRefresh();

    expect($this->fake->refreshed)->toBe([]);
    expect([$far->fresh()->status, $never->fresh()->status, $off->fresh()->status, $noConnector->fresh()->status])->toBe(['activa', 'activa', 'desconectada', 'activa']);
});

test('RefreshTokens honours a configurable margin per connector', function () {
    $account = SocialAccount::factory()->for($this->brand)->create(['token_expires_at' => now()->addDays(10)]);

    runRefresh();
    expect($this->fake->refreshed)->toBe([]);

    $wide = new FakeConnector('meta', [Network::Instagram, Network::Facebook], margin: 24 * 14);
    $this->app->instance(ConnectorRegistry::class, (new ConnectorRegistry)->register($wide));
    runRefresh();
    expect($wide->refreshed)->toBe([$account->id]);
});

test('RefreshTokens permanent failure marks the account as requiring reconnection and notifies once', function () {
    $account = SocialAccount::factory()->for($this->brand)->create(['token_expires_at' => now()->addDays(2)]);
    $this->fake->refreshError = new ReconnectRequiredException('Token revocado por el usuario');

    refreshAccount($account);
    refreshAccount($account);

    $account->refresh();
    expect($account->status)->toBe('requiere_reconexion')->and($account->last_error)->toContain('Token revocado');
    Notification::assertSentToTimes($this->user, ReconnectRequired::class, 1);
    Notification::assertSentTo($this->user, ReconnectRequired::class, function ($n, $channels) use ($account) {
        return $n->accountId === $account->id && $channels === ['database', 'mail']
            && ! str_contains(json_encode($n->toArray($this->user)), 'access-');
    });
});

test('RefreshTokens keeps the pending data of a failed account (brand and account row survive)', function () {
    $account = SocialAccount::factory()->for($this->brand)->create(['token_expires_at' => now()->addDays(2), 'access_token' => 'KEEP']);
    $this->fake->refreshError = new ReconnectRequiredException('revocado');

    refreshAccount($account);

    expect(Brand::count())->toBe(0); // sin usuario autenticado el scope oculta, pero la fila existe:
    expect(Brand::withoutUserScope()->find($this->brand->id))->not->toBeNull()
        ->and(SocialAccount::withoutUserScope()->find($account->id)->access_token)->toBe('KEEP');
});

test('RefreshTokens a transient failure NEVER marks the account, even if the access token already expired', function () {
    $expired = SocialAccount::factory()->for($this->brand)->create(['token_expires_at' => now()->subHour()]);
    $this->fake->refreshError = new ConnectorException('Google no disponible');

    foreach (range(1, 5) as $_) {
        refreshAccount($expired);
    }

    $expired->refresh();
    expect($expired->status)->toBe('activa')->and($expired->refresh_failures)->toBe(5)->and($expired->last_error)->toContain('no disponible');
    Notification::assertNothingSent();

    // La siguiente pasada se recupera sola cuando la red vuelve.
    $this->fake->refreshError = null;
    refreshAccount($expired);
    expect($expired->fresh()->status)->toBe('activa')->and($expired->fresh()->refresh_failures)->toBe(0)->and($expired->fresh()->last_error)->toBeNull();
});

test('RefreshTokens escalates transient failures only after the configured number of consecutive ones', function () {
    config(['social.max_transient_failures' => 3]);
    $account = SocialAccount::factory()->for($this->brand)->create(['token_expires_at' => now()->subHour()]);
    $this->fake->refreshError = new ConnectorException('502');

    refreshAccount($account);
    refreshAccount($account);
    expect($account->fresh()->status)->toBe('activa');
    Notification::assertNothingSent();

    refreshAccount($account);
    expect($account->fresh()->status)->toBe('requiere_reconexion');
    Notification::assertSentToTimes($this->user, ReconnectRequired::class, 1);
});

test('RefreshTokens a success between transient failures resets the consecutive counter', function () {
    config(['social.max_transient_failures' => 3]);
    $account = SocialAccount::factory()->for($this->brand)->create(['token_expires_at' => now()->addHour()]);

    $this->fake->refreshError = new ConnectorException('502');
    refreshAccount($account);
    refreshAccount($account);
    $this->fake->refreshError = null;
    $this->fake->refreshResult = new RefreshResult('NEW', null, now()->addHour());
    refreshAccount($account);
    $this->fake->refreshError = new ConnectorException('502');
    refreshAccount($account);

    expect($account->fresh()->status)->toBe('activa')->and($account->fresh()->refresh_failures)->toBe(1);
});

test('RefreshTokens marks accounts whose data access or refresh token already expired without calling the network', function () {
    $meta = SocialAccount::factory()->for($this->brand)->create(['token_expires_at' => now()->addDays(30), 'data_access_expires_at' => now()->subDay()]);
    $tiktok = SocialAccount::factory()->for($this->brand)->create(['token_expires_at' => now()->addDays(30), 'refresh_expires_at' => now()->subDay()]);

    runRefresh();

    expect($meta->fresh()->status)->toBe('requiere_reconexion')->and($tiktok->fresh()->status)->toBe('requiere_reconexion')
        ->and($this->fake->refreshed)->toBe([]);
    Notification::assertSentToTimes($this->user, ReconnectRequired::class, 2);
});

test('RefreshTokens an unexpected error on one account does not stop the others', function () {
    $a = SocialAccount::factory()->for($this->brand)->create(['token_expires_at' => now()->addDay()]);
    $b = SocialAccount::factory()->for($this->brand)->create(['token_expires_at' => now()->addDay()]);
    $this->fake->refreshError = new RuntimeException('boom');

    runRefresh();

    expect($this->fake->refreshed)->toBe([$a->id, $b->id]);
});

test('RefreshTokens aborts when the account was disconnected while refreshing and never resurrects its tokens', function () {
    $account = SocialAccount::factory()->for($this->brand)->create(['token_expires_at' => now()->addDay()]);
    $this->fake->duringRefresh = fn () => app(SocialAccountService::class)->disconnect(SocialAccount::withoutUserScope()->find($account->id));

    refreshAccount($account);

    $raw = SocialAccount::withoutUserScope()->find($account->id);
    expect($raw->status)->toBe('desconectada')->and($raw->access_token)->toBeNull()->and($raw->refresh_token)->toBeNull();
    Notification::assertNothingSent();
});

test('RefreshTokens aborts when the account was already refreshed by someone else', function () {
    $account = SocialAccount::factory()->for($this->brand)->create(['token_expires_at' => now()->addDay(), 'access_token' => 'OTHER-RUN']);
    $this->fake->duringRefresh = fn () => SocialAccount::withoutUserScope()->whereKey($account->id)->first()
        ->forceFill(['last_refreshed_at' => now()->addMinute(), 'token_expires_at' => now()->addDays(60)])->save();
    $this->fake->refreshResult = new RefreshResult('SLOW-RUN', null, now()->addDays(60));

    refreshAccount($account);

    expect(SocialAccount::withoutUserScope()->find($account->id)->access_token)->toBe('OTHER-RUN');
});

test('RefreshTokens does nothing for a missing, disconnected or already marked account', function () {
    $off = SocialAccount::factory()->for($this->brand)->create(['status' => 'desconectada', 'token_expires_at' => now()->subHour()]);
    $marked = SocialAccount::factory()->for($this->brand)->requiresReconnection()->create(['token_expires_at' => now()->subHour()]);

    refreshAccount($off);
    refreshAccount($marked);
    refreshAccount(999999);

    expect($this->fake->refreshed)->toBe([]);
    Notification::assertNothingSent();
});

test('RefreshSocialAccount is unique per account, bounded and below the worker timeout', function () {
    $job = new RefreshSocialAccount(42);
    $unit = file_get_contents(base_path('deploy/systemd/scheduler-worker@.service'));
    preg_match('/--timeout=(\d+)/', $unit, $worker);

    expect($job)->toBeInstanceOf(ShouldBeUnique::class)->toBeInstanceOf(ShouldQueue::class)
        ->and($job->uniqueId())->toBe('42')
        ->and($job->tries)->toBeLessThanOrEqual(3)
        ->and($job->timeout)->toBeLessThan((int) $worker[1])
        ->and((int) $worker[1])->toBeLessThan(config('queue.connections.database.retry_after'));
    expect(file_get_contents(base_path('deploy/systemd/README.md')))->toContain('--timeout=120');
});

test('RefreshTokens is queued on the configured queue, unique and registered in the scheduler', function () {
    Queue::fake();
    dispatch(new RefreshTokens);
    Queue::assertPushedOn('poll', RefreshTokens::class);
    expect(new RefreshTokens)->toBeInstanceOf(ShouldBeUnique::class)
        ->and(config('queue.names'))->toContain(config('social.refresh_queue'));

    Artisan::call('schedule:list');
    expect(Artisan::output())->toContain('refresh-social-tokens')->toContain('*/30');
});

test('RefreshTokens notification goes through the queue, writes the database channel first and holds no model', function () {
    $account = SocialAccount::factory()->for($this->brand)->network(Network::YouTube)->create(['name' => 'Jordi daycry', 'access_token' => 'SECRET-T']);
    $notification = new ReconnectRequired($account, 'Token revocado');
    $mail = $notification->toMail($this->user);

    expect($notification)->toBeInstanceOf(ShouldQueue::class)
        ->and($notification->via($this->user))->toBe(['database', 'mail'])
        ->and($notification->queue)->toBe('poll')
        ->and(serialize($notification))->not->toContain('SECRET-T')->not->toContain('SocialAccount');
    expect($mail->subject)->toContain('YouTube')->and($mail->actionUrl)->toContain('/brands/'.$this->brand->id.'/connections');
    expect(json_encode($mail->toArray()))->not->toContain('SECRET-T');
});

test('RefreshTokens sends one queued job per channel so a failing mail never blocks the database notice', function () {
    Bus::fake();
    Notification::swap(new ChannelManager($this->app)); // notificaciones reales
    $account = SocialAccount::factory()->for($this->brand)->create();

    $this->user->notify(new ReconnectRequired($account, 'Token revocado'));

    Bus::assertDispatchedTimes(SendQueuedNotifications::class, 2);
    Bus::assertDispatched(SendQueuedNotifications::class, fn ($j) => $j->channels === ['database']);
    Bus::assertDispatched(SendQueuedNotifications::class, fn ($j) => $j->channels === ['mail']);

    // El job de BD, ejecutado por si solo, deja el aviso aunque el de mail nunca llegue a ejecutarse.
    $this->user->notifyNow(new ReconnectRequired($account, 'Token revocado'), ['database']);
    expect($this->user->notifications()->count())->toBe(1)
        ->and($this->user->notifications()->first()->data['social_account_id'])->toBe($account->id);
});
