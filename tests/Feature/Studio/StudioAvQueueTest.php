<?php

use App\Jobs\Studio\ProcessStudioAvPhase;
use App\Models\Brand;
use App\Models\MediaAsset;
use App\Models\Studio\DesignExport;
use App\Models\Studio\StudioAvLease;
use App\Models\Studio\StudioAvWorkspace;
use App\Models\User;
use App\Services\Studio\StudioAssets;
use App\Services\Studio\StudioAvCleanup;
use App\Services\Studio\StudioAvFiles;
use App\Services\Studio\StudioAvQueue;
use App\Services\Studio\StudioAvRecovery;
use App\Services\Studio\StudioAvRunner;
use App\Services\Studio\StudioAvState;
use App\Services\Studio\StudioAvStorage;
use App\Services\Studio\StudioRenderFailure;
use App\Services\Studio\StudioService;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

beforeEach(function () {
    $this->realQueue = Queue::getFacadeRoot();
    Queue::fake();
    Storage::fake('local');
    config(['filesystems.media' => 'local']);
    $this->user = User::factory()->create();
    $this->brand = Brand::factory()->for($this->user)->create();
    $document = ['schema_version' => 2, 'width' => 100, 'height' => 80, 'timeline' => ['fps' => 12, 'audio_tracks' => []],
        'pages' => [['id' => 'one', 'duration' => 1, 'background' => '#ffffff', 'elements' => []]]];
    $design = app(StudioService::class)->create($this->user, $this->brand, ['name' => 'AV queue', 'document' => $document]);
    $export = DesignExport::create(['user_id' => $this->user->id, 'brand_id' => $this->brand->id, 'design_id' => $design->id,
        'revision' => 1, 'format' => 'mp4', 'pages' => ['one'], 'options' => ['scale' => 1, 'quality' => 0.94], 'status' => 'pending']);
    $this->state = app(StudioAvState::class);
    $this->export = $this->state->initialize($this->user, $export);
});

test('AV queue delivery begins its reserved lease exactly once and may renew a still-current queued lease', function () {
    $lease = $this->state->claim($this->export->id, $this->export->render_state['generation']);
    $this->travel(181)->seconds();
    $started = $this->state->begin($lease->id);
    expect($started)->not->toBeNull();
    expect($started->started_at)->not->toBeNull()->and($started->expires_at->isFuture())->toBeTrue();
    expect($this->state->begin($lease->id))->toBeNull();
    $this->travel(181)->seconds();
    expect($this->state->begin($lease->id))->toBeNull();
});

test('AV queue delivery cannot begin a cancelled or replaced generation', function () {
    $lease = $this->state->claim($this->export->id, $this->export->render_state['generation']);
    $this->state->cancel($this->user, $this->export);
    $this->state->retry($this->user, $this->export);
    expect($this->state->begin($lease->id))->toBeNull();
});

test('AV queue schedules one private lease delivery on the dedicated queue without a handoff unique lock', function () {
    $queue = app(StudioAvQueue::class);
    expect($queue->enqueue($this->export->id))->toBeTrue();
    expect($queue->enqueue($this->export->id))->toBeFalse();
    $lease = StudioAvLease::findOrFail($this->export->refresh()->render_state['lease']);
    expect($lease->phase)->toBe('plan')->and($lease->reserved_bytes)->toBe(0)->and($lease->started_at)->toBeNull();
    Queue::assertPushed(ProcessStudioAvPhase::class, 1);
    Queue::assertPushed(ProcessStudioAvPhase::class, fn ($job) => $job->leaseId === $lease->id
        && $job->connection === 'database' && $job->queue === 'studio' && $job->tries === 1 && $job->timeout === 100
        && ! $job instanceof ShouldBeUnique);
});

