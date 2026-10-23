<?php

use App\Connectors\Data\PublishMedia;
use App\Connectors\Data\PublishRequest;
use App\Connectors\Google\YouTubePublisher;
use App\Connectors\Network;
use App\Enums\ContentType;
use App\Models\Brand;
use App\Models\MediaAsset;
use App\Models\SocialAccount;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;
use Tests\Support\FakeHttp;

// C-04 (T-27, CA-13): opciones de YouTube en la subida sin expulsar el marcador de idempotencia.

const YTO_CHANNEL = 'UCabcdefghij0123456789';
const YTO_SESSION = 'https://www.googleapis.com/upload/youtube/v3/videos?uploadType=resumable&upload_id=OPT1';
const YTO_SIZE = 1024;

beforeEach(function () {
    Carbon::setTestNow('2026-11-01 12:00:00');
    Cache::flush();
    Storage::fake('local');
    config(['filesystems.media' => 'local', 'social.youtube.daily_quota' => 10000, 'social.youtube.upload_cost' => 1600]);

    $this->user = User::factory()->create();
    $this->brand = Brand::factory()->for($this->user)->create();
    $this->account = SocialAccount::factory()->for($this->user)->create(['brand_id' => $this->brand->id, 'network' => Network::YouTube, 'external_id' => YTO_CHANNEL, 'access_token' => 'tok']);
    $this->asset = MediaAsset::factory()->video(duration: 45)->for($this->user)->create(['size' => YTO_SIZE]);
    Storage::disk('local')->put($this->asset->path, str_repeat('v', YTO_SIZE));
    $this->http = new FakeHttp;
    $this->http->on('POST', '#uploadType=resumable#', ['status' => 200, 'headers' => ['Location' => YTO_SESSION]])
        ->on('PUT', '#upload_id=OPT1$#', ['status' => 200, 'json' => ['id' => 'VID1', 'status' => ['uploadStatus' => 'uploaded']]]);
    $this->yt = new YouTubePublisher;
});

function ytoRequest(array $options = [], int $targetId = 77): PublishRequest
{
    $a = test()->asset;
    $media = new PublishMedia($a->id, 'video', 'video/mp4', 'https://cdn.test/v.mp4', 1080, 1920, 45.0, 30.0, YTO_SIZE, $a->disk, $a->path);

    return new PublishRequest($targetId, ContentType::YouTubeVideo, "Titulo\nDescripcion", [$media], $options, null, now()->subMinutes(5));
}

function ytoInit(): array
{
    return test()->http->callsTo('POST', '#uploadType=resumable#')[0];
}

test('YouTubeOptions: la subida lleva categoria, hecho para ninos, tags, no notificar y etiqueta IA y conserva el marcador (CA-13)', function () {
    $this->yt->publish($this->account, ytoRequest([
        'category_id' => '27', 'made_for_kids' => false, 'tags' => ['laravel', 'php'], 'notify_subscribers' => false, 'contains_synthetic_media' => true,
    ]));

    $init = ytoInit();
    expect($init['json']['snippet']['categoryId'])->toBe('27')
        ->and($init['json']['snippet']['tags'])->toBe([YouTubePublisher::targetTag(77), 'laravel', 'php'])
        ->and($init['json']['status'])->toBe(['privacyStatus' => 'public', 'selfDeclaredMadeForKids' => false, 'containsSyntheticMedia' => true])
        ->and($init['url'])->toContain('notifySubscribers=false');
});

test('YouTubeOptions: sin opciones se mantiene el comportamiento anterior (categoria 22, sin notifySubscribers ni etiqueta IA)', function () {
    $this->yt->publish($this->account, ytoRequest());

    $init = ytoInit();
    expect($init['json']['snippet']['categoryId'])->toBe('22')
        ->and($init['json']['snippet']['tags'])->toBe([YouTubePublisher::targetTag(77)])
        ->and($init['json']['status'])->toBe(['privacyStatus' => 'public', 'selfDeclaredMadeForKids' => false])
        ->and($init['url'])->not->toContain('notifySubscribers');
});

