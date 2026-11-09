<?php

use App\Enums\ContentType;
use App\Models\Brand;
use App\Models\MediaAsset;
use App\Models\Studio\StudioAsset;
use App\Models\User;
use App\Services\MediaUploadService;
use App\Services\MediaValidator;
use App\Services\Studio\StudioAudioProbeUnavailable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\Process\Process;
use Tests\Support\McpClient;
use Tests\Support\StudioAudioR2;

uses(McpClient::class);

beforeEach(function () {
    Storage::fake('local');
    config(['filesystems.media' => 'local']);
    $this->user = User::factory()->create();
    $this->brand = Brand::factory()->for($this->user)->create();
    $this->actingAs($this->user);
    $this->audioUrl = '/brands/'.$this->brand->id.'/studio/audio';
});

/** One second of our own 440 Hz, 16 kHz mono PCM. No third-party recording. */
function studioAudioWave(): string
{
    $pcm = '';
    for ($i = 0; $i < 16000; $i++) {
        $pcm .= pack('v', ((int) round(12000 * sin(2 * M_PI * 440 * $i / 16000))) & 0xFFFF);
    }

    return 'RIFF'.pack('V', 36 + strlen($pcm)).'WAVEfmt '.pack('VvvVVvv', 16, 1, 1, 16000, 32000, 2, 16)
        .'data'.pack('V', strlen($pcm)).$pcm;
}

test('Studio audio starts a private binary upload registered in exactly its brand', function () {
    $response = $this->postJson($this->audioUrl, ['name' => 'tono.wav', 'mime' => 'audio/wav', 'size' => strlen(studioAudioWave())])
        ->assertCreated()->assertJsonPath('asset.kind', 'audio')->assertJsonPath('asset.status', 'pendiente')
        ->assertJsonPath('asset.preview_url', null)->assertJsonPath('upload.mode', 'local');
    $id = $response->json('asset.id');
    expect($response->json('upload.url'))->toBe($this->audioUrl.'/'.$id.'/upload')
        ->and($response->json('complete_url'))->toBe($this->audioUrl.'/'.$id.'/complete');
    $this->assertDatabaseHas('studio_assets', ['brand_id' => $this->brand->id, 'user_id' => $this->user->id, 'media_asset_id' => $id]);
    expect(StudioAsset::withoutUserScope()->count())->toBe(1);
    $response->assertJsonMissingPath('asset.path')->assertJsonMissingPath('asset.disk');
});

test('Studio audio finalization probes our real PCM file before declaring it ready', function () {
    $wave = studioAudioWave();
    $asset = app(MediaUploadService::class)->initiate($this->user, 'tono.wav', 'audio/wav', strlen($wave), allowAudio: true)['asset'];
    Storage::disk('local')->put($asset->path, $wave);
    $result = app(MediaUploadService::class)->finalize($asset);
    expect($result->status)->toBe('listo')
        ->and($result->duration)->toBe(1.0)->and($result->audio_codec)->toBe('pcm_s16le')
        ->and($result->width)->toBeNull()->and($result->height)->toBeNull()->and($result->codec)->toBeNull();
    Storage::disk('local')->assertExists($result->path);
    Storage::disk('local')->assertMissing($asset->path);
});

test('Studio audio keeps a valid pending file when the native probe is unavailable', function () {
    $wave = studioAudioWave();
    $asset = app(MediaUploadService::class)->initiate($this->user, 'tono.wav', 'audio/wav', strlen($wave), allowAudio: true)['asset'];
    Storage::disk('local')->put($asset->path, $wave);
    config(['media.ffprobe' => 't11-no-such-ffprobe-executable']);
    try {
        app(MediaUploadService::class)->finalize($asset);
    } catch (Throwable $e) {
        expect($e->getMessage())->not->toContain($asset->path);
    }
    expect($asset->fresh()->status)->toBe('pendiente');
    Storage::disk('local')->assertExists($asset->path);
    expect($asset->fresh()->import_state)->toBeNull()->and(Storage::disk('local')->allFiles('studio-audio'))->toBe([]);
});

