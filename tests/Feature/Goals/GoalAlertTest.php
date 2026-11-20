<?php

use App\Analytics\Metric;
use App\Connectors\Network;
use App\Jobs\EvaluateGoals;
use App\Models\AccountSnapshot;
use App\Models\Brand;
use App\Models\GoalAlertEvent;
use App\Models\SocialAccount;
use App\Models\User;
use App\Notifications\GoalAlert;
use App\Services\GoalService;
use Illuminate\Notifications\SendQueuedNotifications;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;

beforeEach(function () {
    Carbon::setTestNow('2026-11-18 08:00:00 UTC');
    Http::preventStrayRequests();
    Mail::fake();
    Queue::fake();
});

afterEach(fn () => Carbon::setTestNow());

function goalAlertFixture(string $kind = 'milestone', string $metric = 'followers', float $threshold = 1000): array
{
    $owner = User::factory()->create(['notify_by_mail' => false]);
    $brand = Brand::factory()->for($owner)->create(['timezone' => 'UTC']);
    $account = SocialAccount::factory()->for($owner)->for($brand)->create(['network' => Network::Instagram]);
    $goal = app(GoalService::class)->create($owner, $brand, [
        'name' => 'Objetivo de prueba', 'kind' => $kind, 'metric' => $metric,
        'threshold' => $threshold, 'social_account_id' => $account->id,
    ]);

    return [$owner, $brand, $account, $goal];
}

function goalSnapshot(User $owner, Brand $brand, SocialAccount $account, string $date, array $metrics): AccountSnapshot
{
    return AccountSnapshot::withoutUserScope()->create([
        'user_id' => $owner->id, 'brand_id' => $brand->id, 'social_account_id' => $account->id,
        'network' => $account->network, 'date' => $date,
        'metrics' => array_replace(Metric::blankAccount(), $metrics), 'collected_at' => now(),
    ]);
}

test('GoalAlert: a milestone creates one durable owner notification and repeated evaluation cannot duplicate it', function () {
    [$owner, $brand, $account, $goal] = goalAlertFixture();
    goalSnapshot($owner, $brand, $account, '2026-11-17', [Metric::FOLLOWERS => 1200]);
    Auth::logout();
    $service = app(GoalService::class);
    $first = $service->evaluate($owner, $goal);
    $second = $service->evaluate($owner, $goal);
    expect($first)->not->toBeNull()->and($second)->toBeNull()
        ->and($owner->notifications()->count())->toBe(1)
        ->and($owner->notifications()->sole()->data['type'])->toBe('goal_alert')
        ->and($owner->notifications()->sole()->data['goal_id'])->toBe($goal->id)
        ->and($goal->fresh()->last_value)->toBe(1200.0)
        ->and($goal->fresh()->last_period)->toBe('2026-11-17');
    Queue::assertNothingPushed();
    Mail::assertNothingSent();
    Http::assertNothingSent();
});

test('GoalAlert: a fall strictly above the threshold creates only one alert for that metric period', function () {
    [$owner, $brand, $account, $goal] = goalAlertFixture('drop', 'followers', 25);
    goalSnapshot($owner, $brand, $account, '2026-11-16', [Metric::FOLLOWERS => 1000]);
    goalSnapshot($owner, $brand, $account, '2026-11-17', [Metric::FOLLOWERS => 700]);
    $service = app(GoalService::class);
    expect($service->evaluate($owner, $goal))->not->toBeNull()
        ->and($service->evaluate($owner, $goal))->toBeNull()
        ->and($owner->notifications()->count())->toBe(1);
});

test('GoalAlert: missing current data is unknown rather than a total loss of followers', function () {
    [$owner, $brand, $account, $goal] = goalAlertFixture('drop', 'followers', 25);
    goalSnapshot($owner, $brand, $account, '2026-11-16', [Metric::FOLLOWERS => 1000]);
    goalSnapshot($owner, $brand, $account, '2026-11-17', [Metric::FOLLOWERS => null]);
    expect(app(GoalService::class)->evaluate($owner, $goal))->toBeNull()
        ->and($goal->fresh()->last_value)->toBeNull()
        ->and($owner->notifications()->count())->toBe(0);
    Queue::assertNothingPushed();
    Http::assertNothingSent();
});