test('AV state mutations serialize an independent SQLite session writer', function (string $mutation) {
    $plan = $mutation === 'initialize' ? null : $this->state->claim($this->export->id, $this->export->render_state['generation']);
    $planData = ['frameCount' => 12, 'fps' => 12, 'duration' => 1, 'sampleRate' => 48000, 'segmentFrames' => 24, 'segmentCount' => 1,
        'selection' => ['one'], 'assetIds' => [], 'fontIds' => [], 'audioAssetIds' => [],
        'render' => ['width' => 100, 'height' => 80, 'paddedWidth' => 100, 'paddedHeight' => 80, 'scale' => 1, 'quality' => 0.94]];
    if ($mutation === 'initialize') {
        $this->export = DesignExport::create(['user_id' => $this->user->id, 'brand_id' => $this->brand->id, 'design_id' => $this->export->design_id,
            'revision' => 1, 'format' => 'mp4', 'pages' => ['one'], 'options' => ['scale' => 1, 'quality' => 0.94], 'status' => 'pending']);
    } elseif ($mutation === 'claim') {
        $this->state->accept($plan, $planData);
    } elseif ($mutation === 'retry') {
        $this->state->cancel($this->user, $this->export);
    } elseif ($mutation === 'rejectCheckpoint') {
        $this->state->accept($plan, $planData);
        $segment = $this->state->claim($this->export->id, $plan->generation, 1);
        $this->state->accept($segment, ['file' => 'segments/'.$segment->id.'/video-000000.mp4', 'namespace' => $segment->id,
            'size' => 1, 'sha256' => str_repeat('a', 64)], 1);
        $this->state->cancel($this->user, $this->export);
        $retry = $this->state->retry($this->user, $this->export);
        $this->state->accept($this->state->claim($retry->id, $retry->render_state['generation']), $planData);
        $plan = $this->state->claim($retry->id, $retry->render_state['generation']);
        expect($plan->phase)->toBe('verify');
    }
    $originalConnection = config('database.default');
    $directory = storage_path('framework/testing/t11-register-debug-claim-'.Str::uuid());
    mkdir($directory, 0700, true);
    $database = $directory.'/db.sqlite';
    $marker = $directory.'/writer-started';
    $target = new PDO('sqlite:'.$database);
    $target->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    foreach (DB::select("select name, sql from sqlite_master where type = 'table' and name not like 'sqlite_%'") as $table) {
        $target->exec($table->sql);
        foreach (DB::select('select * from "'.$table->name.'"') as $row) {
            $values = (array) $row;
            $statement = $target->prepare('insert into "'.$table->name.'" values ('.implode(',', array_fill(0, count($values), '?')).')');
            $statement->execute(array_values($values));
        }
    }
    $target->exec('create table claim_contention (value integer)');
    $target->exec('insert into claim_contention values (0)');
    $target = null;
    config(['database.connections.claim_contention' => array_replace(config('database.connections.sqlite'),
        ['database' => $database, 'busy_timeout' => 5000, 'journal_mode' => 'DELETE']), 'database.default' => 'claim_contention']);
    $writer = null;
    $armed = true;
    DB::listen(function ($query) use (&$armed, &$writer, $database, $marker) {
        if (! $armed || $query->connectionName !== 'claim_contention' || $query->connection->transactionLevel() === 0
            || ! str_starts_with($query->sql, 'select ')) {
            return;
        }
        $armed = false;
        $writer = new Symfony\Component\Process\Process([PHP_BINARY, '-r', <<<'PHP'
$db = new PDO('sqlite:'.$argv[1]);
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$db->exec('PRAGMA busy_timeout=5000');
file_put_contents($argv[2], 'started');
$db->beginTransaction();
$db->exec('update claim_contention set value = value + 1');
$db->commit();
PHP, $database, $marker]);
        $writer->start();
        $limit = microtime(true) + 3;
        while (! is_file($marker) && microtime(true) < $limit) {
            usleep(1000);
            clearstatcache(true, $marker);
        }
        expect(is_file($marker))->toBeTrue();
        usleep(100000);
    });
    try {
        $result = match ($mutation) {
            'initialize' => $this->state->initialize($this->user, $this->export),
            'claim' => app(StudioAvQueue::class)->enqueue($this->export->id),
            'begin' => $this->state->begin($plan->id),
            'defer' => $this->state->defer($plan),
            'accept' => $this->state->accept($plan, $planData),
            'cancel' => $this->state->cancel($this->user, $this->export),
            'retry' => $this->state->retry($this->user, $this->export),
            'fail' => $this->state->fail($plan, 'phase_failed'),
            'failPosition' => $this->state->failPosition($this->export->id, $plan->generation, 0, 'phase_failed'),
            'rejectCheckpoint' => $this->state->rejectCheckpoint($plan),
        };
        expect((bool) $result)->toBeTrue();
        expect($writer)->toBeInstanceOf(Symfony\Component\Process\Process::class);
        expect($writer->wait())->toBe(0);
        $export = DesignExport::withoutUserScope()->findOrFail($this->export->id);
        $expectedStatus = match ($mutation) {
            'cancel' => 'cancelled', 'initialize', 'retry' => 'pending', 'fail', 'failPosition' => 'failed', default => 'processing',
        };
        expect($export->status)->toBe($expectedStatus);
        if ($mutation === 'claim') {
            $lease = StudioAvLease::findOrFail($export->render_state['lease']);
            expect($lease->phase)->toBe('render')->and($lease->status)->toBe('running');
            expect(StudioAvLease::where('status', 'accepted')->count())->toBe(1);
        } elseif ($mutation === 'begin') {
            expect(StudioAvLease::findOrFail($plan->id)->started_at)->not->toBeNull();
        } else {
            expect($export->render_state['lease'])->toBeNull();
        }
        expect((int) DB::scalar('select value from claim_contention'))->toBe(1);
        if ($mutation === 'claim') {
            Queue::assertPushed(ProcessStudioAvPhase::class, 1);
        } else {
            Queue::assertNotPushed(ProcessStudioAvPhase::class); // el aviso de fallo (SendQueuedNotifications) es un efecto legitimo de fail/failPosition
        }
    } finally {
        if ($writer instanceof Symfony\Component\Process\Process) {
            $writer->wait();
        }
        config(['database.default' => $originalConnection]);
        DB::purge('claim_contention');
    }
})->with(['initialize', 'claim', 'begin', 'defer', 'accept', 'cancel', 'retry', 'fail', 'failPosition', 'rejectCheckpoint']);

