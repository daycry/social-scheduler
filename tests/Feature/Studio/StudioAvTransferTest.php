<?php

use App\Models\Brand;
use App\Models\MediaAsset;
use App\Models\Studio\DesignExport;
use App\Models\Studio\StudioAvWorkspace;
use App\Models\User;
use App\Services\Studio\StudioAvCheckpointCorrupt;
use App\Services\Studio\StudioAvProcessFailed;
use App\Services\Studio\StudioAvState;
use App\Services\Studio\StudioAvTransfer;
use App\Services\Studio\StudioService;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\Process\Process;
use Tests\Support\StudioAvR2;

beforeEach(function () {
    Storage::fake('local');
    config(['filesystems.media' => 'local']);
    $this->user = User::factory()->create();
    $this->brand = Brand::factory()->for($this->user)->create();
    $this->state = app(StudioAvState::class);
    $this->prepare = function (string $bytes) {
        $design = app(StudioService::class)->create($this->user, $this->brand, ['name' => 'Transfer', 'document' => [
            'schema_version' => 2, 'width' => 100, 'height' => 80, 'timeline' => ['fps' => 12, 'audio_tracks' => []],
            'pages' => [['id' => 'one', 'duration' => 1, 'background' => '#ffffff', 'elements' => []]]]]);
        $export = DesignExport::create(['user_id' => $this->user->id, 'brand_id' => $this->brand->id, 'design_id' => $design->id,
            'revision' => 1, 'format' => 'mp4', 'pages' => ['one'], 'options' => ['scale' => 1, 'quality' => 0.94], 'status' => 'pending']);
        $this->export = $this->state->initialize($this->user, $export);
        $generation = $this->export->render_state['generation'];
        $this->state->accept($this->state->claim($export->id, $generation), [
            'frameCount' => 12, 'fps' => 12, 'duration' => 1, 'sampleRate' => 48000, 'segmentFrames' => 24, 'segmentCount' => 1,
            'selection' => ['one'], 'assetIds' => [], 'fontIds' => [], 'audioAssetIds' => [],
            'render' => ['width' => 100, 'height' => 80, 'paddedWidth' => 100, 'paddedHeight' => 80, 'scale' => 1, 'quality' => 0.94]]);
        $render = $this->state->claim($export->id, $generation, 1);
        $this->state->accept($render, ['file' => 'segments/'.$render->id.'/video-000000.mp4', 'namespace' => $render->id,
            'size' => 1, 'sha256' => hash('sha256', 'x')], 1);
        $this->source = $this->state->claim($export->id, $generation, strlen($bytes));
        $this->result = ['file' => 'outputs/'.$this->source->id.'/project.mp4', 'namespace' => $this->source->id,
            'size' => strlen($bytes), 'sha256' => hash('sha256', $bytes), 'width' => 100, 'height' => 80, 'duration' => 1,
            'fps' => 12, 'frameCount' => 12, 'videoCodec' => 'h264', 'audioCodec' => null, 'scale' => 1, 'quality' => 0.94,
            'chunk_size' => 16 * 1024 ** 2, 'chunk_sha256' => array_map(fn ($part) => hash('sha256', $part), str_split($bytes, 16 * 1024 ** 2))];
        $this->state->accept($this->source, $this->result, strlen($bytes));
        $this->root = 'studio-exports/'.$this->user->id.'/'.$export->id.'/'.$this->export->render_state['workspace'];
        Storage::disk('local')->put($this->root.'/'.$this->result['file'], $bytes);
        $this->lease = $this->state->claim($export->id, $generation);
    };
    $this->finish = function (int $passes = 12) {
        $result = null;
        for ($i = 0; $i < $passes && $result === null; $i++) {
            $result = app(StudioAvTransfer::class)->run($this->lease);
        }

        return $result;
    };
});

test('AV local transfer publishes a separately durable verified copy before accepting the medium', function () {
    ($this->prepare)('rendered bytes');
    $result = ($this->finish)();
    expect($result)->toHaveKey('media_id');
    $media = MediaAsset::withoutUserScope()->findOrFail($result['media_id']);
    expect($media->status)->toBe(MediaAsset::STATUS_PENDING)->and($media->import_state['verified_bytes'])->toBe(14);
    expect(Storage::disk('local')->get($media->path))->toBe('rendered bytes');
    expect($this->state->accept($this->lease, $result))->toBeTrue();
    expect(StudioAvWorkspace::withoutUserScope()->findOrFail($this->lease->workspace_id)->media_reserved_bytes)->toBe(0);
    Storage::disk('local')->deleteDirectory($this->root);
    expect(Storage::disk('local')->get($media->path))->toBe('rendered bytes')->and($media->refresh()->isReady())->toBeTrue();
});

