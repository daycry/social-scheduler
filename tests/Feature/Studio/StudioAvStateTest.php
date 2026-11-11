<?php

use App\Models\Brand;
use App\Models\MediaAsset;
use App\Models\Studio\DesignExport;
use App\Models\Studio\DesignRevision;
use App\Models\Studio\StudioAvStep;
use App\Models\Studio\StudioAvWorkspace;
use App\Models\User;
use App\Services\Studio\StudioAssets;
use App\Services\Studio\StudioAvState;
use App\Services\Studio\StudioExports;
use App\Services\Studio\StudioService;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpException;

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->brand = Brand::factory()->for($this->user)->create();
    $document = ['schema_version' => 2, 'width' => 100, 'height' => 80, 'timeline' => ['fps' => 12, 'audio_tracks' => []],
        'pages' => [['id' => 'page_one', 'duration' => 2, 'background' => '#ffffff', 'elements' => []]]];
    $this->design = app(StudioService::class)->create($this->user, $this->brand, ['name' => 'AV state', 'document' => $document]);
    $this->export = DesignExport::create(['user_id' => $this->user->id, 'brand_id' => $this->brand->id, 'design_id' => $this->design->id,
        'revision' => 1, 'format' => 'mp4', 'pages' => ['page_one'], 'options' => ['scale' => 1, 'quality' => 0.94], 'status' => 'pending']);
});

test('AV initialization pins a private immutable workspace and is idempotent', function () {
    $state = app(StudioAvState::class);
    $first = $state->initialize($this->user, $this->export);
    $snapshot = $first->render_state;
    expect($snapshot)->toBeArray();
    expect(Str::isUuid($snapshot['generation']))->toBeTrue()
        ->and(Str::isUuid($snapshot['workspace']))->toBeTrue()
        ->and($snapshot['fingerprint'])->toMatch('/^[a-f0-9]{64}$/')
        ->and($snapshot['position'])->toBe(0)
        ->and($snapshot['total'])->toBe(1)
        ->and($snapshot['lease'])->toBeNull();
    expect($state->task($first))->toBe(['phase' => 'plan', 'index' => 0]);
    expect($state->initialize($this->user, $first)->render_state)->toBe($snapshot);
    expect($first->toArray())->not->toHaveKey('render_state');
});

test('AV initialization cannot take over another owner or a mismatched design brand', function () {
    $other = User::factory()->create();
    expect(fn () => app(StudioAvState::class)->initialize($other, $this->export))->toThrow(ModelNotFoundException::class);
    $another = Brand::factory()->for($this->user)->create();
    $this->export->update(['brand_id' => $another->id]);
    expect(fn () => app(StudioAvState::class)->initialize($this->user, $this->export))->toThrow(ModelNotFoundException::class);
});

function studioStatePlan(): array
{
    return ['frameCount' => 24, 'fps' => 12, 'duration' => 2, 'sampleRate' => 48000, 'segmentFrames' => 12, 'segmentCount' => 2,
        'selection' => ['page_one'], 'assetIds' => [], 'fontIds' => [], 'audioAssetIds' => [],
        'render' => ['width' => 100, 'height' => 80, 'paddedWidth' => 100, 'paddedHeight' => 80, 'scale' => 1, 'quality' => 0.94]];
}

test('AV leases fence duplicate deliveries and expired workers before accepting progress', function () {
    $manager = app(StudioAvState::class);
    $export = $manager->initialize($this->user, $this->export);
    $generation = $export->render_state['generation'];
    $first = $manager->claim($export->id, $generation);
    expect($first)->not->toBeNull();
    expect(Str::isUuid($first->id))->toBeTrue()->and($first->phase)->toBe('plan')->and($first->item)->toBe(0);
    expect($manager->claim($export->id, $generation))->toBeNull();
    $this->travel(181)->seconds();
    $second = $manager->claim($export->id, $generation);
    expect($second)->not->toBeNull();
    expect($second->id)->not->toBe($first->id)->and($first->refresh()->status)->toBe('abandoned');
    expect($manager->accept($first, studioStatePlan()))->toBeFalse();
    expect($manager->accept($second, studioStatePlan()))->toBeTrue();
    expect($manager->accept($second, studioStatePlan()))->toBeFalse();
    $current = $export->refresh();
    expect($current->render_state['position'])->toBe(1)->and($current->status)->toBe('processing');
    $steps = StudioAvStep::where('workspace_id', $current->render_state['allocation_id'])->where('generation', $generation)->orderBy('position')->get();
    expect($steps->map(fn ($step) => $step->descriptor())->all())->toBe([
        ['phase' => 'plan', 'index' => 0], ['phase' => 'render', 'index' => 0], ['phase' => 'render', 'index' => 1],
        ['phase' => 'assemble', 'index' => 0], ['phase' => 'register', 'index' => 0],
    ]);
});