test('Studio audio binary upload becomes playable only after native validation and cannot be overwritten', function () {
    $wave = studioAudioWave();
    $start = $this->postJson($this->audioUrl, ['name' => 'tono.wav', 'mime' => 'audio/wav', 'size' => strlen($wave)])->assertCreated()->json();
    $this->call('PUT', $start['upload']['url'], [], [], [], ['HTTP_ACCEPT' => 'application/json', 'CONTENT_TYPE' => 'audio/wav'], $wave)
        ->assertOk()->assertJsonPath('asset.status', 'listo')->assertJsonPath('asset.duration', 1)
        ->assertJsonPath('asset.audio_codec', 'pcm_s16le');
    $asset = MediaAsset::withoutUserScope()->findOrFail($start['asset']['id']);
    $file = '/brands/'.$this->brand->id.'/studio/assets/'.$asset->id.'/file';
    $this->get($file, ['Range' => 'bytes=0-15'])->assertStatus(206)->assertHeader('Content-Range', 'bytes 0-15/'.strlen($wave));
    $this->call('PUT', $start['upload']['url'], [], [], [], ['HTTP_ACCEPT' => 'application/json'], 'overwrite')
        ->assertConflict();
    expect(Storage::disk('local')->get($asset->path))->toBe($wave);
    $this->postJson($start['complete_url'], [])->assertOk()->assertJsonPath('asset.id', $asset->id);
});

test('Studio audio rejects a real object whose size differs from its upload declaration', function () {
    $wave = studioAudioWave();
    $asset = app(MediaUploadService::class)->initiate($this->user, 'tono.wav', 'audio/wav', strlen($wave) + 1, allowAudio: true)['asset'];
    Storage::disk('local')->put($asset->path, $wave);
    expect(app(MediaUploadService::class)->finalize($asset)->status)->toBe('invalido');
    Storage::disk('local')->assertMissing($asset->path);
});

test('Studio audio ready listing is scoped paginated and treats search wildcards literally', function () {
    foreach (['Tono %_ uno.wav', 'Tono %_ dos.wav', 'Tono otro.wav'] as $name) {
        $asset = MediaAsset::factory()->for($this->user)->create(['kind' => 'audio', 'mime' => 'audio/wav', 'original_name' => $name, 'duration' => 1, 'audio_codec' => 'pcm_s16le']);
        StudioAsset::create(['user_id' => $this->user->id, 'brand_id' => $this->brand->id, 'media_asset_id' => $asset->id]);
    }
    $pending = $this->postJson($this->audioUrl, ['name' => 'Tono %_ pendiente.wav', 'mime' => 'audio/wav', 'size' => 200])->assertCreated()->json('asset.id');
    $response = $this->getJson($this->audioUrl.'?query='.urlencode('%_').'&per_page=1')->assertOk()
        ->assertJsonPath('pagination.total', 2)->assertJsonPath('pagination.next_page', 2)->assertJsonCount(1, 'assets');
    $first = $response->json('assets.0.id');
    $second = $this->getJson($this->audioUrl.'?query='.urlencode('%_').'&per_page=1&page=2')->assertOk()->json('assets.0.id');
    expect($first)->not->toBe($second)->and($first)->not->toBe($pending);
    $this->getJson($this->audioUrl.'/'.$pending)->assertOk()->assertJsonPath('asset.status', 'pendiente');
});

test('Studio audio never appears in the social composer media catalog', function () {
    $image = MediaAsset::factory()->for($this->user)->create();
    MediaAsset::factory()->for($this->user)->create(['kind' => 'audio', 'mime' => 'audio/wav', 'duration' => 1, 'audio_codec' => 'pcm_s16le']);
    $ids = array_column($this->getJson(route('media.index'))->assertOk()->json('media'), 'id');
    expect($ids)->toBe([$image->id]);
    [$error, $data] = $this->callTool($this->mcpKey($this->user, ['brands:read']), 'list_media');
    expect($error)->toBeFalse()->and(array_column($data['media'], 'id'))->toBe([$image->id]);
});