test('AV recovery recognizes a database message still waiting for a worker and cannot prune its lease', function () {
    Queue::swap($this->realQueue);
    $queue = app(StudioAvQueue::class);
    expect($queue->enqueue($this->export->id))->toBeTrue();
    $lease = StudioAvLease::findOrFail($this->export->refresh()->render_state['lease']);
    expect($lease->queue_job_id)->toBeGreaterThan(0)->and($queue->pending($lease))->toBeTrue();
    $this->travel(181)->seconds();
    expect($queue->enqueue($this->export->id))->toBeFalse();
    expect(app(StudioAvCleanup::class)->lease($lease->id))->toBeFalse();
    expect($this->state->begin($lease->id))->not->toBeNull();
    expect($queue->pending($lease->refresh()))->toBeFalse();
});

test('AV enqueue cannot apply a stale phase reservation after another delivery advances the cursor', function () {
    $planLease = $this->state->claim($this->export->id, $this->export->render_state['generation']);
    $real = $this->state;
    $plan = ['frameCount' => 12, 'fps' => 12, 'duration' => 1, 'sampleRate' => 48000, 'segmentFrames' => 24, 'segmentCount' => 1,
        'selection' => ['one'], 'assetIds' => [], 'fontIds' => [], 'audioAssetIds' => [],
        'render' => ['width' => 100, 'height' => 80, 'paddedWidth' => 100, 'paddedHeight' => 80, 'scale' => 1, 'quality' => 0.94]];
    $state = Mockery::mock(StudioAvState::class)->makePartial();
    $state->shouldReceive('claim')->once()->andReturnUsing(function ($id, $generation, $bytes, ...$position) use ($real, $planLease, $plan) {
        $real->accept($planLease, $plan);

        return $real->claim($id, $generation, $bytes, ...$position);
    });
    expect((new StudioAvQueue($state))->enqueue($this->export->id))->toBeFalse();
    expect($this->state->task($this->export->refresh()))->toBe(['phase' => 'render', 'index' => 0]);
    Queue::assertNothingPushed();
});

