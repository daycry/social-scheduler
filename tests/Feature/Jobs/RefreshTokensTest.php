<?php

use App\Connectors\ConnectorRegistry;
use App\Connectors\Data\RefreshResult;
use App\Connectors\Exceptions\ConnectorException;
use App\Connectors\Exceptions\ReconnectRequiredException;
use App\Connectors\Network;
use App\Jobs\RefreshTokens;
use App\Models\Brand;
use App\Models\SocialAccount;
use App\Models\User;
use App\Notifications\ReconnectRequired;
use App\Services\SocialAccountService;
use Illuminate\Support\Facades\Artisan;
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

function runRefresh(): void
{
    (new RefreshTokens)->handle(app(ConnectorRegistry::class), app(SocialAccountService::class));
}

test('RefreshTokens renews tokens inside the margin without user intervention', function () {
    $due = SocialAccount::factory()->for($this->brand)->create(['token_expires_at' => now()->addDays(3), 'access_token' => 'OLD', 'refresh_token' => 'OLD-R']);
    $this->fake->refreshResult = new RefreshResult('NEW', 'NEW-R', now()->addDays(60), null, now()->addDays(90));

    runRefresh();

    $due->refresh();
    expect($due->access_token)->toBe('NEW')->and($due->refresh_token)->toBe('NEW-R')
        ->and($due->token_expires_at->isFuture())->toBeTrue()->and($due->data_access_expires_at->isFuture())->toBeTrue()
        ->and($due->status)->toBe('activa')->and($due->last_refreshed_at)->not->toBeNull();
    Notification::assertNothingSent();
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

test('RefreshTokens failure marks the account as requiring reconnection and notifies once', function () {
    $account = SocialAccount::factory()->for($this->brand)->create(['token_expires_at' => now()->addDays(2)]);
    $this->fake->refreshError = new ReconnectRequiredException('Token revocado por el usuario');

    runRefresh();
    runRefresh();

    $account->refresh();
    expect($account->status)->toBe('requiere_reconexion')->and($account->last_error)->toContain('Token revocado');
    Notification::assertSentToTimes($this->user, ReconnectRequired::class, 1);
    Notification::assertSentTo($this->user, ReconnectRequired::class, function ($n, $channels) use ($account) {
        return $n->account->is($account) && $channels === ['mail', 'database']
            && ! str_contains(json_encode($n->toArray($this->user)), 'access-');
    });
});

test('RefreshTokens keeps the pending data of a failed account (brand and account row survive)', function () {
    $account = SocialAccount::factory()->for($this->brand)->create(['token_expires_at' => now()->addDays(2), 'access_token' => 'KEEP']);
    $this->fake->refreshError = new ReconnectRequiredException('revocado');

    runRefresh();

    expect(Brand::count())->toBe(0); // sin usuario autenticado el scope oculta, pero la fila existe:
    expect(Brand::withoutUserScope()->find($this->brand->id))->not->toBeNull()
        ->and(SocialAccount::withoutUserScope()->find($account->id)->access_token)->toBe('KEEP');
});

test('RefreshTokens treats a transient failure as retry later and only escalates when the token already expired', function () {
    $soon = SocialAccount::factory()->for($this->brand)->create(['token_expires_at' => now()->addDays(2)]);
    $expired = SocialAccount::factory()->for($this->brand)->create(['token_expires_at' => now()->subHour()]);
    $this->fake->refreshError = new ConnectorException('Meta no disponible');

    runRefresh();

    expect($soon->fresh()->status)->toBe('activa')->and($expired->fresh()->status)->toBe('requiere_reconexion');
    Notification::assertSentToTimes($this->user, ReconnectRequired::class, 1);
});

test('RefreshTokens marks Meta accounts whose 90 day data access expired without calling the network', function () {
    $account = SocialAccount::factory()->for($this->brand)->create(['token_expires_at' => now()->addDays(30), 'data_access_expires_at' => now()->subDay()]);

    runRefresh();

    expect($account->fresh()->status)->toBe('requiere_reconexion')->and($this->fake->refreshed)->toBe([]);
    Notification::assertSentTo($this->user, ReconnectRequired::class);
});

test('RefreshTokens an unexpected error on one account does not stop the others', function () {
    $a = SocialAccount::factory()->for($this->brand)->create(['token_expires_at' => now()->addDay()]);
    $b = SocialAccount::factory()->for($this->brand)->create(['token_expires_at' => now()->addDay()]);
    $this->fake->refreshError = new RuntimeException('boom');

    runRefresh();

    expect($this->fake->refreshed)->toBe([$a->id, $b->id]);
});

test('RefreshTokens is queued on the configured queue and registered in the scheduler', function () {
    Queue::fake();
    dispatch(new RefreshTokens);
    Queue::assertPushedOn('poll', RefreshTokens::class);
    expect(config('queue.names'))->toContain(config('social.refresh_queue'));

    Artisan::call('schedule:list');
    expect(Artisan::output())->toContain('refresh-social-tokens')->toContain('*/30');
});

test('RefreshTokens notification renders a mail that links to the brand connections and no secrets', function () {
    $account = SocialAccount::factory()->for($this->brand)->network(Network::YouTube)->create(['name' => 'Jordi daycry', 'access_token' => 'SECRET-T']);
    $mail = (new ReconnectRequired($account, 'Token revocado'))->toMail($this->user);

    expect($mail->subject)->toContain('YouTube')->and($mail->actionUrl)->toContain('/brands/'.$this->brand->id.'/connections');
    expect(json_encode($mail->toArray()))->not->toContain('SECRET-T');
});