test('AV local transfer resumes one 16MiB part per call without copying or exposing a second medium', function () {
    $first = str_repeat('a', 16 * 1024 ** 2);
    ($this->prepare)($first.'tail');
    expect(app(StudioAvTransfer::class)->run($this->lease))->toBeNull();
    $media = MediaAsset::withoutUserScope()->sole();
    expect($media->import_state['bytes'])->toBe(strlen($first))->and($media->isReady())->toBeFalse();
    $this->state->defer($this->lease);
    $this->lease = $this->state->claim($this->export->id, $this->export->render_state['generation']);
    $result = ($this->finish)();
    expect($result)->toBe(['media_id' => $media->id])->and(MediaAsset::withoutUserScope()->count())->toBe(1);
    expect(hash_file('sha256', Storage::disk('local')->path($media->path)))->toBe($this->result['sha256']);
});

test('AV transfer rejects source bytes that do not match their native part proof', function () {
    ($this->prepare)('rendered bytes');
    Storage::disk('local')->put($this->root.'/'.$this->result['file'], 'tampered bytes');
    expect(fn () => app(StudioAvTransfer::class)->run($this->lease))->toThrow(StudioAvCheckpointCorrupt::class);
    expect(MediaAsset::withoutUserScope()->sole()->isReady())->toBeFalse();
});

test('AV transfer refuses an exhausted deadline before allocating a copy', function () {
    ($this->prepare)('rendered bytes');
    expect(fn () => app(StudioAvTransfer::class)->run($this->lease, microtime(true) - 1))->toThrow(StudioAvProcessFailed::class);
    expect(MediaAsset::withoutUserScope()->count())->toBe(0);
});

test('AV R2 transfer resumes bounded multipart and verifies actual ranged bytes on the pinned disk', function () {
    ($this->prepare)(str_repeat('b', 16 * 1024 ** 2).'tail');
    $r2 = new StudioAvR2;
    expect(app(StudioAvTransfer::class)->run($this->lease))->toBeNull();
    $media = MediaAsset::withoutUserScope()->sole();
    config(['filesystems.media' => 'local']);
    $result = ($this->finish)();
    expect($result)->toBe(['media_id' => $media->id])->and($media->refresh()->import_state['complete'])->toBeTrue()
        ->and($media->status)->toBe(MediaAsset::STATUS_PENDING)->and($media->disk)->toBe('studio-transfer-r2');
    expect(hash('sha256', $r2->objects['tenant/'.$media->path]['bytes']))->toBe($this->result['sha256']);
    $calls = collect($r2->calls->getArrayCopy());
    expect($calls->where('name', 'CreateMultipartUpload'))->toHaveCount(1)
        ->and($calls->where('name', 'UploadPart'))->toHaveCount(2)->and($calls->where('name', 'GetObject'))->toHaveCount(2);
    foreach ($calls as $call) {
        expect($call['args']['@retries'])->toBe(0)->and($call['args']['@http']['timeout'])->toBeGreaterThan(0)->toBeLessThanOrEqual(20);
        if (isset($call['args']['Key'])) {
            expect($call['args']['Key'])->toBe('tenant/'.$media->path);
        }
    }
});

test('AV R2 transfer recovers a lost storage acknowledgement without duplicating allocation or upload', function (string $operation) {
    ($this->prepare)('rendered bytes');
    $r2 = new StudioAvR2;
    $r2->after = function ($name) use ($operation, $r2) {
        if ($name === $operation) {
            $r2->after = null;
            throw new RuntimeException('fixture lost acknowledgement');
        }
    };
    expect(fn () => ($this->finish)())->toThrow(RuntimeException::class, 'fixture lost acknowledgement');
    expect(($this->finish)())->toHaveKey('media_id');
    expect(MediaAsset::withoutUserScope()->count())->toBe(1)->and($r2->uploads)->toBe([]);
    expect(collect($r2->calls->getArrayCopy())->where('name', 'CreateMultipartUpload'))->toHaveCount(1);
})->with(['CreateMultipartUpload', 'CompleteMultipartUpload']);