test('AV worker accepts a plan and hands off the next phase exactly once when the same job is delivered twice', function () {
    app(StudioAvQueue::class)->enqueue($this->export->id);
    $job = null;
    Queue::assertPushed(ProcessStudioAvPhase::class, function ($queued) use (&$job) {
        $job = $queued;

        return true;
    });
    Process::fake(function ($process) {
        $plan = ['frameCount' => 12, 'fps' => 12, 'duration' => 1, 'sampleRate' => 48000, 'segmentFrames' => 24, 'segmentCount' => 1,
            'selection' => ['one'], 'assetIds' => [], 'fontIds' => [], 'audioAssetIds' => [],
            'render' => ['width' => 100, 'height' => 80, 'paddedWidth' => 100, 'paddedHeight' => 80, 'scale' => 1, 'quality' => 0.94]];

        return Process::result(output: json_encode(['ok' => true, 'namespace' => $process->command[3], 'phase' => 'plan', 'index' => 0,
            'result' => $plan, 'memory' => ['scope' => 'job', 'limit' => 3 * 1024 ** 3, 'kill_on_close' => true, 'breakaway' => false]]));
    });
    $job->handle(app(StudioAvRunner::class));
    expect($this->export->refresh()->render_state['position'])->toBe(1);
    expect($this->state->task($this->export))->toBe(['phase' => 'render', 'index' => 0]);
    Queue::assertPushed(ProcessStudioAvPhase::class, 2);
    $job->handle(app(StudioAvRunner::class));
    Process::assertRanTimes(fn () => true, 1);
    Queue::assertPushed(ProcessStudioAvPhase::class, 2);
    $next = $this->export->refresh()->render_state['lease'];
    $job->failed(new RuntimeException('late private worker error'));
    expect($this->export->refresh()->status)->toBe('processing')->and($this->export->render_state['lease'])->toBe($next);
});

test('AV worker stores a safe failure instead of handing off an invalid native response', function () {
    app(StudioAvQueue::class)->enqueue($this->export->id);
    $id = $this->export->refresh()->render_state['lease'];
    Process::fake(['*' => Process::result(exitCode: 1, output: '{"ok":false,"error_code":"C:/private/secret"}')]);
    (new ProcessStudioAvPhase($id))->handle(app(StudioAvRunner::class));
    expect($this->export->refresh()->status)->toBe('failed')->and($this->export->error)->not->toContain('private', 'secret');
    Queue::assertPushed(ProcessStudioAvPhase::class, 1);
});

test('AV worker reports source replacement as requiring a new export and never leaks its raw error', function () {
    $media = MediaAsset::factory()->for($this->user)->create(['kind' => 'image', 'mime' => 'image/png', 'disk' => 'local', 'path' => 'source.bin', 'size' => 5,
        'declared_size' => 5, 'status' => MediaAsset::STATUS_READY]);
    app(StudioAssets::class)->attach($this->user, $this->brand, $media->id);
    $planLease = $this->state->claim($this->export->id, $this->export->render_state['generation']);
    $plan = ['frameCount' => 12, 'fps' => 12, 'duration' => 1, 'sampleRate' => 48000, 'segmentFrames' => 24, 'segmentCount' => 1,
        'selection' => ['one'], 'assetIds' => [$media->id], 'fontIds' => [], 'audioAssetIds' => [],
        'render' => ['width' => 100, 'height' => 80, 'paddedWidth' => 100, 'paddedHeight' => 80, 'scale' => 1, 'quality' => 0.94]];
    $this->state->accept($planLease, $plan);
    app(StudioAvQueue::class)->enqueue($this->export->id);
    $files = Mockery::mock(StudioAvFiles::class)->makePartial();
    $files->shouldReceive('snapshot')->once()->andThrow(new StudioRenderFailure('secret filesystem path'));
    $this->app->instance(StudioAvFiles::class, $files);
    app(StudioAvRunner::class)->run($this->export->refresh()->render_state['lease']);
    expect($this->export->refresh()->status)->toBe('failed')->and($this->export->error)->toContain('nueva exportación')->not->toContain('secret', 'filesystem');
});