test('AV cancellation and retry keep the revision but reject all replies from the old generation', function () {
    $manager = app(StudioAvState::class);
    $export = $manager->initialize($this->user, $this->export);
    $original = $export->render_state;
    $lease = $manager->claim($export->id, $original['generation']);
    expect($manager->cancel($this->user, $export)->status)->toBe('cancelled');
    expect($manager->cancel($this->user, $export)->status)->toBe('cancelled');
    expect($manager->accept($lease, studioStatePlan()))->toBeFalse();
    $retry = $manager->retry($this->user, $export);
    expect($retry->status)->toBe('pending')->and($retry->revision)->toBe(1)
        ->and($retry->render_state['generation'])->not->toBe($original['generation'])
        ->and($retry->render_state['workspace'])->toBe($original['workspace'])
        ->and($retry->render_state['fingerprint'])->toBe($original['fingerprint']);
    expect($manager->claim($export->id, $original['generation']))->toBeNull();
    expect($manager->claim($export->id, $retry->render_state['generation']))->not->toBeNull();
});

test('AV disk reservations include accepted bytes and refuse oversubscription before allocating a lease', function () {
    config(['studio.av.disk.segment' => 10]);
    $manager = app(StudioAvState::class);
    $export = $manager->initialize($this->user, $this->export);
    $generation = $export->render_state['generation'];
    $lease = $manager->claim($export->id, $generation);
    expect($manager->accept($lease, studioStatePlan()))->toBeTrue();
    $segment = $manager->claim($export->id, $generation, 7);
    expect($segment)->not->toBeNull();
    expect($manager->accept($segment, ['file' => 'segments/'.$segment->id.'/video-000000.mp4', 'namespace' => $segment->id,
        'size' => 7, 'sha256' => str_repeat('a', 64)], 7))->toBeTrue();
    expect(fn () => $manager->claim($export->id, $generation, 4))->toThrow(ValidationException::class);
    $last = $manager->claim($export->id, $generation, 3);
    expect($last)->not->toBeNull();
    expect($last->reserved_bytes)->toBe(3)->and($last->category)->toBe('segment');
});

test('AV orphan allocation remains traceable after deleting the brand and prevents further claims', function () {
    $manager = app(StudioAvState::class);
    $export = $manager->initialize($this->user, $this->export);
    $workspace = $export->render_state['workspace'];
    $this->brand->delete();
    expect(DesignExport::withoutUserScope()->find($export->id))->toBeNull();
    $allocation = StudioAvWorkspace::withoutUserScope()->where('uuid', $workspace)->firstOrFail();
    expect($allocation->user_id)->toBe($this->user->id)->and($allocation->export_id)->toBe($export->id);
    expect($manager->claim($export->id, $export->render_state['generation']))->toBeNull();
});