test('AV R2 verification refuses corrupt object bytes even if metadata and size claim the expected source', function () {
    ($this->prepare)('rendered bytes');
    $r2 = new StudioAvR2;
    $r2->after = function ($name, $args) use ($r2) {
        if ($name === 'CompleteMultipartUpload') {
            $r2->objects[$args['Key']]['bytes'] = 'tampered bytes';
        }
    };
    expect(fn () => ($this->finish)())->toThrow(StudioAvCheckpointCorrupt::class);
    expect(MediaAsset::withoutUserScope()->sole()->isReady())->toBeFalse();
});

test('AV R2 transfer never creates a new multipart when its already verified object disappears', function () {
    ($this->prepare)('rendered bytes');
    $r2 = new StudioAvR2;
    $result = ($this->finish)();
    $media = MediaAsset::withoutUserScope()->findOrFail($result['media_id']);
    unset($r2->objects['tenant/'.$media->path]);
    expect(fn () => app(StudioAvTransfer::class)->run($this->lease))->toThrow(StudioAvCheckpointCorrupt::class);
    expect(collect($r2->calls->getArrayCopy())->where('name', 'CreateMultipartUpload'))->toHaveCount(1);
});

test('AV R2 verification rejects a response that ignores the requested range', function () {
    ($this->prepare)(str_repeat('b', 16 * 1024 ** 2).'tail');
    $r2 = new StudioAvR2;
    $r2->ignoreRange = true;
    expect(fn () => ($this->finish)())->toThrow(StudioAvCheckpointCorrupt::class);
    expect(MediaAsset::withoutUserScope()->sole()->import_state['verified_bytes'])->toBe(0);
});

test('AV cancellation during an R2 part cannot checkpoint or publish a late acknowledgement', function () {
    ($this->prepare)('rendered bytes');
    $r2 = new StudioAvR2;
    app(StudioAvTransfer::class)->run($this->lease);
    $r2->after = function ($name) {
        if ($name === 'UploadPart') {
            $this->state->cancel($this->user, $this->export);
        }
    };
    expect(fn () => app(StudioAvTransfer::class)->run($this->lease))->toThrow(ModelNotFoundException::class);
    $media = MediaAsset::withoutUserScope()->sole();
    expect($media->import_state['bytes'])->toBe(0)->and($media->upload_id)->not->toBeNull()->and($media->isReady())->toBeFalse();
});

test('AV local transfer discards an unacknowledged tail without duplicating its medium', function () {
    ($this->prepare)(str_repeat('a', 16 * 1024 ** 2).'tail');
    app(StudioAvTransfer::class)->run($this->lease);
    $media = MediaAsset::withoutUserScope()->sole();
    file_put_contents(Storage::disk('local')->path($media->path.'.part'), 'unacknowledged', FILE_APPEND);
    expect(($this->finish)())->toBe(['media_id' => $media->id]);
    expect(hash_file('sha256', Storage::disk('local')->path($media->path)))->toBe($this->result['sha256']);
});

test('AV transfer never checkpoints a storage acknowledgement after the caller deadline', function () {
    ($this->prepare)('rendered bytes');
    $r2 = new StudioAvR2;
    app(StudioAvTransfer::class)->run($this->lease);
    $r2->after = function ($name) {
        if ($name === 'UploadPart') {
            usleep(100000);
        }
    };
    expect(fn () => app(StudioAvTransfer::class)->run($this->lease, microtime(true) + 0.05))->toThrow(StudioAvProcessFailed::class);
    expect(MediaAsset::withoutUserScope()->sole()->import_state['bytes'])->toBe(0);
});

test('AV local transfer refuses a vanished completed copy before its medium can be accepted', function () {
    ($this->prepare)('rendered bytes');
    $result = ($this->finish)();
    $media = MediaAsset::withoutUserScope()->findOrFail($result['media_id']);
    Storage::disk('local')->delete($media->path);
    expect(fn () => app(StudioAvTransfer::class)->run($this->lease))->toThrow(StudioAvCheckpointCorrupt::class);
});