test('AV snapshot worker respects the caller deadline rather than opening a new phase budget', function () {
    Storage::disk('local')->put('source.bin', 'bytes');
    $media = MediaAsset::factory()->for($this->user)->create(['kind' => 'image', 'mime' => 'image/png', 'disk' => 'local', 'path' => 'source.bin',
        'size' => 5, 'declared_size' => 5, 'status' => MediaAsset::STATUS_READY]);
    app(StudioAssets::class)->attach($this->user, $this->brand, $media->id);
    $planLease = $this->state->claim($this->export->id, $this->export->render_state['generation']);
    $plan = ['frameCount' => 12, 'fps' => 12, 'duration' => 1, 'sampleRate' => 48000, 'segmentFrames' => 24, 'segmentCount' => 1,
        'selection' => ['one'], 'assetIds' => [$media->id], 'fontIds' => [], 'audioAssetIds' => [],
        'render' => ['width' => 100, 'height' => 80, 'paddedWidth' => 100, 'paddedHeight' => 80, 'scale' => 1, 'quality' => 0.94]];
    $this->state->accept($planLease, $plan);
    app(StudioAvQueue::class)->enqueue($this->export->id);
    expect(app(StudioAvRunner::class)->run($this->export->refresh()->render_state['lease'], microtime(true) - 1))->toBeFalse();
    expect($this->export->refresh()->status)->toBe('failed');
    $root = 'studio-exports/'.$this->user->id.'/'.$this->export->id.'/'.$this->export->render_state['workspace'];
    expect(Storage::disk('local')->allFiles($root.'/imports'))->toBe([]);
});

test('AV recovery releases an interrupted delivery and schedules a fresh lease without reviving the old one', function () {
    app(StudioAvQueue::class)->enqueue($this->export->id);
    $old = StudioAvLease::findOrFail($this->export->refresh()->render_state['lease']);
    $this->state->begin($old->id);
    $this->travel(181)->seconds();
    $result = app(StudioAvRecovery::class)->run();
    expect($result['enqueued'])->toBe(1)->and($result['leases_released'])->toBe(1);
    expect($old->refresh()->status)->toBe('released');
    expect($this->export->refresh()->render_state['lease'])->not->toBe($old->id);
    expect($this->state->begin($old->id))->toBeNull();
    Queue::assertPushed(ProcessStudioAvPhase::class, 2);
});

test('AV recovery publishes an accepted snapshot left by a crash before enqueueing its next phase', function () {
    Storage::disk('local')->put('source.bin', 'snapshot bytes');
    $media = MediaAsset::factory()->for($this->user)->create(['kind' => 'image', 'mime' => 'image/png', 'disk' => 'local', 'path' => 'source.bin',
        'size' => 14, 'declared_size' => 14, 'status' => MediaAsset::STATUS_READY]);
    app(StudioAssets::class)->attach($this->user, $this->brand, $media->id);
    $generation = $this->export->render_state['generation'];
    $planLease = $this->state->claim($this->export->id, $generation);
    $plan = ['frameCount' => 12, 'fps' => 12, 'duration' => 1, 'sampleRate' => 48000, 'segmentFrames' => 24, 'segmentCount' => 1,
        'selection' => ['one'], 'assetIds' => [$media->id], 'fontIds' => [], 'audioAssetIds' => [],
        'render' => ['width' => 100, 'height' => 80, 'paddedWidth' => 100, 'paddedHeight' => 80, 'scale' => 1, 'quality' => 0.94]];
    $this->state->accept($planLease, $plan);
    $source = $this->state->claim($this->export->id, $generation, 14);
    $files = app(StudioAvFiles::class);
    $snapshot = $files->snapshot($source);
    $this->state->accept($source, $snapshot, $snapshot['size']);
    // Crash boundary: no publishSource call happened after the accepted checkpoint.
    $root = 'studio-exports/'.$this->user->id.'/'.$this->export->id.'/'.$this->export->render_state['workspace'];
    Storage::disk('local')->assertMissing($root.'/assets/'.$media->id.'.bin');
    $this->travel(181)->seconds();
    expect(app(StudioAvRecovery::class)->run()['enqueued'])->toBe(1);
    expect(Storage::disk('local')->get($root.'/assets/'.$media->id.'.bin'))->toBe('snapshot bytes');
});

