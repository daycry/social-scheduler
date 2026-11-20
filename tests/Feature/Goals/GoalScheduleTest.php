<?php

use App\Analytics\Metric;
use App\Connectors\Network;
use App\Jobs\EvaluateGoals;
use App\Models\AccountSnapshot;
use App\Models\Brand;
use App\Models\SocialAccount;
use App\Models\User;
use App\Services\GoalService;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

test('GoalSchedule: daily evaluation has an explicit schedule and overlapping dispatch protection', function () {
    $event = collect(app(Schedule::class)->events())->first(fn ($event) => $event->description === 'evaluate-goals');
    expect($event)->not->toBeNull()->and($event->expression)->toBe('15 13 * * *')
        ->and($event->withoutOverlapping)->toBeTrue();
    $collection = collect(app(Schedule::class)->events())->first(fn ($event) => $event->description === 'collect-snapshots');
    expect($event->nextRunDate('2026-11-18 00:00:00')->greaterThan($collection->nextRunDate('2026-11-18 00:00:00')))->toBeTrue();
});

test('GoalSchedule: the scheduled handler evaluates enabled goals without auth and retries do not duplicate alerts', function () {
    Carbon::setTestNow('2026-11-18 08:00:00 UTC');
    Queue::fake();
    Http::preventStrayRequests();
    try {
        $owner = User::factory()->create(['notify_by_mail' => false]);
        $brand = Brand::factory()->for($owner)->create(['timezone' => 'UTC']);
        $account = SocialAccount::factory()->for($owner)->for($brand)->create(['network' => Network::Instagram]);
        $goal = app(GoalService::class)->create($owner, $brand, [
            'name' => 'Meta diaria', 'kind' => 'milestone', 'metric' => 'followers',
            'threshold' => 1000, 'social_account_id' => $account->id,
        ]);
        AccountSnapshot::withoutUserScope()->create([
            'user_id' => $owner->id, 'brand_id' => $brand->id, 'social_account_id' => $account->id,
            'network' => Network::Instagram, 'date' => '2026-11-17',
            'metrics' => array_replace(Metric::blankAccount(), [Metric::FOLLOWERS => 1200]), 'collected_at' => now(),
        ]);
        Auth::logout();
        $event = collect(app(Schedule::class)->events())->first(fn ($event) => $event->description === 'evaluate-goals');
        expect($event)->not->toBeNull();
        $event->run(app());
        Queue::assertPushed('App\Jobs\EvaluateGoals', 1);
        $job = Queue::pushed('App\Jobs\EvaluateGoals')->sole();
        expect($job->queue)->toBe('metrics');
        $job->handle(app(GoalService::class));
        $job->handle(app(GoalService::class));
        expect($owner->notifications()->count())->toBe(1)
            ->and($owner->notifications()->sole()->data['goal_id'])->toBe($goal->id);
        Http::assertNothingSent();
    } finally {
        Carbon::setTestNow();
    }
});

test('GoalSchedule: one corrupted brand timezone cannot prevent evaluation for another owner', function () {
    Carbon::setTestNow('2026-11-18 13:15:00 UTC');
    Queue::fake();
    Http::preventStrayRequests();
    try {
        $owners = [];
        foreach ([true, false] as $corrupt) {
            $owner = User::factory()->create(['notify_by_mail' => false]);
            $brand = Brand::factory()->for($owner)->create(['timezone' => 'UTC']);
            $account = SocialAccount::factory()->for($owner)->for($brand)->create(['network' => Network::Instagram]);
            app(GoalService::class)->create($owner, $brand, ['name' => 'Objetivo aislado', 'kind' => 'milestone', 'metric' => 'followers', 'threshold' => 1000, 'social_account_id' => $account->id]);
            AccountSnapshot::withoutUserScope()->create(['user_id' => $owner->id, 'brand_id' => $brand->id, 'social_account_id' => $account->id, 'network' => Network::Instagram, 'date' => '2026-11-17', 'metrics' => array_replace(Metric::blankAccount(), [Metric::FOLLOWERS => 1200]), 'collected_at' => now()]);
            if ($corrupt) {
                $brand->forceFill(['timezone' => 'Invalid/Poison'])->save();
            }
            $owners[] = $owner;
        }
        Auth::logout();
        expect(fn () => (new EvaluateGoals)->handle(app(GoalService::class)))->toThrow(RuntimeException::class, 'La evaluación terminó con errores');
        expect($owners[0]->notifications()->count())->toBe(0)->and($owners[1]->notifications()->count())->toBe(1);
        Http::assertNothingSent();
    } finally {
        Carbon::setTestNow();
    }
});
