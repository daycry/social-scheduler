<?php

use App\Analytics\Metric;
use App\Models\AccountSnapshot;
use App\Models\Brand;
use App\Models\Goal;
use App\Models\GoalAlertEvent;
use App\Models\SocialAccount;
use App\Models\User;
use App\Services\GoalService;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;

chdir('C:/laragon/www/metricool/.cache/t79-physical');
require 'vendor/autoload.php';
$app = require 'bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
if (config('database.default') !== 'mysql' || ! str_starts_with(config('database.connections.mysql.database'), 'metricool_t79_')) {
    throw new RuntimeException('Dedicated disposable MySQL database required.');
}
Http::preventStrayRequests();
Queue::fake();
$mode = $argv[1];
if ($mode === 'migrations') {
    $paths = ['database/migrations/2026_11_18_091000_create_goals_table.php', 'database/migrations/2026_11_18_091100_add_evaluation_to_goals_and_create_goal_alerts.php'];
    Artisan::call('migrate:rollback', ['--path' => $paths, '--step' => DB::table('migrations')->count(), '--force' => true]);
    if (Schema::hasTable('goals') || Schema::hasTable('goal_alert_events')) {
        throw new RuntimeException('Both new tables must roll back.');
    }
    Artisan::call('migrate', ['--path' => $paths, '--force' => true]);
    if (! Schema::hasTable('goals') || ! Schema::hasTable('goal_alert_events')) {
        throw new RuntimeException('Both new tables must migrate again.');
    }
    echo json_encode(['rollback_and_up' => true], JSON_THROW_ON_ERROR);
} elseif ($mode === 'seed') {
    $user = User::factory()->create(['notify_by_mail' => false]);
    $brand = Brand::factory()->for($user)->create(['timezone' => 'UTC']);
    $account = SocialAccount::factory()->for($user)->for($brand)->create();
    AccountSnapshot::withoutUserScope()->create([
        'user_id' => $user->id, 'brand_id' => $brand->id, 'social_account_id' => $account->id,
        'network' => $account->network, 'date' => now()->subDay()->toDateString(),
        'metrics' => array_replace(Metric::blankAccount(), [Metric::FOLLOWERS => 1200]), 'collected_at' => now(),
    ]);
    $goal = app(GoalService::class)->create($user, $brand, [
        'name' => 'Concurrency probe', 'kind' => 'milestone', 'metric' => 'followers', 'threshold' => 1000,
        'social_account_id' => $account->id,
    ]);
    echo json_encode(['user' => $user->id, 'goal' => $goal->id], JSON_THROW_ON_ERROR);
} elseif ($mode === 'worker') {
    $user = User::findOrFail((int) $argv[2]);
    $goal = Goal::ownedBy($user->id)->findOrFail((int) $argv[3]);
    file_put_contents($argv[4], 'ready');
    $deadline = microtime(true) + 15;
    while (! file_exists($argv[5])) {
        if (microtime(true) > $deadline) {
            throw new RuntimeException('Barrier timed out.');
        }
        usleep(10000);
    }
    $start = microtime(true);
    $event = app(GoalService::class)->evaluate($user, $goal);
    echo json_encode(['created' => $event !== null, 'duration_ms' => round((microtime(true) - $start) * 1000, 2)], JSON_THROW_ON_ERROR);
} elseif ($mode === 'verify') {
    $user = User::findOrFail((int) $argv[2]);
    $events = GoalAlertEvent::ownedBy($user->id)->where('goal_id', (int) $argv[3])->count();
    $notifications = $user->notifications()->count();
    if ($events !== 1 || $notifications !== 1) {
        throw new RuntimeException('Expected exactly one event and database notification.');
    }
    echo json_encode(['events' => $events, 'notifications' => $notifications], JSON_THROW_ON_ERROR);
}