test('GoalAlert: equality does not count as a fall above the threshold and a zero baseline is undefined', function (int $before, int $after) {
    [$owner, $brand, $account, $goal] = goalAlertFixture('drop', 'followers', 25);
    goalSnapshot($owner, $brand, $account, '2026-11-16', [Metric::FOLLOWERS => $before]);
    goalSnapshot($owner, $brand, $account, '2026-11-17', [Metric::FOLLOWERS => $after]);
    expect(app(GoalService::class)->evaluate($owner, $goal))->toBeNull()
        ->and($owner->notifications()->count())->toBe(0);
})->with(['equal threshold' => [1000, 750], 'zero baseline' => [0, 0]]);

test('GoalAlert: a genuine zero current value is a complete loss and can alert', function () {
    [$owner, $brand, $account, $goal] = goalAlertFixture('drop', 'followers', 25);
    goalSnapshot($owner, $brand, $account, '2026-11-16', [Metric::FOLLOWERS => 1000]);
    goalSnapshot($owner, $brand, $account, '2026-11-17', [Metric::FOLLOWERS => 0]);
    expect(app(GoalService::class)->evaluate($owner, $goal))->not->toBeNull()
        ->and($owner->notifications()->count())->toBe(1);
});

test('GoalAlert: the same milestone is not repeated on the next day while a later fall is a distinct period', function () {
    [$owner, $brand, $account, $milestone] = goalAlertFixture();
    $drop = app(GoalService::class)->create($owner, $brand, [
        'name' => 'Caída diaria', 'kind' => 'drop', 'metric' => 'followers', 'threshold' => 25,
        'social_account_id' => $account->id,
    ]);
    goalSnapshot($owner, $brand, $account, '2026-11-16', [Metric::FOLLOWERS => 2000]);
    goalSnapshot($owner, $brand, $account, '2026-11-17', [Metric::FOLLOWERS => 1400]);
    $service = app(GoalService::class);
    expect($service->evaluate($owner, $milestone))->not->toBeNull()
        ->and($service->evaluate($owner, $drop))->not->toBeNull();
    Carbon::setTestNow('2026-11-19 08:00:00 UTC');
    goalSnapshot($owner, $brand, $account, '2026-11-18', [Metric::FOLLOWERS => 1000]);
    expect($service->evaluate($owner, $milestone))->toBeNull()
        ->and($service->evaluate($owner, $drop))->not->toBeNull()
        ->and($owner->notifications()->count())->toBe(3);
});

test('GoalAlert: a brand aggregate stays unknown when any selected account is missing data', function () {
    [$owner, $brand, $account, $goal] = goalAlertFixture('drop', 'followers', 25);
    $goal->forceFill(['social_account_id' => null])->save();
    $second = SocialAccount::factory()->for($owner)->for($brand)->create(['network' => Network::Facebook]);
    foreach ([$account, $second] as $a) {
        goalSnapshot($owner, $brand, $a, '2026-11-16', [Metric::FOLLOWERS => 1000]);
    }
    goalSnapshot($owner, $brand, $account, '2026-11-17', [Metric::FOLLOWERS => 700]);
    goalSnapshot($owner, $brand, $second, '2026-11-17', [Metric::FOLLOWERS => null]);
    expect(app(GoalService::class)->evaluate($owner, $goal))->toBeNull()
        ->and($goal->fresh()->last_value)->toBeNull()
        ->and($owner->notifications()->count())->toBe(0);
});

test('GoalAlert: engagement uses the canonical weighted ratio of interactions to views', function () {
    [$owner, $brand, $account, $goal] = goalAlertFixture('milestone', 'engagement', 8);
    $goal->forceFill(['social_account_id' => null])->save();
    $second = SocialAccount::factory()->for($owner)->for($brand)->create(['network' => Network::Facebook]);
    goalSnapshot($owner, $brand, $account, '2026-11-17', [Metric::INTERACTIONS => 100, Metric::VIEWS => 1000]);
    goalSnapshot($owner, $brand, $second, '2026-11-17', [Metric::INTERACTIONS => 0, Metric::VIEWS => 100]);
    expect(app(GoalService::class)->evaluate($owner, $goal))->not->toBeNull()
        ->and($goal->fresh()->last_value)->toBe(9.09);
});