test('YouTubeOptions: hecho para ninos se envia cuando se marca; categoria no valida cae a la de por defecto', function () {
    $this->yt->publish($this->account, ytoRequest(['made_for_kids' => true, 'category_id' => 'abc']));

    expect(ytoInit()['json']['status']['selfDeclaredMadeForKids'])->toBeTrue()
        ->and(ytoInit()['json']['snippet']['categoryId'])->toBe('22');
});

test('YouTubeOptions: muchos tags largos no expulsan el marcador y el total no pasa de 500 caracteres', function () {
    $many = array_map(fn ($i) => 'etiqueta muy larga numero '.$i.str_repeat('x', 60), range(1, 30));

    $tags = YouTubePublisher::tagsFor(77, $many);

    expect($tags[0])->toBe(YouTubePublisher::targetTag(77));
    $cost = array_sum(array_map(fn ($t) => mb_strlen($t) + (str_contains($t, ' ') ? 2 : 0) + 1, $tags));
    expect($cost)->toBeLessThanOrEqual(500)->and(count($tags))->toBeGreaterThan(1)->toBeLessThan(31);
});

test('YouTubeOptions: el marcador sobrevive a tags unicode, vacios, duplicados, con comas y al propio marcador', function () {
    $marker = YouTubePublisher::targetTag(77);
    $tags = YouTubePublisher::tagsFor(77, ['ñandú 🦩', '  ', 'PHP', 'php', 'a,b', '<x>', $marker, str_repeat('é', 100), 42]);

    expect($tags[0])->toBe($marker)
        ->and(array_count_values($tags)[$marker])->toBe(1)
        ->and($tags)->toContain('ñandú 🦩', 'PHP', 'ab', 'x')
        ->and($tags)->not->toContain('php');
});

test('YouTubeOptions: un video con tags del usuario sigue encontrandose por el marcador (findPublished)', function () {
    $this->http->on('GET', '#youtube/v3/playlistItems\?#', ['json' => ['items' => [
        ['snippet' => ['publishedAt' => '2026-11-01T11:59:30Z', 'resourceId' => ['videoId' => 'VID9']]],
    ]]])->on('GET', '#youtube/v3/videos\?part=snippet&id=#', ['json' => ['items' => [
        ['id' => 'VID9', 'snippet' => ['tags' => YouTubePublisher::tagsFor(77, ['uno', 'dos', str_repeat('largo ', 70)])]],
    ]]])->on('GET', '#youtube/v3/videos\?part=status#', ['json' => ['items' => [['status' => ['uploadStatus' => 'processed']]]]]);

    $found = $this->yt->findPublished($this->account, ytoRequest(['tags' => ['uno', 'dos']]));

    expect($found?->externalId)->toBe('VID9');
});

test('YouTubeOptions: PostRequest rechaza categoria, tags y booleanos con forma invalida', function () {
    $post = fn (array $options) => $this->actingAs($this->user)->postJson(route('brands.posts.validate', $this->brand, false), [
        'schedule' => false, 'targets' => [['account_id' => $this->account->id, 'content_type' => 'youtube_video', 'options' => $options]],
    ]);

    $post(['category_id' => 'x1'])->assertJsonValidationErrors('targets.0.options.category_id');
    $post(['tags' => 'no-lista'])->assertJsonValidationErrors('targets.0.options.tags');
    $post(['tags' => [str_repeat('a', 101)]])->assertJsonValidationErrors('targets.0.options.tags.0');
    $post(['notify_subscribers' => 'quizas'])->assertJsonValidationErrors('targets.0.options.notify_subscribers');
    $post(['category_id' => '27', 'tags' => ['a'], 'notify_subscribers' => false])->assertOk();
});