test('Studio audio API and MCP enforce mixed abilities and the original brand identity', function () {
    $read = $this->mcpKey($this->user, ['designs:read']);
    $write = $this->mcpKey($this->user, ['designs:write']);
    $base = '/api/v1/brands/'.$this->brand->id.'/design-audio';
    app('auth')->forgetGuards();
    $this->getJson($base, ['Authorization' => 'Bearer '.$read])->assertOk();
    app('auth')->forgetGuards();
    $this->postJson($base, ['name' => 'test.wav', 'mime' => 'audio/wav', 'size' => 200], ['Authorization' => 'Bearer '.$read])->assertForbidden();
    [$error, $data] = $this->callTool($write, 'manage_studio_audio', ['action' => 'start', 'brand_id' => $this->brand->id, 'name' => 'agente.wav', 'mime' => 'audio/wav', 'size' => 200]);
    expect($error)->toBeFalse()->and($data['asset']['kind'])->toBe('audio')
        ->and($data['upload']['url'])->toStartWith($base.'/');
    $id = $data['asset']['id'];
    $otherBrand = Brand::factory()->for($this->user)->create();
    app('auth')->forgetGuards();
    $this->getJson('/api/v1/brands/'.$otherBrand->id.'/design-audio/'.$id, ['Authorization' => 'Bearer '.$read])->assertNotFound();
    [$error] = $this->callTool($read, 'manage_studio_audio', ['action' => 'start', 'brand_id' => $this->brand->id, 'name' => 'denied.wav', 'mime' => 'audio/wav', 'size' => 200]);
    expect($error)->toBeTrue();
    [$error, $get] = $this->callTool($read, 'manage_studio_audio', ['action' => 'get', 'brand_id' => $this->brand->id, 'media_id' => $id]);
    expect($error)->toBeFalse()->and($get['asset']['id'])->toBe($id);
});

test('Studio audio can be explicitly attached to another own brand after native validation', function () {
    $wave = studioAudioWave();
    $start = $this->postJson($this->audioUrl, ['name' => 'marca.wav', 'mime' => 'audio/wav', 'size' => strlen($wave)])->assertCreated()->json();
    $this->call('PUT', $start['upload']['url'], [], [], [], ['HTTP_ACCEPT' => 'application/json'], $wave)->assertOk();
    $other = Brand::factory()->for($this->user)->create();
    $this->postJson('/brands/'.$other->id.'/studio/assets', ['media_id' => $start['asset']['id']])
        ->assertCreated()->assertJsonPath('asset.kind', 'audio');
    $this->assertDatabaseHas('studio_assets', ['brand_id' => $other->id, 'user_id' => $this->user->id, 'media_asset_id' => $start['asset']['id']]);
});

test('Studio audio R2 completion seals a validated snapshot away from the upload capability', function () {
    $remote = new StudioAudioR2;
    $wave = studioAudioWave();
    $start = $this->postJson($this->audioUrl, ['name' => 'remoto.wav', 'mime' => 'audio/wav', 'size' => strlen($wave)])
        ->assertCreated()->assertJsonPath('upload.mode', 'single')->json();
    $asset = MediaAsset::withoutUserScope()->findOrFail($start['asset']['id']);
    $uploadPath = $asset->path;
    $remote->objects[$uploadPath] = $wave;
    $this->postJson($start['complete_url'], [])->assertOk()->assertJsonPath('asset.audio_codec', 'pcm_s16le')
        ->assertJsonPath('asset.duration', 1)->assertJsonPath('asset.status', 'listo');
    $asset->refresh();
    expect($asset->path)->not->toBe($uploadPath);
    $remote->objects[$uploadPath] = 'a replay of the old upload URL';
    expect($remote->objects[$asset->path])->toBe($wave);
    $this->get('/brands/'.$this->brand->id.'/studio/assets/'.$asset->id.'/file', ['Range' => 'bytes=0-15'])
        ->assertStatus(206)->assertStreamedContent(substr($wave, 0, 16));
    expect(array_filter(iterator_to_array($remote->calls), fn ($call) => $call['name'] === 'GetObject' && isset($call['args']['Range'])))->not->toBeEmpty();
    $this->travel(181)->minutes();
    app(MediaUploadService::class)->pruneAbandoned(24);
    expect(isset($remote->objects[$uploadPath]))->toBeFalse()->and($remote->objects[$asset->path])->toBe($wave);
});

test('Studio audio binary transfer cannot bypass its brand-scoped endpoints', function () {
    $wave = studioAudioWave();
    $start = $this->postJson($this->audioUrl, ['name' => 'marca.wav', 'mime' => 'audio/wav', 'size' => strlen($wave)])->assertCreated()->json();
    $id = $start['asset']['id'];
    $this->call('PUT', route('media.upload', $id), [], [], [], ['HTTP_ACCEPT' => 'application/json'], $wave)->assertNotFound();
    $this->postJson(route('media.complete', $id), [])->assertNotFound();
    expect(MediaAsset::withoutUserScope()->findOrFail($id)->status)->toBe('pendiente');
});