test('AV local transfer verifies actual destination bytes after copying', function () {
    ($this->prepare)('rendered bytes');
    app(StudioAvTransfer::class)->run($this->lease);
    $media = MediaAsset::withoutUserScope()->sole();
    Storage::disk('local')->put($media->path.'.part', 'tampered bytes');
    expect(fn () => ($this->finish)())->toThrow(StudioAvCheckpointCorrupt::class);
    expect($media->refresh()->import_state['verified_bytes'])->toBe(0)->and($media->isReady())->toBeFalse();
});

test('AV local registration checkpoints serialize SQLite writers and respect the caller deadline', function (bool $shortDeadline, bool $commitReader, string $mutation = 'checkpoint') {
    ($this->prepare)('rendered bytes');
    $media = $mutation === 'allocate' ? null : $this->state->allocateRegistration($this->lease);
    $originalConnection = config('database.default');
    $directory = storage_path('framework/testing/t11-register-debug-'.Str::uuid());
    mkdir($directory, 0700, true);
    $database = $directory.'/db.sqlite';
    $marker = $directory.'/writer-started';
    $target = new PDO('sqlite:'.$database);
    $target->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    // Clone only this test's disposable database, including its uncommitted fixture rows.
    $schema = DB::select("select name, sql from sqlite_master where type = 'table' and name not like 'sqlite_%'");
    foreach ($schema as $table) {
        $target->exec($table->sql);
        foreach (DB::select('select * from "'.$table->name.'"') as $row) {
            $values = (array) $row;
            $statement = $target->prepare('insert into "'.$table->name.'" values ('.implode(',', array_fill(0, count($values), '?')).')');
            $statement->execute(array_values($values));
        }
    }
    $target->exec('create table registration_contention (value integer)');
    $target->exec('insert into registration_contention values (0)');
    $target = null;
    config(['database.connections.registration_contention' => array_replace(config('database.connections.sqlite'),
        ['database' => $database, 'busy_timeout' => 5000, 'journal_mode' => 'DELETE']), 'database.default' => 'registration_contention']);
    $connection = DB::connection();
    $media = $media ? MediaAsset::withoutUserScope()->findOrFail($media->id) : null;
    $leaseBefore = DB::table('studio_av_leases')->where('id', $this->lease->id)->first();
    $busyBefore = (int) $connection->scalar('PRAGMA busy_timeout');
    $writer = null;
    $armed = true;
    $startWriter = function () use (&$writer, $database, $marker, $shortDeadline, $commitReader) {
        $writer = new Process([PHP_BINARY, '-r', <<<'PHP'
$db = new PDO('sqlite:'.$argv[1]);
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$db->exec('PRAGMA busy_timeout=5000');
if ($argv[3] === '0') file_put_contents($argv[2], 'started');
$db->beginTransaction();
if ($argv[3] === '2') {
    $db->query('select * from registration_contention')->fetchAll();
    file_put_contents($argv[2], 'locked'); usleep(400000); $db->rollBack(); exit(0);
}
$db->exec('update registration_contention set value = value + 1');
if ($argv[3] === '1') { file_put_contents($argv[2], 'locked'); usleep(400000); }
$db->commit();
PHP, $database, $marker, $commitReader ? '2' : ($shortDeadline ? '1' : '0')]);
        $writer->start();
        $limit = microtime(true) + 3;
        while (! is_file($marker) && microtime(true) < $limit) {
            usleep(1000);
            clearstatcache(true, $marker);
        }
        expect(is_file($marker))->toBeTrue();
        if (! $shortDeadline && ! $commitReader) {
            usleep(100000);
        }
    };
    $connection->beforeExecuting(function ($sql) use (&$armed, $startWriter, $shortDeadline, $commitReader, $mutation) {
        if ($mutation === 'checkpoint' && ! $shortDeadline && ! $commitReader && $armed && str_starts_with($sql, 'update "media_assets"')) {
            $armed = false;
            $startWriter();
        }
    });
    DB::listen(function ($query) use (&$armed, $startWriter, $mutation) {
        if ($mutation !== 'checkpoint' && $armed && $query->connectionName === 'registration_contention'
            && $query->connection->transactionLevel() > 0 && str_starts_with($query->sql, 'select ')) {
            $armed = false;
            $startWriter();
        }
    });
    try {
        if ($mutation === 'allocate') {
            $media = $this->state->allocateRegistration($this->lease);
        } elseif ($mutation === 'bind') {
            $location = ['driver' => 'local', 'root' => rtrim(Storage::disk('local')->path(''), '/\\')];
            $this->state->bindRegistrationLocation($this->lease, $location);
            expect(StudioAvWorkspace::withoutUserScope()->findOrFail($this->lease->workspace_id)->media_receipt['location'])->toBe($location);
        } elseif ($shortDeadline || $commitReader) {
            // Test the DB wait budget directly; the competing lock is already held.
            Storage::disk('local')->put($media->path.'.part', 'rendered bytes');
            $startWriter();
            $started = microtime(true);
            expect(fn () => $this->state->checkpointRegistration($this->lease, ['bytes' => 14, 'stage' => 'copying'], deadline: $started + 0.15))
                ->toThrow(StudioAvProcessFailed::class);
            expect(microtime(true) - $started)->toBeLessThan(0.3);
        } else {
            expect(app(StudioAvTransfer::class)->run($this->lease))->toBeNull();
        }
        expect($media->fresh()->import_state['bytes'])->toBe($mutation !== 'checkpoint' || $shortDeadline || $commitReader ? 0 : 14);
        expect($connection->transactionLevel())->toBe(0)->and($connection->getPdo()->inTransaction())->toBeFalse();
        expect((int) $connection->scalar('PRAGMA busy_timeout'))->toBe($busyBefore);
        if ($mutation === 'checkpoint') {
            expect(file_get_contents(Storage::disk('local')->path($media->path.'.part')))->toBe('rendered bytes');
        }
        expect($writer)->toBeInstanceOf(Process::class);
        expect($writer->wait())->toBe(0);
        expect((int) $connection->scalar('select value from registration_contention'))->toBe($commitReader ? 0 : 1);
        expect(DB::table('studio_av_leases')->where('id', $this->lease->id)->first())->toEqual($leaseBefore);
        expect(MediaAsset::withoutUserScope()->count())->toBe(1)->and($media->fresh()->isReady())->toBeFalse();
    } finally {
        if ($writer instanceof Process) {
            $writer->wait();
        }
        config(['database.default' => $originalConnection]);
        DB::purge('registration_contention');
    }
})->with(['concurrent session write' => [false, false], 'deadline expires waiting for writer' => [true, false],
    'deadline expires committing against reader' => [false, true], 'allocate registration' => [false, false, 'allocate'],
    'bind registration' => [false, false, 'bind']]);

