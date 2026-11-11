<?php

use App\Models\Brand;
use App\Models\MediaAsset;
use App\Models\Studio\StudioAsset;
use App\Models\User;
use App\Services\Studio\StudioAssets;
use App\Services\Studio\StudioDocument;
use Illuminate\Support\Facades\Storage;
use Tests\Support\McpClient;

uses(McpClient::class);

beforeEach(function () {
    Storage::fake('local');
    $this->user = User::factory()->create();
    $this->brand = Brand::factory()->for($this->user)->create();
    $this->actingAs($this->user);
    $this->video = MediaAsset::factory()->for($this->user)->create(['kind' => 'video', 'mime' => 'video/mp4', 'duration' => 12, 'audio_codec' => 'aac']);
    app(StudioAssets::class)->attach($this->user, $this->brand, $this->video->id);
    $this->audio = MediaAsset::factory()->for($this->user)->create(['kind' => 'audio', 'mime' => 'audio/wav', 'duration' => 12, 'codec' => null, 'audio_codec' => 'pcm_s16le']);
    // Ready audio fixture registered directly; upload/probe support is a later T-11 subblock.
    StudioAsset::create(['user_id' => $this->user->id, 'brand_id' => $this->brand->id, 'media_asset_id' => $this->audio->id]);
    $this->url = '/brands/'.$this->brand->id.'/studio/designs';
});

function studioTimelineScene(int $video, int $audio): array
{
    $doc = StudioDocument::blank(320, 180);
    $doc['schema_version'] = 2;
    $doc['timeline'] = ['fps' => 30, 'audio_tracks' => [['id' => 'music', 'asset_id' => $audio, 'start' => 0, 'duration' => 9,
        'trimStart' => 1, 'trimEnd' => 12, 'sourceOffset' => 0.25, 'playbackRate' => 1, 'loop' => false, 'volume' => 0.4, 'muted' => false,
        'gainPoints' => [['time' => 0, 'value' => 0], ['time' => 0.5, 'value' => 1], ['time' => 8, 'value' => 1], ['time' => 9, 'value' => 0]]]]];
    $doc['pages'][0]['transition'] = ['type' => 'fade', 'duration' => 0.5];
    $doc['pages'][0]['elements'] = [['id' => 'clip', 'type' => 'video', 'asset_id' => $video, 'x' => 0, 'y' => 0, 'width' => 320, 'height' => 180,
        'trimStart' => 1, 'trimEnd' => 5, 'timing' => ['start' => 1, 'duration' => 3], 'playbackRate' => 2, 'sourceOffset' => 0.25,
        'loop' => true, 'gainPoints' => [['time' => 0, 'value' => 1], ['time' => 3, 'value' => 0]]]];
    $doc['pages'][] = ['id' => 'second', 'name' => 'Segunda', 'background' => '#ffffff', 'duration' => 5, 'elements' => []];

    return $doc;
}

test('Studio timeline v2 persists exact temporal data and retains it in a later revision', function () {
    $doc = studioTimelineScene($this->video->id, $this->audio->id);
    $created = $this->postJson($this->url, ['name' => 'Vídeo con música', 'document' => $doc])->assertCreated()->json('design');
    expect($created['document'])->toBe($doc);
    $this->patchJson('/studio/designs/'.$created['id'], ['name' => 'Conserva timeline', 'document' => $doc, 'revision' => 1])
        ->assertOk()->assertJsonPath('design.revision', 2)->assertJsonPath('design.document', $doc);
});

test('Studio timeline rejects explicit null in nonnullable temporal media values', function (string $field) {
    $doc = studioTimelineScene($this->video->id, $this->audio->id);
    data_set($doc, $field, null);
    $this->postJson($this->url, ['name' => 'Valor nulo', 'document' => $doc])->assertUnprocessable();
})->with(['timeline.audio_tracks.0.playbackRate', 'timeline.audio_tracks.0.sourceOffset', 'timeline.audio_tracks.0.trimStart',
    'timeline.audio_tracks.0.volume', 'pages.0.elements.0.playbackRate', 'pages.0.elements.0.sourceOffset']);

