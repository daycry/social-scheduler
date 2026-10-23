<?php

use App\Connectors\Data\PublishMedia;
use App\Connectors\Data\PublishRequest;
use App\Connectors\Exceptions\PermanentPublishException;
use App\Connectors\Google\YouTubePublisher;
use App\Connectors\Network;
use App\Connectors\TikTok\TikTokPublisher;
use App\Enums\ContentType;
use App\Models\Brand;
use App\Models\MediaAsset;
use App\Models\Post;
use App\Models\SocialAccount;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;
use Tests\Support\FakeHttp;
use Tests\Support\McpClient;

uses(McpClient::class);

// Revision de la Fase 4 (T-33): booleanos equivalentes (1, 0, "1", "0") en web y MCP, defensa de los publishers, regla de marca
// privada en TikTok y limpieza de los tags de YouTube.

beforeEach(function () {
    Carbon::setTestNow('2026-11-01 12:00:00');
    Cache::flush();
    Storage::fake('local');
    config(['filesystems.media' => 'local', 'social.youtube.daily_quota' => 10000, 'social.youtube.upload_cost' => 1600, 'social.tiktok.verified_domain' => 'https://media.midominio.test', 'social.tiktok.single_chunk_max_bytes' => 64 * 1024 * 1024]);

    $this->user = User::factory()->create();
    $this->brand = Brand::factory()->for($this->user)->create(['timezone' => 'Europe/Madrid']);
    $this->yt = SocialAccount::factory()->for($this->user)->create(['brand_id' => $this->brand->id, 'network' => Network::YouTube, 'external_id' => 'UCabcdefghij0123456789', 'access_token' => 'tok']);
    $this->tt = SocialAccount::factory()->for($this->user)->create(['brand_id' => $this->brand->id, 'network' => Network::TikTok, 'access_token' => 'tt', 'meta' => ['direct_post' => true]]);
    $this->video = MediaAsset::factory()->video(duration: 45)->for($this->user)->create(['size' => 1024]);
    Storage::disk('local')->put($this->video->path, str_repeat('v', 1024));
    $this->key = $this->mcpKey($this->user);
});

function prBody(string $network, array $options, bool $schedule = false): array
{
    $account = $network === 'youtube' ? test()->yt : test()->tt;

    return ['text' => 'Hola', 'schedule' => $schedule, 'media' => [test()->video->id], 'targets' => [['account_id' => $account->id, 'content_type' => $network === 'youtube' ? 'youtube_video' : 'tiktok_video', 'options' => $options]]];
}

function prSaved(): array
{
    return Post::withoutUserScope()->latest('id')->first()->targets()->first()->options;
}

// --- Important 1 y 2: normalizacion de booleanos al guardar ---

test('PresetsReview: la web normaliza 1, 0, "1" y "0" a booleanos reales de YouTube al guardar', function (mixed $on, mixed $off) {
    $this->actingAs($this->user)->post(route('brands.posts.store', $this->brand), prBody('youtube', [
        'made_for_kids' => $on, 'contains_synthetic_media' => $on, 'notify_subscribers' => $off, 'shorts_hashtag' => $off,
    ]))->assertSessionHasNoErrors();

    expect(prSaved())->toEqualCanonicalizing(['made_for_kids' => true, 'contains_synthetic_media' => true, 'notify_subscribers' => false, 'shorts_hashtag' => false]);
})->with([[1, 0], ['1', '0'], [true, false]]);

test('PresetsReview: el MCP normaliza 1, 0, "1" y "0" a booleanos reales de YouTube al guardar', function (mixed $on, mixed $off) {
    [$err] = $this->callTool($this->key, 'create_post', [
        'brand_id' => $this->brand->id, 'text' => 'Hola', 'schedule' => false, 'media_ids' => [$this->video->id],
        'targets' => [['account_id' => $this->yt->id, 'content_type' => 'youtube_video', 'options' => ['made_for_kids' => $on, 'notify_subscribers' => $off]]],
    ]);

    expect($err)->toBeFalse()->and(prSaved())->toEqualCanonicalizing(['made_for_kids' => true, 'notify_subscribers' => false]);
})->with([[1, 0], ['1', '0']]);