test('Studio audio abandoned upload cleanup removes its managed probe snapshot', function () {
    $start = $this->postJson($this->audioUrl, ['name' => 'abandono.wav', 'mime' => 'audio/wav', 'size' => 200])->assertCreated()->json();
    $asset = MediaAsset::withoutUserScope()->findOrFail($start['asset']['id']);
    $snapshot = 'studio-audio/'.$this->user->id.'/'.$asset->id.'/'.Str::uuid().'.wav';
    Storage::disk('local')->put($snapshot, 'owned incomplete snapshot');
    $asset->forceFill(['import_state' => ['studio_audio_snapshot' => $snapshot], 'created_at' => now()->subHours(25)])->save();
    expect(app(MediaUploadService::class)->pruneAbandoned(24))->toBe(1);
    Storage::disk('local')->assertMissing($snapshot);
});

test('Studio audio probes real supported compressed formats', function (string $mime, string $extension, string $encoder, string $codec) {
    $disk = Storage::disk('local');
    $work = 'audio-format/'.Str::uuid();
    $disk->put($work.'/source.wav', studioAudioWave());
    $output = $disk->path($work.'/encoded.'.$extension);
    $process = new Process([(string) config('media.ffmpeg', 'ffmpeg'), '-nostdin', '-hide_banner', '-loglevel', 'error', '-y',
        '-i', $disk->path($work.'/source.wav'), '-vn', '-c:a', $encoder, '-b:a', '64k', $output]);
    $process->setTimeout(15);
    $process->mustRun();
    $bytes = file_get_contents($output);
    $start = $this->postJson($this->audioUrl, ['name' => 'tono.'.$extension, 'mime' => $mime, 'size' => strlen($bytes)])->assertCreated()->json();
    $result = $this->call('PUT', $start['upload']['url'], [], [], [], ['HTTP_ACCEPT' => 'application/json'], $bytes)
        ->assertOk()->assertJsonPath('asset.status', 'listo')->assertJsonPath('asset.audio_codec', $codec)->json('asset');
    expect((float) $result['duration'])->toBeGreaterThan(0.8)->toBeLessThan(1.4);
    $asset = MediaAsset::withoutUserScope()->findOrFail($result['id']);
    expect(hash('sha256', $disk->get($asset->path)))->toBe(hash('sha256', $bytes));
})->with([
    'MP3' => ['audio/mpeg', 'mp3', 'libmp3lame', 'mp3'],
    'M4A AAC' => ['audio/mp4', 'm4a', 'aac', 'aac'],
    'ADTS AAC' => ['audio/aac', 'aac', 'aac', 'aac'],
    'Ogg Vorbis' => ['audio/ogg', 'ogg', 'libvorbis', 'vorbis'],
    'Ogg Opus' => ['audio/ogg', 'ogg', 'libopus', 'opus'],
]);

test('Studio audio rejects invalid declarations without allocating media', function (array $patch) {
    $this->postJson($this->audioUrl, array_replace(['name' => 'tono.wav', 'mime' => 'audio/wav', 'size' => 200], $patch))->assertUnprocessable();
    expect(MediaAsset::withoutUserScope()->count())->toBe(0);
})->with([
    'video' => [['mime' => 'video/mp4']], 'empty' => [['size' => 0]], 'too large' => [['size' => 104857601]],
    'remote URL' => [['url' => 'https://source.invalid/track.wav']], 'base64' => [['file_base64' => 'AAAA']],
    'path' => [['path' => '../track.wav']],
]);