test('Studio timeline accepts one-frame durations produced by subtracting decimal playhead positions', function () {
    $doc = studioTimelineScene($this->video->id, $this->audio->id);
    $doc['pages'][0]['elements'][0]['timing'] = ['start' => 0.1, 'duration' => 4 / 30 - 0.1];
    unset($doc['pages'][0]['elements'][0]['gainPoints']);
    $this->postJson($this->url, ['name' => 'Un fotograma', 'document' => $doc])->assertCreated();
});

test('Studio timeline preserves positive subframe windows created by intersecting timed groups', function () {
    $doc = studioTimelineScene($this->video->id, $this->audio->id);
    $doc['pages'][0]['elements'][0]['timing'] = ['start' => 1, 'duration' => 0.01];
    unset($doc['pages'][0]['elements'][0]['gainPoints']);
    $this->postJson($this->url, ['name' => 'Intersección de grupo', 'document' => $doc])->assertCreated();
});

test('Studio timeline rejects audio from another brand, owner or a silent video', function (string $case) {
    $doc = studioTimelineScene($this->video->id, $this->audio->id);
    if ($case === 'silent') {
        $this->video->forceFill(['audio_codec' => null])->save();
        $doc['timeline']['audio_tracks'][0]['asset_id'] = $this->video->id;
    } else {
        $owner = $case === 'owner' ? User::factory()->create() : $this->user;
        $brand = Brand::factory()->for($owner)->create();
        $audio = MediaAsset::factory()->for($owner)->create(['kind' => 'audio', 'duration' => 12, 'audio_codec' => 'aac']);
        StudioAsset::create(['user_id' => $owner->id, 'brand_id' => $brand->id, 'media_asset_id' => $audio->id]);
        $doc['timeline']['audio_tracks'][0]['asset_id'] = $audio->id;
    }
    $this->postJson($this->url, ['name' => 'Recurso ajeno', 'document' => $doc])->assertUnprocessable();
})->with(['brand', 'owner', 'silent']);

test('Studio timeline rejects inconsistent temporal IDs, envelopes and windows', function (string $case) {
    $doc = studioTimelineScene($this->video->id, $this->audio->id);
    match ($case) {
        'id' => $doc['timeline']['audio_tracks'][0]['id'] = 'clip',
        'curve' => $doc['timeline']['audio_tracks'][0]['gainPoints'][1]['time'] = 0,
        'curve_end' => $doc['timeline']['audio_tracks'][0]['gainPoints'][3]['time'] = 8.5,
        'window' => $doc['pages'][0]['elements'][0]['timing']['duration'] = 5,
        'transition' => $doc['pages'][1]['transition'] = ['type' => 'fade', 'duration' => 0.5],
        'offset' => $doc['pages'][0]['elements'][0]['sourceOffset'] = 10,
        'url' => $doc['timeline']['audio_tracks'][0]['url'] = 'https://example.test/audio.mp3',
    };
    $this->postJson($this->url, ['name' => 'Temporal inválido', 'document' => $doc])->assertUnprocessable();
})->with(['id', 'curve', 'curve_end', 'window', 'transition', 'offset', 'url']);

test('Studio timeline v2 shares the validated contract with bearer API and MCP without losing time data', function () {
    $doc = studioTimelineScene($this->video->id, $this->audio->id);
    $key = $this->mcpKey($this->user, ['designs:read', 'designs:write']);
    $api = $this->withToken($key)->postJson('/api/v1/brands/'.$this->brand->id.'/designs', ['name' => 'Temporal API', 'document' => $doc])->assertCreated();
    expect($api->json('document'))->toBe($doc);
    [$error, $created] = $this->callTool($key, 'create_design', ['brand_id' => $this->brand->id, 'name' => 'Temporal MCP', 'document' => $doc]);
    expect($error)->toBeFalse()->and($created['document'])->toBe($doc);
    [$readError, $read] = $this->callTool($key, 'get_design', ['design_id' => $created['id']]);
    expect($readError)->toBeFalse()->and($read['document'])->toBe($doc);
    $reader = $this->mcpKey($this->user, ['designs:read']);
    [$denied] = $this->callTool($reader, 'create_design', ['brand_id' => $this->brand->id, 'name' => 'Sin permiso', 'document' => $doc]);
    expect($denied)->toBeTrue();
});