test('PresetsReview: 0 y "0" no cuentan como pedidos en TikTok (borrador y marca de contenido)', function (mixed $zero) {
    // Cuenta en modo borrador: `disable_duet: 0` es «no pedido», no un error de «solo con publicacion directa».
    $this->tt->forceFill(['meta' => []])->save();
    $this->actingAs($this->user)->post(route('brands.posts.store', $this->brand), prBody('tiktok', ['disable_duet' => $zero, 'is_aigc' => $zero]))->assertSessionHasNoErrors();
    expect(prSaved())->toEqualCanonicalizing(['disable_duet' => false, 'is_aigc' => false]);

    // Publicacion directa: `brand_content_toggle: 0` sin visibilidad no dispara «no puede ser privado».
    $this->tt->forceFill(['meta' => ['direct_post' => true]])->save();
    $this->actingAs($this->user)->post(route('brands.posts.store', $this->brand), prBody('tiktok', ['brand_content_toggle' => $zero]))->assertSessionHasNoErrors();
    expect(prSaved())->toBe(['brand_content_toggle' => false]);
})->with([[0], ['0']]);

test('PresetsReview: por MCP, brand_content_toggle 0/"0" se acepta y 1/"1" sin visibilidad sigue siendo privado', function () {
    foreach ([0, '0'] as $zero) {
        [$err] = $this->callTool($this->key, 'create_post', [
            'brand_id' => $this->brand->id, 'text' => 'Hola', 'schedule' => false, 'media_ids' => [$this->video->id],
            'targets' => [['account_id' => $this->tt->id, 'content_type' => 'tiktok_video', 'options' => ['brand_content_toggle' => $zero, 'disable_duet' => $zero]]],
        ]);
        expect($err)->toBeFalse();
    }

    foreach ([1, '1'] as $one) {
        [$err, $msg] = $this->callTool($this->key, 'create_post', [
            'brand_id' => $this->brand->id, 'text' => 'Hola', 'schedule' => false, 'media_ids' => [$this->video->id],
            'targets' => [['account_id' => $this->tt->id, 'content_type' => 'tiktok_video', 'options' => ['brand_content_toggle' => $one]]],
        ]);
        expect($err)->toBeTrue()->and(json_encode($msg))->toContain('no puede ser privado');
    }
});

// --- Defensa en los publishers (valores equivalentes ya guardados) ---

function prYtRequest(array $options): PublishRequest
{
    $a = test()->video;
    $media = new PublishMedia($a->id, 'video', 'video/mp4', 'https://cdn.test/v.mp4', 1080, 1920, 45.0, 30.0, 1024, $a->disk, $a->path);

    return new PublishRequest(77, ContentType::YouTubeVideo, "Titulo\nDescripcion", [$media], $options, null, now()->subMinutes(5));
}

test('PresetsReview: YouTube interpreta 1/"1" y 0/"0" como booleanos (hecho para ninos y notificar)', function (mixed $on, mixed $off) {
    $session = 'https://www.googleapis.com/upload/youtube/v3/videos?uploadType=resumable&upload_id=PR1';
    $http = new FakeHttp;
    $http->on('POST', '#uploadType=resumable#', ['status' => 200, 'headers' => ['Location' => $session]])
        ->on('PUT', '#upload_id=PR1$#', ['status' => 200, 'json' => ['id' => 'VID1', 'status' => ['uploadStatus' => 'uploaded']]]);

    (new YouTubePublisher)->publish($this->yt, prYtRequest(['made_for_kids' => $on, 'contains_synthetic_media' => $on, 'notify_subscribers' => $off]));

    $init = $http->callsTo('POST', '#uploadType=resumable#')[0];
    expect($init['json']['status'])->toBe(['privacyStatus' => 'public', 'selfDeclaredMadeForKids' => true, 'containsSyntheticMedia' => true])
        ->and($init['url'])->toContain('notifySubscribers=false');
})->with([[1, 0], ['1', '0']]);

test('PresetsReview: TikTok interpreta "0" como falso y "1" como verdadero en disable_comment', function () {
    $init = ['json' => ['data' => ['publish_id' => 'v1', 'upload_url' => 'https://open-upload.tiktokapis.com/video/?upload_id=U1&upload_token=T'], 'error' => ['code' => 'ok']]];
    $http = new FakeHttp;
    $http->on('POST', '#creator_info/query/$#', ['json' => ['data' => ['privacy_level_options' => ['SELF_ONLY', 'PUBLIC_TO_EVERYONE']], 'error' => ['code' => 'ok']]])
        ->on('POST', '#/publish/video/init/$#', $init)
        ->on('PUT', '#upload_id=U1#', ['status' => 201]);
    $a = $this->video;
    $media = new PublishMedia($a->id, 'video', 'video/mp4', 'https://cdn.test/v.mp4', 1080, 1920, 20.0, 30.0, 1024, $a->disk, $a->path);

    (new TikTokPublisher)->publish($this->tt->fresh(), new PublishRequest(55, ContentType::TikTokVideo, 'Video', [$media], ['privacy_level' => 'PUBLIC_TO_EVERYONE', 'disable_comment' => '0', 'disable_duet' => '1'], null, now()->subMinutes(5)));

    expect($http->callsTo('POST', '#/publish/video/init/#')[0]['json']['post_info'])->toMatchArray(['disable_comment' => false, 'disable_duet' => true]);
});