test('Studio audio rejects playlists and malformed files and cleans their bytes', function (string $bytes, string $mime) {
    $start = $this->postJson($this->audioUrl, ['name' => 'bad.audio', 'mime' => $mime, 'size' => strlen($bytes)])->assertCreated()->json();
    $this->call('PUT', $start['upload']['url'], [], [], [], ['HTTP_ACCEPT' => 'application/json'], $bytes)
        ->assertStatus(422)->assertJsonPath('asset.status', 'invalido')->assertJsonPath('asset.preview_url', null);
    expect(Storage::disk('local')->allFiles('studio-audio'))->toBe([]);
    Storage::disk('local')->assertMissing(MediaAsset::withoutUserScope()->findOrFail($start['asset']['id'])->path);
})->with([
    'HLS' => ["#EXTM3U\n#EXTINF:1\nhttps://audio-target.invalid/segment.aac\n", 'audio/aac'],
    'html' => ['<html>not audio</html>', 'audio/wav'],
]);

test('Studio audio refuses cross-brand and cross-owner operations including pending files', function () {
    $wave = studioAudioWave();
    $start = $this->postJson($this->audioUrl, ['name' => 'marca.wav', 'mime' => 'audio/wav', 'size' => strlen($wave)])->assertCreated()->json();
    $id = $start['asset']['id'];
    $other = Brand::factory()->for($this->user)->create();
    $base = '/brands/'.$other->id.'/studio/audio/'.$id;
    $this->getJson($base)->assertNotFound();
    $this->call('PUT', $base.'/upload', [], [], [], ['HTTP_ACCEPT' => 'application/json'], $wave)->assertNotFound();
    $this->postJson($base.'/complete', [])->assertNotFound();
    $this->actingAs(User::factory()->create());
    $this->getJson($this->audioUrl.'/'.$id)->assertNotFound();
    $this->postJson($this->audioUrl.'/'.$id.'/complete', [])->assertNotFound();
    expect(MediaAsset::withoutUserScope()->findOrFail($id)->status)->toBe('pendiente');
});

test('Studio audio does not accept a concurrent transfer or a truncated body', function () {
    $wave = studioAudioWave();
    $start = $this->postJson($this->audioUrl, ['name' => 'tono.wav', 'mime' => 'audio/wav', 'size' => strlen($wave)])->assertCreated()->json();
    $lock = Cache::lock('studio.audio.transfer.'.$start['asset']['id'], 180);
    expect($lock->get())->toBeTrue();
    try {
        $this->call('PUT', $start['upload']['url'], [], [], [], ['HTTP_ACCEPT' => 'application/json'], $wave)->assertConflict();
    } finally {
        $lock->release();
    }
    $this->call('PUT', $start['upload']['url'], [], [], [], ['HTTP_ACCEPT' => 'application/json'], substr($wave, 0, -1))
        ->assertStatus(422)->assertJsonPath('asset.status', 'invalido');
    expect(Storage::disk('local')->allFiles('media'))->toBe([]);
});

test('Studio audio R2 multipart uses ordered parts bounded requests and complete file probing', function () {
    $remote = new StudioAudioR2;
    config(['media.multipart_threshold' => 1, 'media.part_size' => 5 * 1024 * 1024]);
    $wave = studioAudioWave();
    $junk = 'JUNK'.pack('V', 5 * 1024 * 1024).str_repeat("\0", 5 * 1024 * 1024);
    $wave = substr_replace($wave, pack('V', strlen($wave) + strlen($junk) - 8), 4, 4).$junk;
    $start = $this->postJson($this->audioUrl, ['name' => 'multipart.wav', 'mime' => 'audio/wav', 'size' => strlen($wave)])
        ->assertCreated()->assertJsonPath('upload.mode', 'multipart')->assertJsonCount(2, 'upload.parts')->json();
    $asset = MediaAsset::withoutUserScope()->findOrFail($start['asset']['id']);
    $remote->objects[$asset->path] = $wave;
    $this->postJson($start['complete_url'], ['parts' => [['number' => 2, 'etag' => 'part-2'], ['number' => 1, 'etag' => 'part-1']]])
        ->assertOk()->assertJsonPath('asset.status', 'listo')->assertJsonPath('asset.duration', 1);
    $commands = array_values(array_filter(iterator_to_array($remote->calls), fn ($call) => $call['name'] === 'CompleteMultipartUpload'));
    expect(array_column($commands[0]['args']['MultipartUpload']['Parts'], 'PartNumber'))->toBe([1, 2])
        ->and($commands[0]['args']['@http']['timeout'])->toBe(20.0);
    $this->postJson($start['complete_url'], [])->assertOk();
    expect(count(array_filter(iterator_to_array($remote->calls), fn ($call) => $call['name'] === 'CompleteMultipartUpload')))->toBe(1);
});