test('AV pending promotion lookup starts with unpublished leases and uses indexed generation membership', function () {
    DB::enableQueryLog();
    try {
        $this->state->checkpoints($this->export, ['asset', 'font'], true);
        $query = collect(DB::getQueryLog())->first(fn ($q) => str_contains($q['query'], 'input_published'));
        $plan = DB::select('EXPLAIN QUERY PLAN '.$query['query'], $query['bindings']);
        expect($plan[0]->detail)->toContain('workspace_id=? AND category=? AND status=? AND input_published=?');
        $details = implode(' ', array_map(fn ($row) => $row->detail, $plan));
        expect($details)->toContain('generation=? AND result_lease_id=?');
    } finally {
        DB::disableQueryLog();
        DB::flushQueryLog();
    }
});

test('AV recovery cleans expired partials from failed or cancelled exports while preserving their status', function (string $status) {
    app(StudioAvQueue::class)->enqueue($this->export->id);
    $lease = StudioAvLease::findOrFail($this->export->refresh()->render_state['lease']);
    $root = 'studio-exports/'.$this->user->id.'/'.$this->export->id.'/'.$this->export->render_state['workspace'];
    $partial = $root.'/audio-sources/'.$lease->id.'/partial.pcm';
    Storage::disk('local')->put($partial, 'unaccepted partial');
    if ($status === 'failed') {
        $this->state->fail($lease, 'phase_failed');
    } else {
        $this->state->cancel($this->user, $this->export);
    }
    $this->travel(181)->seconds();
    $result = app(StudioAvRecovery::class)->run();
    expect($result['leases_released'])->toBe(1)->and($result['enqueued'])->toBe(0);
    expect($lease->refresh()->status)->toBe('released')->and($this->export->refresh()->status)->toBe($status);
    Storage::disk('local')->assertMissing($partial);
})->with(['failed', 'cancelled']);

test('AV recovery does not duplicate a database message while its worker is stopped', function () {
    Queue::swap($this->realQueue);
    app(StudioAvQueue::class)->enqueue($this->export->id);
    $old = StudioAvLease::findOrFail($this->export->refresh()->render_state['lease']);
    $this->travel(181)->seconds();
    $result = app(StudioAvRecovery::class)->run();
    expect($result['enqueued'])->toBe(0)->and($result['leases_released'])->toBe(0);
    expect($old->refresh()->status)->toBe('running')->and($this->export->refresh()->render_state['lease'])->toBe($old->id);
});

test('AV busy work is deferred without losing accounting and can be recovered after the mutex is released', function () {
    app(StudioAvQueue::class)->enqueue($this->export->id);
    $old = StudioAvLease::findOrFail($this->export->refresh()->render_state['lease']);
    app(StudioAvStorage::class)->locked($old->workspace_id, function () use ($old) {
        expect(app(StudioAvRunner::class)->run($old->id))->toBeFalse();
    });
    expect($this->export->refresh()->status)->toBe('processing')->and($this->export->render_state['lease'])->toBeNull();
    expect($old->refresh()->status)->toBe('abandoned');
    $result = app(StudioAvRecovery::class)->run();
    expect($result['enqueued'])->toBe(1)->and($old->refresh()->status)->toBe('released');
});

test('AV recovery removes expired orphan journals after their brand is deleted', function () {
    $workspaceId = $this->export->render_state['allocation_id'];
    $this->brand->delete();
    $this->travel(181)->seconds();
    expect(app(StudioAvRecovery::class)->run()['orphans_removed'])->toBe(1);
    expect(StudioAvWorkspace::withoutUserScope()->find($workspaceId))->toBeNull();
});