// --- Importante 4: nunca una combinacion prohibida (marca + privado) hacia TikTok ---

test('PresetsReview: contenido de marca con una visibilidad que la cuenta no ofrece da un error claro y no envia nada', function () {
    $http = new FakeHttp;
    // La cuenta solo ofrece SELF_ONLY: pedir PUBLIC_TO_EVERYONE caeria a SELF_ONLY con la marca activa.
    $http->on('POST', '#creator_info/query/$#', ['json' => ['data' => ['privacy_level_options' => ['SELF_ONLY']], 'error' => ['code' => 'ok']]]);
    $a = $this->video;
    $media = new PublishMedia($a->id, 'video', 'video/mp4', 'https://cdn.test/v.mp4', 1080, 1920, 20.0, 30.0, 1024, $a->disk, $a->path);
    $request = new PublishRequest(55, ContentType::TikTokVideo, 'Video', [$media], ['privacy_level' => 'PUBLIC_TO_EVERYONE', 'brand_content_toggle' => true], null, now()->subMinutes(5));

    expect(fn () => (new TikTokPublisher)->publish($this->tt->fresh(), $request))->toThrow(PermanentPublishException::class, 'no puede ser privado');
    expect($http->callsTo('POST', '#/publish/video/init/#'))->toBe([]);
});

test('PresetsReview: con marca y sin la visibilidad pedida usa la opcion no privada mas abierta que no supere lo pedido', function () {
    $http = new FakeHttp;
    $http->on('POST', '#creator_info/query/$#', ['json' => ['data' => ['privacy_level_options' => ['FOLLOWER_OF_CREATOR', 'SELF_ONLY']], 'error' => ['code' => 'ok']]])
        ->on('POST', '#/publish/video/init/$#', ['json' => ['data' => ['publish_id' => 'v1', 'upload_url' => 'https://open-upload.tiktokapis.com/video/?upload_id=U1&upload_token=T'], 'error' => ['code' => 'ok']]])
        ->on('PUT', '#upload_id=U1#', ['status' => 201]);
    $a = $this->video;
    $media = new PublishMedia($a->id, 'video', 'video/mp4', 'https://cdn.test/v.mp4', 1080, 1920, 20.0, 30.0, 1024, $a->disk, $a->path);
    $request = new PublishRequest(55, ContentType::TikTokVideo, 'Video', [$media], ['privacy_level' => 'PUBLIC_TO_EVERYONE', 'brand_content_toggle' => true], null, now()->subMinutes(5));

    (new TikTokPublisher)->publish($this->tt->fresh(), $request);

    $info = $http->callsTo('POST', '#/publish/video/init/#')[0]['json']['post_info'];
    expect($info['privacy_level'])->toBe('FOLLOWER_OF_CREATOR')->and($info['brand_content_toggle'])->toBeTrue();
});

test('PresetsReview: con marca y solo SELF_ONLY disponible el mensaje dice que la cuenta solo ofrece la visibilidad privada', function () {
    $http = new FakeHttp;
    $http->on('POST', '#creator_info/query/$#', ['json' => ['data' => ['privacy_level_options' => ['SELF_ONLY']], 'error' => ['code' => 'ok']]]);
    $a = $this->video;
    $media = new PublishMedia($a->id, 'video', 'video/mp4', 'https://cdn.test/v.mp4', 1080, 1920, 20.0, 30.0, 1024, $a->disk, $a->path);
    $request = new PublishRequest(55, ContentType::TikTokVideo, 'Video', [$media], ['privacy_level' => 'PUBLIC_TO_EVERYONE', 'brand_content_toggle' => true], null, now()->subMinutes(5));

    expect(fn () => (new TikTokPublisher)->publish($this->tt->fresh(), $request))->toThrow(PermanentPublishException::class, 'solo ofrece la visibilidad privada');
    expect($http->callsTo('POST', '#/publish/video/init/#'))->toBe([]);
});

// --- Extra: tags de YouTube sin saltos de linea ni caracteres de control ---

test('PresetsReview: los tags de YouTube no llevan saltos de linea ni caracteres de control y el marcador sigue primero', function () {
    $tags = YouTubePublisher::tagsFor(77, ["uno\ndos", "tres\r\ncuatro", "cin\x00co\x07", "\n\t", 'normal']);

    expect($tags[0])->toBe(YouTubePublisher::targetTag(77))
        ->and(array_slice($tags, 1))->toBe(['uno dos', 'tres cuatro', 'cin co', 'normal']);
    foreach ($tags as $tag) {
        expect(preg_match('/[\x00-\x1F\x7F]/', $tag))->toBe(0);
    }
});