test('Studio audio transfers native bytes through bearer API and completes through MCP', function () {
    $wave = studioAudioWave();
    $write = $this->mcpKey($this->user, ['designs:write']);
    $read = $this->mcpKey($this->user, ['designs:read']);
    [$error, $start] = $this->callTool($write, 'manage_studio_audio', ['action' => 'start', 'brand_id' => $this->brand->id,
        'name' => 'agente.wav', 'mime' => 'audio/wav', 'size' => strlen($wave)]);
    expect($error)->toBeFalse();
    app('auth')->forgetGuards();
    $this->call('PUT', $start['upload']['url'], [], [], [], ['HTTP_ACCEPT' => 'application/json', 'HTTP_AUTHORIZATION' => 'Bearer '.$read], $wave)->assertForbidden();
    app('auth')->forgetGuards();
    $this->call('PUT', $start['upload']['url'], [], [], [], ['HTTP_ACCEPT' => 'application/json', 'HTTP_AUTHORIZATION' => 'Bearer '.$write], $wave)
        ->assertOk()->assertJsonPath('asset.status', 'listo');
    [$error, $completed] = $this->callTool($write, 'manage_studio_audio', ['action' => 'complete', 'brand_id' => $this->brand->id, 'media_id' => $start['asset']['id']]);
    expect($error)->toBeFalse()->and($completed['asset']['audio_codec'])->toBe('pcm_s16le');
    app('auth')->forgetGuards();
    $this->get($completed['asset']['preview_url'], ['Authorization' => 'Bearer '.$read, 'Range' => 'bytes=0-15'])
        ->assertStatus(206)->assertStreamedContent(substr($wave, 0, 16));
    [$error, $listing] = $this->callTool($read, 'manage_studio_audio', ['action' => 'list', 'brand_id' => $this->brand->id]);
    expect($error)->toBeFalse()->and(array_column($listing['assets'], 'id'))->toBe([$start['asset']['id']]);
});

test('Studio audio remains invalid as a standalone social attachment for every network content type', function () {
    $asset = MediaAsset::factory()->for($this->user)->create(['kind' => 'audio', 'mime' => 'audio/wav', 'duration' => 1, 'audio_codec' => 'pcm_s16le']);
    foreach (ContentType::cases() as $type) {
        expect(app(MediaValidator::class)->validate($type, [$asset], 'Texto de prueba'))->not->toBeEmpty($type->value);
    }
});

test('Studio audio retries only validation after a consumed multipart and an unavailable probe', function () {
    $remote = new StudioAudioR2;
    config(['media.multipart_threshold' => 1]);
    $wave = studioAudioWave();
    $start = $this->postJson($this->audioUrl, ['name' => 'retry.wav', 'mime' => 'audio/wav', 'size' => strlen($wave)])->assertCreated()->json();
    $asset = MediaAsset::withoutUserScope()->findOrFail($start['asset']['id']);
    $remote->objects[$asset->path] = $wave;
    $binary = config('media.ffprobe');
    config(['media.ffprobe' => 't11-missing-native-probe']);
    $this->postJson($start['complete_url'], ['parts' => [['number' => 1, 'etag' => 'part-1']]])->assertStatus(503);
    expect($asset->fresh()->status)->toBe('pendiente')->and($asset->fresh()->upload_id)->toBeNull();
    config(['media.ffprobe' => $binary]);
    $this->postJson($start['complete_url'], [])->assertOk()->assertJsonPath('asset.status', 'listo');
    expect(count(array_filter(iterator_to_array($remote->calls), fn ($call) => $call['name'] === 'CompleteMultipartUpload')))->toBe(1);
});

test('Studio audio remote metadata honors bounded per-request timeouts and disables hidden retries', function () {
    $remote = new StudioAudioR2;
    $wave = studioAudioWave();
    $start = $this->postJson($this->audioUrl, ['name' => 'timeout.wav', 'mime' => 'audio/wav', 'size' => strlen($wave)])->assertCreated()->json();
    $asset = MediaAsset::withoutUserScope()->findOrFail($start['asset']['id']);
    $remote->objects[$asset->path] = $wave;
    $this->postJson($start['complete_url'], [])->assertOk();
    $heads = array_filter(iterator_to_array($remote->calls), fn ($call) => $call['name'] === 'HeadObject');
    expect($heads)->not->toBeEmpty();
    foreach ($heads as $head) {
        expect($head['args']['@http']['timeout'] ?? null)->toBeNumeric()->toBeGreaterThan(0)->toBeLessThanOrEqual(25)
            ->and($head['args']['@retries'] ?? null)->toBe(0);
    }
});