test('AV retry schedules verification of accepted checkpoints before continuing without reallocating their bytes', function () {
    $manager = app(StudioAvState::class);
    $export = $manager->initialize($this->user, $this->export);
    $generation = $export->render_state['generation'];
    $manager->accept($manager->claim($export->id, $generation), studioStatePlan());
    $segment = $manager->claim($export->id, $generation, 7);
    $result = ['file' => 'segments/'.$segment->id.'/video-000000.mp4', 'namespace' => $segment->id, 'size' => 7, 'sha256' => str_repeat('a', 64)];
    $manager->accept($segment, $result, 7);
    $manager->cancel($this->user, $export);
    $retry = $manager->retry($this->user, $export);
    $next = $retry->render_state['generation'];
    $manager->accept($manager->claim($export->id, $next), studioStatePlan());
    $task = $manager->task($export->refresh());
    expect($task['phase'])->toBe('verify')->and($task['source'])->toBe($segment->id)
        ->and($task['target_phase'])->toBe('render')->and($task['target_index'])->toBe(0);
    $verify = $manager->claim($export->id, $next);
    expect($verify->reserved_bytes)->toBe(0);
    // JSON object key order may change in storage; the checkpoint's typed values must not.
    expect($manager->accept($verify, array_reverse($result, true)))->toBeTrue();
    expect($manager->checkpoints($export->refresh(), ['render'])->get('render:0')->id)->toBe($segment->id);
    expect($export->render_state['position'])->toBe(2);
});

test('AV failure stores a safe actionable error and cannot overwrite a newer generation', function () {
    $manager = app(StudioAvState::class);
    $export = $manager->initialize($this->user, $this->export);
    $lease = $manager->claim($export->id, $export->render_state['generation']);
    expect($manager->fail($lease, 'phase_timeout'))->toBeTrue();
    expect($export->refresh()->status)->toBe('failed')->and($export->error)->toContain('tiempo');
    $retry = $manager->retry($this->user, $export);
    expect($manager->fail($lease, 'private C:/credentials must not leak'))->toBeFalse();
    expect($export->refresh()->status)->toBe('pending')->and($export->error)->toBeNull();
    $current = $manager->claim($export->id, $retry->render_state['generation']);
    expect($manager->fail($current, 'private C:/credentials must not leak'))->toBeTrue();
    expect($export->refresh()->error)->not->toContain('private', 'credentials', 'C:/');
});

test('AV retry rejects a changed pinned revision instead of reusing incompatible checkpoints', function () {
    $manager = app(StudioAvState::class);
    $export = $manager->initialize($this->user, $this->export);
    $manager->cancel($this->user, $export);
    $revision = DesignRevision::withoutUserScope()->where('design_id', $this->design->id)->firstOrFail();
    $document = $revision->document;
    $document['pages'][0]['background'] = '#ff0000';
    $revision->update(['document' => $document]);
    expect(fn () => $manager->retry($this->user, $export))->toThrow(ValidationException::class);
    expect($export->refresh()->status)->toBe('cancelled');
});

test('AV ready results reject cancel and retry while preserving media and private state', function () {
    $manager = app(StudioAvState::class);
    $export = $manager->initialize($this->user, $this->export);
    $export->update(['status' => 'ready', 'media_ids' => [991]]);
    expect(fn () => $manager->cancel($this->user, $export))->toThrow(HttpException::class);
    expect(fn () => $manager->retry($this->user, $export))->toThrow(HttpException::class);
    expect($export->refresh()->status)->toBe('ready')->and($export->media_ids)->toBe([991]);
    expect($export->toArray())->not->toHaveKey('render_state');
});

test('AV public progress exposes accepted work without private state in history queries', function () {
    $manager = app(StudioAvState::class);
    $export = $manager->initialize($this->user, $this->export);
    $exports = app(StudioExports::class);
    $public = $exports->present($this->user, $export);
    expect($public)->toHaveKey('progress');
    expect($public['progress'])->toBe(['phase' => 'plan', 'completed' => 0, 'total' => 1, 'percent' => 0]);
    expect($public['can_cancel'])->toBeTrue()->and($public['can_retry'])->toBeFalse();
    $manager->accept($manager->claim($export->id, $export->render_state['generation']), studioStatePlan());
    $page = $exports->paginated($this->user, $this->design);
    expect($page->first()->getAttributes())->not->toHaveKey('render_state');
    $public = $exports->presentMany($this->user, $page->getCollection())[0];
    expect($public['progress'])->toBe(['phase' => 'render', 'completed' => 1, 'total' => 5, 'percent' => 20]);
    expect(json_encode($public))->not->toContain('fingerprint', $export->render_state['workspace'], $export->render_state['generation']);
    $manager->cancel($this->user, $export);
    $cancelled = $exports->present($this->user, $export);
    expect($cancelled['progress']['phase'])->toBe('cancelled')->and($cancelled['can_cancel'])->toBeFalse()->and($cancelled['can_retry'])->toBeTrue();
});