test('AV bounded recovery does not starve another brand behind a worker-down export', function () {
    Queue::swap($this->realQueue);
    app(StudioAvQueue::class)->enqueue($this->export->id);
    $brand = Brand::factory()->for($this->user)->create();
    $design = app(StudioService::class)->create($this->user, $brand, ['name' => 'Other brand', 'document' => [
        'schema_version' => 2, 'width' => 100, 'height' => 80, 'timeline' => ['fps' => 12, 'audio_tracks' => []],
        'pages' => [['id' => 'one', 'duration' => 1, 'background' => '#ffffff', 'elements' => []]],
    ]]);
    $other = DesignExport::create(['user_id' => $this->user->id, 'brand_id' => $brand->id, 'design_id' => $design->id,
        'revision' => 1, 'format' => 'mp4', 'pages' => ['one'], 'options' => ['scale' => 1, 'quality' => 0.94], 'status' => 'pending']);
    $other = $this->state->initialize($this->user, $other);
    $this->travel(181)->seconds();
    expect(app(StudioAvRecovery::class)->run(1)['enqueued'])->toBe(0);
    expect(app(StudioAvRecovery::class)->run(1)['enqueued'])->toBe(1);
    expect($other->refresh()->render_state['lease'])->not->toBeNull();
});

test('AV recovery command runs a bounded pass and is scheduled without overlapping existing maintenance', function () {
    app(StudioAvQueue::class)->enqueue($this->export->id);
    $old = StudioAvLease::findOrFail($this->export->refresh()->render_state['lease']);
    $this->state->begin($old->id);
    $this->travel(181)->seconds();
    expect(Artisan::call('studio:recover', ['--limit' => 1]))->toBe(0);
    expect(json_decode(Artisan::output(), true)['enqueued'] ?? null)->toBe(1);
    $event = collect(app(Schedule::class)->events())->first(fn ($event) => str_contains($event->command ?? '', 'studio:recover'));
    expect($event)->not->toBeNull();
    expect($event->expression)->toBe('* * * * *')->and($event->withoutOverlapping)->toBeTrue();
    expect(Artisan::call('studio:recover', ['--limit' => 1000]))->toBe(2);
});

test('AV actual database worker processes native deliveries through assembly and durable media registration', function (string $format) {
    Queue::swap($this->realQueue);
    $export = DesignExport::create($this->export->only(['user_id', 'brand_id', 'design_id', 'revision', 'pages', 'options']) + ['format' => $format, 'status' => 'pending']);
    $export = $this->state->initialize($this->user, $export);
    expect(app(StudioAvQueue::class)->enqueue($export->id))->toBeTrue();
    foreach (['plan', 'render', 'assemble'] as $phase) {
        $lease = StudioAvLease::findOrFail($export->refresh()->render_state['lease']);
        expect($lease->phase)->toBe($phase);
        $exit = Artisan::call('queue:work', ['connection' => 'database', '--queue' => 'studio', '--once' => true, '--tries' => 1, '--timeout' => 100, '--sleep' => 0]);
        expect($exit)->toBe(0)->and($lease->refresh()->status)->toBe('accepted', $export->refresh()->error ?? '');
        expect($lease->started_at)->not->toBeNull()->and($lease->result['runtime_memory']['limit'])->toBeGreaterThan(0)->toBeLessThanOrEqual(3 * 1024 ** 3);
    }
    expect($this->state->task($export->refresh()))->toBe(['phase' => 'register', 'index' => 0]);
    expect($export->status)->toBe('processing')->and($export->render_state['lease'])->not->toBeNull()->and($export->media_ids)->toBeNull();
    for ($pass = 0; $pass < 8 && $export->refresh()->status === 'processing'; $pass++) {
        expect(StudioAvLease::findOrFail($export->render_state['lease'])->phase)->toBe('register');
        Artisan::call('queue:work', ['connection' => 'database', '--queue' => 'studio', '--once' => true, '--tries' => 1, '--timeout' => 100, '--sleep' => 0]);
    }
    expect($export->refresh()->status)->toBe('ready', $export->error ?? '')->and($export->media_ids)->toHaveCount(1);
    expect(MediaAsset::withoutUserScope()->findOrFail($export->media_ids[0])->isReady())->toBeTrue();
})->with(['mp4', 'gif'])->skip(fn () => getenv('STUDIO_AV_NATIVE_TEST') !== '1', 'Explicit managed native runtime opt-in; no ONNX.');