test('GoalAlert: a known token expiry alerts once per account deadline without loading provider data', function () {
    $owner = User::factory()->create(['notify_by_mail' => false]);
    $brand = Brand::factory()->for($owner)->create(['timezone' => 'UTC']);
    $account = SocialAccount::factory()->for($owner)->for($brand)->create([
        'network' => Network::LinkedIn, 'refresh_token' => null, 'access_token' => 'GOAL-TOKEN-PRIVATE', 'token_expires_at' => now()->addDays(2),
        'refresh_expires_at' => null, 'data_access_expires_at' => now()->addDays(7),
    ]);
    $goal = app(GoalService::class)->create($owner, $brand, [
        'name' => 'Vigilar token', 'kind' => 'token_expiry', 'threshold' => 3, 'social_account_id' => $account->id,
    ]);
    $service = app(GoalService::class);
    expect($service->evaluate($owner, $goal))->not->toBeNull()
        ->and($service->evaluate($owner, $goal))->toBeNull()
        ->and($owner->notifications()->count())->toBe(1)
        ->and($owner->notifications()->sole()->data['expires_at'])->toBe(now()->addDays(2)->toIso8601String())
        ->and(json_encode($owner->notifications()->sole()->data))->not->toContain('GOAL-TOKEN-PRIVATE');
    Carbon::setTestNow('2026-11-19 08:00:00 UTC');
    expect($service->evaluate($owner, $goal))->toBeNull()->and($owner->notifications()->count())->toBe(1);
    Http::assertNothingSent();
    Queue::assertNothingPushed();
});

test('GoalAlert: unknown expiry dates cannot be converted into an expired token', function () {
    $owner = User::factory()->create();
    $brand = Brand::factory()->for($owner)->create(['timezone' => 'UTC']);
    $account = SocialAccount::factory()->for($owner)->for($brand)->create([
        'token_expires_at' => null, 'refresh_expires_at' => null, 'data_access_expires_at' => null,
    ]);
    $goal = app(GoalService::class)->create($owner, $brand, [
        'name' => 'Fecha desconocida', 'kind' => 'token_expiry', 'threshold' => 3, 'social_account_id' => $account->id,
    ]);
    expect(app(GoalService::class)->evaluate($owner, $goal))->toBeNull()
        ->and($goal->fresh()->last_value)->toBeNull()->and($owner->notifications()->count())->toBe(0);
});

test('GoalAlert: optional email is queued separately after commit while the in-app alert remains unique', function () {
    [$owner, $brand, $account, $goal] = goalAlertFixture();
    $owner->forceFill(['notify_by_mail' => true])->save();
    $goal->forceFill(['mail_enabled' => true])->save();
    goalSnapshot($owner, $brand, $account, '2026-11-17', [Metric::FOLLOWERS => 1200]);
    $service = app(GoalService::class);
    $service->evaluate($owner, $goal);
    $service->evaluate($owner, $goal);
    expect($owner->notifications()->count())->toBe(1);
    Queue::assertPushed(SendQueuedNotifications::class, fn ($job) => $job->channels === ['mail'] && $job->afterCommit === true && $job->queue === 'poll');
    Queue::assertPushed(SendQueuedNotifications::class, 1);
    $job = Queue::pushed(SendQueuedNotifications::class)->sole();
    expect($job->notification->shouldSend($owner, 'mail'))->toBeTrue();
    $owner->forceFill(['notify_by_mail' => false])->save();
    expect($job->notification->shouldSend($owner, 'mail'))->toBeFalse();
    $owner->forceFill(['notify_by_mail' => true])->save();
    $goal->forceFill(['mail_enabled' => false])->save();
    expect($job->notification->shouldSend($owner, 'mail'))->toBeFalse();
    $goal->forceFill(['mail_enabled' => true])->save();
    $goal->delete();
    expect($job->notification->shouldSend($owner, 'mail'))->toBeFalse();
    Mail::assertNothingSent();
});

