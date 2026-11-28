<?php

use App\Jobs\GenerateReport;
use App\Jobs\SendScheduledReport;
use App\Jobs\Studio\DeleteStudioFontFile;
use App\Jobs\Studio\ProcessStudioAvPhase;
use App\Jobs\Studio\ProcessStudioImage;
use App\Jobs\Studio\RenderStudioDesign;
use App\Jobs\Studio\RenderStudioThumbnail;
use App\Jobs\Studio\RunStudioAiJob;
use App\Support\HealthCheck;
use Illuminate\Support\Facades\DB;

test('the queues used by the Studio jobs are configurable', function () {
    expect((new RenderStudioDesign(1))->queue)->toBe('studio-render')
        ->and((new RenderStudioThumbnail(1))->queue)->toBe('studio')
        ->and(config('queue.names'))->toContain('studio-render');

    config(['queue.studio_render' => 'creative', 'studio.thumbnail_queue' => 'previews', 'studio.av.queue' => 'av', 'media.import_queue' => 'imports']);

    foreach ([new RunStudioAiJob(1), new ProcessStudioImage(1), new RenderStudioDesign(1)] as $job) {
        expect($job->queue)->toBe('creative');
    }

    $av = new ProcessStudioAvPhase('lease');
    expect($av->queue)->toBe('av')->and($av->connection)->toBe('database')
        ->and((new RenderStudioThumbnail(1))->queue)->toBe('previews')
        ->and((new DeleteStudioFontFile('local', 'fonts/example.woff2'))->queue)->toBe('imports');
});

test('reports honor the configured timeout', function () {
    expect((new GenerateReport(1))->timeout)->toBe(100)
        ->and((new SendScheduledReport(1))->timeout)->toBe(100);

    config(['reports.timeout' => 105]);
    expect((new GenerateReport(1))->timeout)->toBe(105)
        ->and((new SendScheduledReport(1))->timeout)->toBe(105);
});

test('configured report timeouts stay positive and under the worker budget', function (int $configured, int $expected) {
    config(['reports.timeout' => $configured]);
    expect((new GenerateReport(1))->timeout)->toBe($expected)
        ->and((new SendScheduledReport(1))->timeout)->toBe($expected);
})->with([[200, 110], [0, 1]]);

test('Redis leaves enough room for long jobs', function () {
    $longestJob = (new RenderStudioDesign(1))->timeout;
    expect(config('queue.connections.redis.retry_after'))->toBeGreaterThanOrEqual(180)
        ->and(config('queue.connections.redis.retry_after') - $longestJob)->toBeGreaterThanOrEqual(60);
});

test('database health reports a stalled render queue omitted from legacy queue names', function () {
    $this->freezeTime();
    config(['queue.names' => ['media', 'studio']]);
    $stalledSince = now()->subSeconds(config('health.queue_max_age.studio-render') + 1)->timestamp;
    DB::table('jobs')->insert([
        'queue' => 'studio-render', 'payload' => '{}', 'attempts' => 0, 'reserved_at' => null,
        'available_at' => $stalledSince, 'created_at' => $stalledSince,
    ]);

    $result = app(HealthCheck::class)->queue();
    expect($result['warn'] ?? false)->toBeTrue()->and($result['detail'])->toContain('atrasadas=studio-render:');
});