test('AV database worker snapshots and mixes a private real tone for MP4 while GIF does not copy unused music', function (string $format) {
    Queue::swap($this->realQueue);
    $samples = '';
    for ($n = 0; $n < 48000; $n++) {
        $value = pack('g', 0.125 * sin(2 * M_PI * 440 * $n / 48000));
        $samples .= $value.$value;
    }
    $wav = 'RIFF'.pack('V', 36 + strlen($samples)).'WAVEfmt '.pack('VvvVVvv', 16, 3, 2, 48000, 384000, 8, 32).'data'.pack('V', strlen($samples)).$samples;
    Storage::disk('local')->put('tone.wav', $wav);
    $media = MediaAsset::factory()->for($this->user)->create(['kind' => 'audio', 'mime' => 'audio/wav', 'disk' => 'local', 'path' => 'tone.wav',
        'size' => strlen($wav), 'declared_size' => strlen($wav), 'duration' => 1, 'audio_codec' => 'pcm_f32le', 'status' => MediaAsset::STATUS_READY]);
    app(StudioAssets::class)->attach($this->user, $this->brand, $media->id);
    $document = ['schema_version' => 2, 'width' => 100, 'height' => 80,
        'timeline' => ['fps' => 12, 'audio_tracks' => [['id' => 'music', 'asset_id' => $media->id, 'start' => 0, 'duration' => 1]]],
        'pages' => [['id' => 'one', 'duration' => 1, 'background' => '#ff0000', 'elements' => []]]];
    $design = app(StudioService::class)->create($this->user, $this->brand, ['name' => 'Tone', 'document' => $document]);
    $export = DesignExport::create(['user_id' => $this->user->id, 'brand_id' => $this->brand->id, 'design_id' => $design->id,
        'revision' => 1, 'format' => $format, 'pages' => ['one'], 'options' => ['scale' => 1, 'quality' => 0.94], 'status' => 'pending']);
    $export = $this->state->initialize($this->user, $export);
    app(StudioAvQueue::class)->enqueue($export->id);
    foreach ($format === 'mp4' ? ['plan', 'asset', 'normalize', 'render', 'mix', 'assemble'] : ['plan', 'render', 'assemble'] as $phase) {
        $lease = StudioAvLease::findOrFail($export->refresh()->render_state['lease']);
        expect($lease->phase)->toBe($phase);
        Artisan::call('queue:work', ['connection' => 'database', '--queue' => 'studio', '--once' => true, '--tries' => 1, '--timeout' => 100, '--sleep' => 0]);
        expect($lease->refresh()->status)->toBe('accepted', $export->refresh()->error ?? '');
    }
    $final = $this->state->checkpoints($export->refresh(), ['assemble'])->first()->result;
    expect($final['frameCount'])->toBe(12)->and($final['audioCodec'])->toBe($format === 'mp4' ? 'aac' : null);
    $root = 'studio-exports/'.$this->user->id.'/'.$export->id.'/'.$export->render_state['workspace'];
    expect(Storage::disk('local')->allFiles($root.'/work'))->toBe([]);
    if ($format === 'mp4') {
        $decode = new Symfony\Component\Process\Process(['ffmpeg', '-v', 'error', '-i', Storage::disk('local')->path($root.'/'.$final['file']), '-vn', '-ac', '1', '-ar', '48000', '-f', 'f32le', 'pipe:1']);
        $decode->mustRun();
        $values = array_values(unpack('g*', $decode->getOutput()));
        $real = 0;
        $imaginary = 0;
        for ($n = 4800; $n < 43200; $n++) {
            $real += $values[$n] * cos(2 * M_PI * 440 * $n / 48000);
            $imaginary += $values[$n] * sin(2 * M_PI * 440 * $n / 48000);
        }
        expect(hypot($real, $imaginary) * 2 / 38400)->toBeGreaterThan(0.07);
    } else {
        expect(Storage::disk('local')->allFiles($root.'/imports'))->toBe([]);
    }
})->with(['mp4', 'gif'])->skip(fn () => getenv('STUDIO_AV_NATIVE_TEST') !== '1', 'Explicit managed native runtime opt-in; no ONNX.');