test('Studio audio cleanup removes only stale managed native probe temporaries', function () {
    $dir = storage_path('app/private/studio-audio-probe');
    File::ensureDirectoryExists($dir);
    $old = $dir.'/'.Str::uuid().'.bin';
    $fresh = $dir.'/'.Str::uuid().'.bin';
    $unowned = $dir.'/keep-this.bin';
    file_put_contents($old, 'small owned stale fixture');
    file_put_contents($fresh, 'small owned live fixture');
    file_put_contents($unowned, 'not a generated temporary');
    touch($old, time() - 7200);
    touch($unowned, time() - 7200);
    app(MediaUploadService::class)->pruneAbandoned(24);
    expect(is_file($old))->toBeFalse()->and(is_file($fresh))->toBeTrue()->and(is_file($unowned))->toBeTrue();
});

test('Studio audio recovers a consumed multipart acknowledgement before its database checkpoint', function () {
    $remote = new StudioAudioR2;
    config(['media.multipart_threshold' => 1]);
    $wave = studioAudioWave();
    $start = $this->postJson($this->audioUrl, ['name' => 'checkpoint.wav', 'mime' => 'audio/wav', 'size' => strlen($wave)])->assertCreated()->json();
    $asset = MediaAsset::withoutUserScope()->findOrFail($start['asset']['id']);
    $remote->objects[$asset->path] = $wave;
    $remote->multipartOpen = false; // The storage consumed it before PHP could persist the acknowledgement.
    $this->postJson($start['complete_url'], ['parts' => [['number' => 1, 'etag' => 'part-1']]])
        ->assertOk()->assertJsonPath('asset.status', 'listo');
});

test('Studio audio remote metadata outage is retryable and preserves the uploaded bytes', function () {
    $remote = new StudioAudioR2;
    $wave = studioAudioWave();
    $start = $this->postJson($this->audioUrl, ['name' => 'outage.wav', 'mime' => 'audio/wav', 'size' => strlen($wave)])->assertCreated()->json();
    $asset = MediaAsset::withoutUserScope()->findOrFail($start['asset']['id']);
    $remote->objects[$asset->path] = $wave;
    $remote->fail['HeadObject'] = 20;
    $this->postJson($start['complete_url'], [])->assertStatus(503);
    expect($asset->fresh()->status)->toBe('pendiente')->and($remote->objects[$asset->path])->toBe($wave);
});

test('Studio audio failed abandoned-file deletion retains the record and snapshot for a later retry', function () {
    $remote = new StudioAudioR2;
    $start = $this->postJson($this->audioUrl, ['name' => 'cleanup.wav', 'mime' => 'audio/wav', 'size' => 200])->assertCreated()->json();
    $asset = MediaAsset::withoutUserScope()->findOrFail($start['asset']['id']);
    $snapshot = 'studio-audio/'.$asset->user_id.'/'.$asset->id.'/'.Str::uuid().'.wav';
    $remote->objects[$asset->path] = 'owned upload';
    $remote->objects[$snapshot] = 'owned temporary snapshot';
    $asset->forceFill(['import_state' => ['studio_audio_snapshot' => $snapshot], 'created_at' => now()->subHours(25)])->save();
    $remote->fail['DeleteObject'] = 1;
    try {
        app(MediaUploadService::class)->pruneAbandoned(24);
    } catch (StudioAudioProbeUnavailable) {
        // The important invariant is retaining the retryable allocation, even if the caller reports an outage.
    }
    expect(MediaAsset::withoutUserScope()->whereKey($asset->id)->exists())->toBeTrue()
        ->and(isset($remote->objects[$snapshot]))->toBeTrue();
    expect(app(MediaUploadService::class)->pruneAbandoned(24))->toBe(1);
    expect(isset($remote->objects[$snapshot]))->toBeFalse()->and(isset($remote->objects[$asset->path]))->toBeFalse();
});