test('AV advancement state stays bounded for a complete 4096 segment project', function (bool $music) {
    $manager = app(StudioAvState::class);
    $pages = [];
    foreach (['a' => 2730, 'b' => 2730, 'c' => 2732] as $id => $duration) {
        $pages[] = ['id' => $id, 'duration' => $duration, 'background' => '#ffffff', 'elements' => []];
    }
    $tracks = [];
    if ($music) {
        $audio = MediaAsset::factory()->for($this->user)->create(['kind' => 'audio', 'mime' => 'audio/wav', 'audio_codec' => 'pcm_f32le', 'duration' => 1, 'width' => null, 'height' => null]);
        app(StudioAssets::class)->attach($this->user, $this->brand, $audio->id);
        $tracks[] = ['id' => 'music', 'asset_id' => $audio->id, 'start' => 0, 'duration' => 8192, 'loop' => true];
    }
    $design = app(StudioService::class)->create($this->user, $this->brand, ['name' => 'Long project', 'document' => [
        'schema_version' => 2, 'width' => 100, 'height' => 80, 'timeline' => ['fps' => 12, 'audio_tracks' => $tracks], 'pages' => $pages,
    ]]);
    $export = DesignExport::create(['user_id' => $this->user->id, 'brand_id' => $this->brand->id, 'design_id' => $design->id,
        'revision' => 1, 'format' => 'mp4', 'pages' => ['a', 'b', 'c'], 'options' => ['scale' => 1, 'quality' => 0.94], 'status' => 'pending']);
    $export = $manager->initialize($this->user, $export);
    $plan = studioStatePlan();
    $plan['frameCount'] = 4096 * 24;
    $plan['duration'] = 8192;
    $plan['segmentFrames'] = 24;
    $plan['segmentCount'] = 4096;
    $plan['selection'] = ['a', 'b', 'c'];
    if ($music) {
        $plan['assetIds'] = [$audio->id];
        $plan['audioAssetIds'] = [$audio->id];
    }
    $manager->accept($manager->claim($export->id, $export->render_state['generation']), $plan);
    $export->refresh();
    expect(strlen(json_encode($export->render_state)))->toBeLessThan(2000);
    expect($export->render_progress['total'])->toBe($music ? 8197 : 4099)->and($export->render_progress['completed'])->toBe(1);
    $render = $manager->claim($export->id, $export->render_state['generation'], 1);
    expect($render->phase)->toBe($music ? 'asset' : 'render')->and($render->item)->toBe($music ? $audio->id : 0);
    expect(strlen(json_encode($export->refresh()->render_state)))->toBeLessThan(2000);
})->with(['silent' => false, 'one track' => true]);

test('AV repeated retries retain accepted verification candidates even before the next plan runs', function () {
    $manager = app(StudioAvState::class);
    $export = $manager->initialize($this->user, $this->export);
    $generation = $export->render_state['generation'];
    $manager->accept($manager->claim($export->id, $generation), studioStatePlan());
    $segment = $manager->claim($export->id, $generation, 7);
    $result = ['file' => 'segments/'.$segment->id.'/video-000000.mp4', 'namespace' => $segment->id, 'size' => 7, 'sha256' => str_repeat('a', 64)];
    $manager->accept($segment, $result, 7);
    $manager->cancel($this->user, $export);
    $manager->retry($this->user, $export);
    $manager->cancel($this->user, $export); // No plan or verification has run in this generation.
    $retry = $manager->retry($this->user, $export);
    $manager->accept($manager->claim($export->id, $retry->render_state['generation']), studioStatePlan());
    $task = $manager->task($export->refresh());
    expect($task['phase'])->toBe('verify')->and($task['source'])->toBe($segment->id);
    $manager->cancel($this->user, $export); // This plan has pending verification, which must also survive retry.
    $retry = $manager->retry($this->user, $export);
    $manager->accept($manager->claim($export->id, $retry->render_state['generation']), studioStatePlan());
    expect($manager->task($export->refresh())['source'])->toBe($segment->id);
});
