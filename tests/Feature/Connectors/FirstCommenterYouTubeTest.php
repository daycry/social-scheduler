<?php

use App\Connectors\ConnectorRegistry;
use App\Connectors\Exceptions\CommentNotAllowedException;
use App\Connectors\Exceptions\CommentSearchInconclusiveException;
use App\Connectors\Exceptions\PermanentPublishException;
use App\Connectors\Exceptions\QuotaExceededException;
use App\Connectors\Exceptions\ReconnectRequiredException;
use App\Connectors\Google\YouTubePublisher;
use App\Connectors\Google\YouTubeQuota;
use App\Connectors\Network;
use App\Models\Brand;
use App\Models\SocialAccount;
use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Tests\Support\FakeHttp;

// C-01b (CA-04, D2): primer comentario de YouTube con commentThreads, cuota de 50 unidades y scope force-ssl.

const YTC_CHANNEL = 'UCabcdefghij0123456789';
const YTC_THREADS = '#googleapis\.com/youtube/v3/commentThreads#';

beforeEach(function () {
    Cache::flush();
    config(['social.youtube.daily_quota' => 10000]);
    $this->user = User::factory()->create();
    $this->brand = Brand::factory()->for($this->user)->create();
    $this->account = SocialAccount::factory()->for($this->user)->create([
        'brand_id' => $this->brand->id, 'network' => Network::YouTube, 'external_id' => YTC_CHANNEL, 'access_token' => 'yt-token',
        'scopes' => ['https://www.googleapis.com/auth/youtube', 'https://www.googleapis.com/auth/youtube.force-ssl'],
    ]);
    $this->http = new FakeHttp;
    $this->yt = new YouTubePublisher;
});

function ytThread(string $id, string $channel, string $text): array
{
    return ['id' => 'T'.$id, 'snippet' => ['topLevelComment' => ['id' => $id, 'snippet' => ['authorChannelId' => ['value' => $channel], 'textDisplay' => $text, 'textOriginal' => $text]]]];
}

test('inserta el comentario, devuelve su id y descuenta 50 unidades de cuota', function () {
    $this->http->on('POST', YTC_THREADS, ['status' => 200, 'json' => ytThread('Ugx1', YTC_CHANNEL, 'Mas info')]);

    $id = $this->yt->comment($this->account, 'VID1', 'Mas info');

    $call = $this->http->callsTo('POST', YTC_THREADS)[0];
    expect($id)->toBe('Ugx1')
        ->and($call['json']['snippet']['videoId'])->toBe('VID1')
        ->and($call['json']['snippet']['topLevelComment']['snippet']['textOriginal'])->toBe('Mas info')
        ->and($call['headers']['authorization'])->toBe('Bearer yt-token')
        ->and((new YouTubeQuota)->used())->toBe(50);
});

test('findOwnComment filtra por el canal autor y el texto, y no gasta la cuota de insertar', function () {
    $this->http->on('GET', YTC_THREADS, ['status' => 200, 'json' => ['items' => [
        ytThread('A', 'UCotro', 'Mas info'),
        ytThread('B', YTC_CHANNEL, 'Mas  info '),
    ]]]);

    expect($this->yt->findOwnComment($this->account, 'VID1', 'Mas info'))->toBe('B')
        ->and($this->yt->findOwnComment($this->account, 'VID1', 'Otro'))->toBeNull()
        ->and((new YouTubeQuota)->used())->toBe(3); // encontrado: 1 busqueda; no encontrado: busqueda + pagina reciente sin filtro
    expect($this->http->callsTo('GET', YTC_THREADS)[0]['url'])->toContain('videoId=VID1')->toContain('searchTerms=Mas');
});

test('video privado o hecho para ninos da CommentNotAllowedException', function (int $status, string $reason) {
    $this->http->on('POST', YTC_THREADS, ['status' => $status, 'json' => ['error' => ['code' => $status, 'message' => 'x', 'errors' => [['reason' => $reason]]]]]);

    expect(fn () => $this->yt->comment($this->account, 'VID1', 'x'))->toThrow(CommentNotAllowedException::class);
    expect((new YouTubeQuota)->used())->toBe(0);
})->with([[403, 'commentsDisabled'], [404, 'videoNotFound']]);