test('GoalAlert: email needs goal opt-in global preference and a verified destination', function (bool $goalMail, bool $userMail, bool $verified) {
    [$owner, $brand, $account, $goal] = goalAlertFixture();
    $owner->forceFill(['notify_by_mail' => $userMail, 'email_verified_at' => $verified ? now() : null])->save();
    $goal->forceFill(['mail_enabled' => $goalMail])->save();
    goalSnapshot($owner, $brand, $account, '2026-11-17', [Metric::FOLLOWERS => 1200]);
    app(GoalService::class)->evaluate($owner, $goal);
    expect($owner->notifications()->count())->toBe(1);
    Queue::assertNothingPushed();
    Mail::assertNothingSent();
})->with(['no goal opt-in' => [false, true, true], 'global opt-out' => [true, false, true], 'unverified' => [true, true, false]]);

test('GoalAlert: a failed database notification rolls back its event and a later evaluation can retry', function () {
    [$owner, $brand, $account, $goal] = goalAlertFixture();
    goalSnapshot($owner, $brand, $account, '2026-11-17', [Metric::FOLLOWERS => 1200]);
    $sender = Notification::getFacadeRoot();
    Notification::shouldReceive('sendNow')->once()->andThrow(new RuntimeException('notification-write-failed'));
    expect(fn () => app(GoalService::class)->evaluate($owner, $goal))->toThrow(RuntimeException::class, 'notification-write-failed');
    expect(GoalAlertEvent::withoutUserScope()->count())->toBe(0)
        ->and($owner->notifications()->count())->toBe(0)
        ->and($goal->fresh()->last_evaluated_at)->toBeNull();
    Notification::swap($sender);
    expect(app(GoalService::class)->evaluate($owner, $goal))->not->toBeNull()
        ->and($owner->notifications()->count())->toBe(1);
});

test('GoalAlert: the closed metric day follows the brand timezone across a UTC date boundary', function () {
    [$owner, $brand, $account, $goal] = goalAlertFixture();
    $brand->forceFill(['timezone' => 'America/Los_Angeles'])->save();
    Carbon::setTestNow('2026-11-18 02:00:00 UTC');
    goalSnapshot($owner, $brand, $account, '2026-11-16', [Metric::FOLLOWERS => 1200]);
    goalSnapshot($owner, $brand, $account, '2026-11-17', [Metric::FOLLOWERS => 500]);
    expect(app(GoalService::class)->evaluate($owner, $goal))->not->toBeNull()
        ->and($goal->fresh()->last_period)->toBe('2026-11-16')
        ->and($goal->fresh()->last_value)->toBe(1200.0);
});

test('GoalAlert: positive brand offsets never evaluate a date that the network collector has not closed', function (string $timezone) {
    [$owner, $brand, $account, $goal] = goalAlertFixture();
    $brand->forceFill(['timezone' => $timezone])->save();
    Carbon::setTestNow('2026-11-18 13:15:00 UTC');
    goalSnapshot($owner, $brand, $account, '2026-11-17', [Metric::FOLLOWERS => 1200]);
    expect(app(GoalService::class)->evaluate($owner, $goal))->not->toBeNull()
        ->and($goal->fresh()->last_period)->toBe('2026-11-17');
})->with(['Pacific/Auckland', 'Australia/Sydney']);

test('GoalAlert: changing a drop threshold creates a distinct rule observation for the same period', function () {
    [$owner, $brand, $account, $goal] = goalAlertFixture('drop', 'followers', 25);
    goalSnapshot($owner, $brand, $account, '2026-11-16', [Metric::FOLLOWERS => 2000]);
    goalSnapshot($owner, $brand, $account, '2026-11-17', [Metric::FOLLOWERS => 1400]);
    $service = app(GoalService::class);
    expect($service->evaluate($owner, $goal))->not->toBeNull();
    $service->update($owner, $goal, ['name' => $goal->name, 'kind' => 'drop', 'metric' => 'followers', 'threshold' => 28, 'social_account_id' => $account->id]);
    expect($service->evaluate($owner, $goal->fresh()))->not->toBeNull()->and($owner->notifications()->count())->toBe(2);
});