test('AV registration preserves caller transaction nesting rollback and after commit callbacks', function () {
    ($this->prepare)('rendered bytes');
    $media = $this->state->allocateRegistration($this->lease);
    $connection = DB::connection();
    $level = $connection->transactionLevel();
    $callbacks = 0;
    $connection->beginTransaction();
    try {
        $connection->afterCommit(function () use (&$callbacks) {
            $callbacks++;
        });
        expect(app(StudioAvTransfer::class)->run($this->lease))->toBeNull();
        expect($connection->transactionLevel())->toBe($level + 1)->and($callbacks)->toBe(0);
        expect($media->fresh()->import_state['bytes'])->toBe(14);
    } finally {
        $connection->rollBack($level);
    }
    expect($media->fresh()->import_state['bytes'])->toBe(0)->and($callbacks)->toBe(0);
    expect(file_get_contents(Storage::disk('local')->path($media->path.'.part')))->toBe('rendered bytes');
    $connection->beginTransaction();
    try {
        $connection->afterCommit(function () use (&$callbacks) {
            $callbacks++;
        });
        expect(app(StudioAvTransfer::class)->run($this->lease))->toBeNull();
        expect($connection->transactionLevel())->toBe($level + 1)->and($callbacks)->toBe(0);
        $connection->commit();
    } finally {
        if ($connection->transactionLevel() > $level) {
            $connection->rollBack($level);
        }
    }
    // Laravel's test manager executes callbacks when the caller commits back to the test transaction.
    expect($callbacks)->toBe(1)->and($connection->transactionLevel())->toBe($level);
    expect($media->fresh()->import_state['bytes'])->toBe(14);
    $queries = [];
    $connection->beforeExecuting(function ($sql) use (&$queries) {
        $queries[] = $sql;
    });
    expect($this->state->workingExport($this->lease)->id)->toBe($this->export->id);
    expect(array_values(array_filter($queries, fn ($sql) => str_starts_with($sql, 'update '))))->toBe([]);
});
