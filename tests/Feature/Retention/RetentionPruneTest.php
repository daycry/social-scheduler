<?php

use App\Models\AuditLog;
use App\Models\Brand;
use App\Models\Canva\CanvaJob;
use App\Models\PublishAttempt;
use App\Models\Studio\DesignExport;
use App\Models\Studio\DesignRevision;
use App\Models\Studio\StudioAiJob;
use App\Models\Studio\StudioAvStep;
use App\Models\Studio\StudioImageJob;
use App\Models\User;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Database\Eloquent\MassPrunable;

test('the eight tables use MassPrunable and the prune command is scheduled daily', function () {
    foreach ([AuditLog::class, PublishAttempt::class, StudioAiJob::class, StudioImageJob::class, CanvaJob::class, DesignExport::class, StudioAvStep::class, DesignRevision::class] as $model) {
        expect(class_uses_recursive($model))->toContain(MassPrunable::class);
    }
    $event = collect(app(Schedule::class)->events())->first(fn ($e) => str_contains((string) $e->command, 'model:prune'));
    expect($event)->not->toBeNull()->and($event->expression)->toBe('50 4 * * *');
});

test('audit logs are pruned by age according to config and zero disables pruning', function () {
    $user = User::factory()->create();
    foreach ([400, 10] as $age) {
        AuditLog::query()->create(['user_id' => $user->id, 'channel' => 'mcp', 'action' => 'x', 'outcome' => 'ok', 'created_at' => now()->subDays($age)]);
    }
    config(['retention.audit_logs_days' => 0]);
    $this->artisan('model:prune', ['--model' => [AuditLog::class]])->assertSuccessful();
    expect(AuditLog::query()->count())->toBe(2);

    config(['retention.audit_logs_days' => 365]);
    $this->artisan('model:prune', ['--model' => [AuditLog::class]])->assertSuccessful();
    expect(AuditLog::query()->count())->toBe(1);
});

test('terminal jobs older than the retention are pruned but active ones are kept', function () {
    $user = User::factory()->create();
    $brand = Brand::factory()->for($user)->create();
    $old = now()->subDays(200);
    foreach (['ready', 'queued'] as $status) {
        StudioAiJob::withoutUserScope()->forceCreate(['user_id' => $user->id, 'brand_id' => $brand->id, 'kind' => 'generate', 'provider' => 'openai', 'model' => 'm', 'prompt' => 'p', 'status' => $status,
            'fingerprint' => md5($status), 'idempotency_key' => md5($status.'k'), 'created_at' => $old, 'updated_at' => $old]);
    }
    $this->artisan('model:prune', ['--model' => [StudioAiJob::class]])->assertSuccessful();
    expect(StudioAiJob::withoutUserScope()->pluck('status')->all())->toBe(['queued']);
});

test('model:prune --pretend runs over all models without errors', function () {
    $this->artisan('model:prune', ['--pretend' => true])->assertSuccessful();
});