test('GoalAlert: automatically renewable short access tokens do not create recurring connection-expiry warnings', function (Network $network) {
    $owner = User::factory()->create(['notify_by_mail' => false]);
    $brand = Brand::factory()->for($owner)->create(['timezone' => 'UTC']);
    $account = SocialAccount::factory()->for($owner)->for($brand)->create(['network' => $network, 'refresh_token' => 'fake-refresh', 'token_expires_at' => now()->addHour(), 'refresh_expires_at' => now()->addDays(30), 'data_access_expires_at' => null]);
    $goal = app(GoalService::class)->create($owner, $brand, ['name' => 'Renovación automática', 'kind' => 'token_expiry', 'threshold' => 3, 'social_account_id' => $account->id]);
    expect(app(GoalService::class)->evaluate($owner, $goal))->toBeNull();
    Carbon::setTestNow('2026-11-19 08:00:00 UTC');
    $account->forceFill(['token_expires_at' => now()->addHour()])->save();
    expect(app(GoalService::class)->evaluate($owner, $goal->fresh()))->toBeNull()->and($owner->notifications()->count())->toBe(0);
    Http::assertNothingSent();
})->with([Network::YouTube, Network::TikTok]);

test('GoalAlert: an already expired non-renewable LinkedIn connection remains a negative known deadline', function () {
    $owner = User::factory()->create(['notify_by_mail' => false]);
    $brand = Brand::factory()->for($owner)->create(['timezone' => 'UTC']);
    $account = SocialAccount::factory()->for($owner)->for($brand)->create(['network' => Network::LinkedIn, 'refresh_token' => null, 'token_expires_at' => now()->subDay(), 'refresh_expires_at' => null, 'data_access_expires_at' => null]);
    $goal = app(GoalService::class)->create($owner, $brand, ['name' => 'Ya caducada', 'kind' => 'token_expiry', 'threshold' => 3, 'social_account_id' => $account->id]);
    $event = app(GoalService::class)->evaluate($owner, $goal);
    expect($event)->not->toBeNull()->and($goal->fresh()->last_value)->toBe(-1.0)->and($event->payload['message'])->toContain('caducó');
});

test('GoalAlert: mail uses its own configured queue independently of token refresh work', function () {
    config(['goals.mail_queue' => 'poll', 'social.refresh_queue' => 'media']);
    $notification = new GoalAlert(1, ['goal_id' => 1], mailOnly: true);
    expect($notification->queue)->toBe('poll');
});

test('GoalAlert: engagement drop uses a relative percentage instead of percentage points', function () {
    [$owner, $brand, $account, $goal] = goalAlertFixture('drop', 'engagement', 25);
    goalSnapshot($owner, $brand, $account, '2026-11-16', [Metric::INTERACTIONS => 200, Metric::VIEWS => 1000]);
    goalSnapshot($owner, $brand, $account, '2026-11-17', [Metric::INTERACTIONS => 100, Metric::VIEWS => 1000]);
    $event = app(GoalService::class)->evaluate($owner, $goal);
    expect($event)->not->toBeNull()->and($event->payload['message'])->toContain('Caída del 50%')->and($event->payload['value'])->toBe(10);
});

test('GoalAlert: an encrypted empty LinkedIn refresh token is not mistaken for automatic renewal', function () {
    $owner = User::factory()->create(['notify_by_mail' => false]);
    $brand = Brand::factory()->for($owner)->create(['timezone' => 'UTC']);
    $account = SocialAccount::factory()->for($owner)->for($brand)->create(['network' => Network::LinkedIn, 'refresh_token' => '', 'token_expires_at' => now()->addDays(2), 'refresh_expires_at' => null, 'data_access_expires_at' => null]);
    expect($account->getRawOriginal('refresh_token'))->not->toBe('');
    $goal = app(GoalService::class)->create($owner, $brand, ['name' => 'Refresh vacío', 'kind' => 'token_expiry', 'threshold' => 3, 'social_account_id' => $account->id]);
    expect(app(GoalService::class)->evaluate($owner, $goal))->not->toBeNull();
});