test('sin el scope force-ssl no se llama a la API', function () {
    $this->account->forceFill(['scopes' => ['https://www.googleapis.com/auth/youtube']])->save();

    expect(fn () => $this->yt->comment($this->account->fresh(), 'VID1', 'x'))->toThrow(ReconnectRequiredException::class, 'Reconecta');
    expect($this->yt->findOwnComment($this->account->fresh(), 'VID1', 'x'))->toBeNull();
    expect($this->http->calls)->toBe([]);
});

test('sin cupo suficiente no se llama a la API', function () {
    config(['social.youtube.daily_quota' => 40]);

    expect(fn () => $this->yt->comment($this->account, 'VID1', 'x'))->toThrow(QuotaExceededException::class);
    expect($this->http->calls)->toBe([]);
});

test('commenterFor devuelve YouTube', function () {
    expect(app(ConnectorRegistry::class)->commenterFor(Network::YouTube))->toBeInstanceOf(YouTubePublisher::class);
});

test('otros rechazos de YouTube conservan su clase permanente', function () {
    $this->http->on('POST', YTC_THREADS, ['status' => 400, 'json' => ['error' => ['code' => 400, 'message' => 'bad', 'errors' => [['reason' => 'invalidCommentText']]]]]);

    expect(fn () => $this->yt->comment($this->account, 'VID1', 'x'))->toThrow(PermanentPublishException::class);
});

/** @return list<array<string, mixed>> */
function ytNoise(int $n, int $from): array
{
    return array_map(fn ($i) => ytThread('N'.$i, 'UCotro', 'ruido '.$i), range($from, $from + $n - 1));
}

test('un video con 250 comentarios: findOwnComment pide searchTerms con el texto y encuentra el propio sin recorrerlos', function () {
    $this->http
        ->on('GET', '#searchTerms=#', ['status' => 200, 'json' => ['items' => [ytThread('MIO', YTC_CHANNEL, 'Mas info')]]])
        ->on('GET', YTC_THREADS, ['status' => 200, 'json' => ['items' => ytNoise(100, 0), 'nextPageToken' => 'P2']]);

    expect($this->yt->findOwnComment($this->account, 'VID1', 'Mas info'))->toBe('MIO');

    $calls = $this->http->callsTo('GET', YTC_THREADS);
    expect($calls)->toHaveCount(1)->and($calls[0]['url'])->toContain('searchTerms=Mas%20info')->toContain('order=time')->toContain('maxResults=100');
});

test('si searchTerms no lo indexa aun, la pagina reciente sin filtro lo encuentra', function () {
    $this->http
        ->on('GET', '#searchTerms=#', ['status' => 200, 'json' => ['items' => []]])
        ->on('GET', YTC_THREADS, ['status' => 200, 'json' => ['items' => array_merge(ytNoise(99, 0), [ytThread('MIO', YTC_CHANNEL, 'Mas info')]), 'nextPageToken' => 'P2']]);

    expect($this->yt->findOwnComment($this->account, 'VID1', 'Mas info'))->toBe('MIO')
        ->and($this->http->callsTo('GET', YTC_THREADS))->toHaveCount(2);
});

test('con muchisimos hilos que contienen el texto, el tope de paginas da busqueda inconclusa (no crea a ciegas)', function () {
    config(['social.first_comment.find_pages' => 3]);
    $this->http->on('GET', '#searchTerms=#', ['status' => 200, 'json' => ['items' => ytNoise(100, 0), 'nextPageToken' => 'MAS']]);

    expect(fn () => $this->yt->findOwnComment($this->account, 'VID1', 'Mas info'))->toThrow(CommentSearchInconclusiveException::class);
    expect($this->http->callsTo('GET', YTC_THREADS))->toHaveCount(3)->and((new YouTubeQuota)->used())->toBe(3);
});