test('GoalAlert: exact expiry is retained for the view and human notices use the brand date', function () {
    $owner = User::factory()->create(['notify_by_mail' => false]);
    $brand = Brand::factory()->for($owner)->create(['timezone' => 'Europe/Madrid']);
    $expiry = Carbon::parse('2026-11-20 23:30:00 UTC');
    $account = SocialAccount::factory()->for($owner)->for($brand)->create(['network' => Network::LinkedIn, 'refresh_token' => null, 'token_expires_at' => $expiry, 'refresh_expires_at' => null, 'data_access_expires_at' => null]);
    $service = app(GoalService::class);
    $data = ['name' => 'Fecha exacta', 'kind' => 'token_expiry', 'threshold' => 3, 'social_account_id' => $account->id];
    $goal = $service->create($owner, $brand, $data);
    $event = $service->evaluate($owner, $goal);
    expect($event->payload['message'])->toContain('21 de noviembre de 2026');
    expect($goal->fresh()->last_expires_at?->toIso8601String())->toBe($expiry->toIso8601String());
    $this->actingAs($owner)->get('/goals?brand_id='.$brand->id)->assertOk()
        ->assertInertia(fn ($page) => $page->where('goals.0.last_expires_at', $expiry->toIso8601String()));
    $renamed = $service->update($owner, $goal->fresh(), array_replace($data, ['name' => 'Renombrado']));
    expect($renamed->last_expires_at?->toIso8601String())->toBe($expiry->toIso8601String());
    $changed = $service->update($owner, $renamed, array_replace($data, ['threshold' => 4]));
    expect($changed->last_expires_at)->toBeNull();
});

test('GoalAlert: a connection already requiring reconnection is explicit in its owned view instead of implying missing provider data', function () {
    $owner = User::factory()->create();
    $brand = Brand::factory()->for($owner)->create(['timezone' => 'UTC']);
    $account = SocialAccount::factory()->for($owner)->for($brand)->create(['network' => Network::LinkedIn, 'status' => SocialAccount::STATUS_RECONNECT, 'refresh_token' => null, 'token_expires_at' => now()->subDay()]);
    $goal = app(GoalService::class)->create($owner, $brand, ['name' => 'Reconectar', 'kind' => 'token_expiry', 'threshold' => 3, 'social_account_id' => $account->id]);
    expect(app(GoalService::class)->evaluate($owner, $goal))->toBeNull();
    $this->actingAs($owner)->get('/goals?brand_id='.$brand->id)->assertOk()
        ->assertInertia(fn ($page) => $page->where('goals.0.account_requires_reconnection', true));
});

test('GoalAlert: a known expired actionable deadline takes priority over a future one without assuming the expired value is stale', function () {
    $owner = User::factory()->create(['notify_by_mail' => false]);
    $brand = Brand::factory()->for($owner)->create(['timezone' => 'UTC']);
    $account = SocialAccount::factory()->for($owner)->for($brand)->create(['network' => Network::YouTube, 'refresh_token' => 'fake-refresh', 'token_expires_at' => now()->addHour(), 'refresh_expires_at' => now()->subDay(), 'data_access_expires_at' => now()->addDays(5)]);
    $goal = app(GoalService::class)->create($owner, $brand, ['name' => 'Ya requiere actuar', 'kind' => 'token_expiry', 'threshold' => 3, 'social_account_id' => $account->id]);
    $event = app(GoalService::class)->evaluate($owner, $goal);
    expect($event)->not->toBeNull()->and($event->payload['expires_at'])->toBe(now()->subDay()->toIso8601String());
});

test('GoalAlert: a transient failure retries after processing other owners without duplicating their committed notices', function () {
    [$firstOwner, $firstBrand, $firstAccount, $firstGoal] = goalAlertFixture();
    [$secondOwner, $secondBrand, $secondAccount, $secondGoal] = goalAlertFixture();
    goalSnapshot($firstOwner, $firstBrand, $firstAccount, '2026-11-17', [Metric::FOLLOWERS => 1200]);
    goalSnapshot($secondOwner, $secondBrand, $secondAccount, '2026-11-17', [Metric::FOLLOWERS => 1200]);
    $real = Notification::getFacadeRoot();
    $fail = true;
    Notification::shouldReceive('sendNow')->andReturnUsing(function ($user, $notification) use (&$fail, $real) {
        if ($fail) {
            $fail = false;
            throw new RuntimeException('transient-notification-fixture');
        }

        return $real->sendNow($user, $notification);
    });
    $job = new EvaluateGoals;
    expect(fn () => $job->handle(app(GoalService::class)))->toThrow(RuntimeException::class, 'La evaluación terminó con errores');
    expect($firstOwner->notifications()->count())->toBe(0)->and($secondOwner->notifications()->count())->toBe(1);
    $job->handle(app(GoalService::class));
    expect($firstOwner->notifications()->count())->toBe(1)->and($secondOwner->notifications()->count())->toBe(1);
    Http::assertNothingSent();
});
