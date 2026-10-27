<?php

use App\Connectors\Bluesky\BlueskyPublisher;
use App\Connectors\Data\PublishMedia;
use App\Connectors\Data\PublishRequest;
use App\Connectors\Exceptions\PermanentPublishException;
use App\Connectors\Exceptions\ReconnectRequiredException;
use App\Connectors\Exceptions\TransientPublishException;
use App\Enums\ContentType;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\Support\BlueskyFake;

beforeEach(function () {
    Storage::fake('local');
    config(['filesystems.media' => 'local', 'social.bluesky.image_max_bytes' => 2_000_000, 'social.bluesky.default_langs' => ['es']]);
    $this->account = BlueskyFake::account();
    $this->publisher = app(BlueskyPublisher::class);
});

/** Imagen JPEG real en el disco; devuelve el PublishMedia que veria el publicador. */
function bskyMedia(int $id, int $side = 64, int $quality = 80, bool $noise = false): PublishMedia
{
    $im = imagecreatetruecolor($side, $side * 2 / 3);
    if ($noise) {
        mt_srand($id);
        for ($y = 0; $y < imagesy($im); $y++) {
            for ($x = 0; $x < $side; $x++) {
                imagesetpixel($im, $x, $y, (mt_rand(0, 255) << 16) | (mt_rand(0, 255) << 8) | mt_rand(0, 255));
            }
        }
    }
    ob_start();
    imagejpeg($im, null, $quality);
    $bytes = (string) ob_get_clean();
    $path = "media/test/img-$id.jpg";
    Storage::disk('local')->put($path, $bytes);

    return new PublishMedia(id: $id, kind: 'image', mime: 'image/jpeg', url: 'https://signed.example/'.$id, width: $side, height: (int) ($side * 2 / 3), size: strlen($bytes), disk: 'local', path: $path);
}

function bskyRequest(?string $text, array $media = [], ContentType $type = ContentType::BlueskyPost): PublishRequest
{
    return new PublishRequest(targetId: 1, type: $type, text: $text, media: $media);
}

function fakeBskyPublish(array $extra = []): void
{
    Http::fake($extra + [
        'pds.example.com/xrpc/com.atproto.repo.uploadBlob' => Http::sequence()
            ->push(['blob' => ['$type' => 'blob', 'ref' => ['$link' => 'bafkrei1'], 'mimeType' => 'image/jpeg', 'size' => 1000]])
            ->push(['blob' => ['$type' => 'blob', 'ref' => ['$link' => 'bafkrei2'], 'mimeType' => 'image/jpeg', 'size' => 1000]])
            ->push(['blob' => ['$type' => 'blob', 'ref' => ['$link' => 'bafkrei3'], 'mimeType' => 'image/jpeg', 'size' => 1000]])
            ->push(['blob' => ['$type' => 'blob', 'ref' => ['$link' => 'bafkrei4'], 'mimeType' => 'image/jpeg', 'size' => 1000]]),
        'pds.example.com/xrpc/com.atproto.repo.createRecord' => Http::response(['uri' => 'at://'.BlueskyFake::DID.'/app.bsky.feed.post/3kabcdefghij2', 'cid' => 'bafyreicid']),
    ]);
}

function sentTo(string $suffix): array
{
    return Http::recorded()->filter(fn ($pair) => str_ends_with($pair[0]->url(), $suffix))->map(fn ($pair) => $pair[0])->values()->all();
}

test('texto solo: un createRecord con texto, langs y createdAt, sin embed', function () {
    fakeBskyPublish();

    $result = $this->publisher->publish($this->account, bskyRequest('Hola Bluesky'));

    expect($result->isPublished())->toBeTrue()
        ->and($result->externalId)->toBe('at://'.BlueskyFake::DID.'/app.bsky.feed.post/3kabcdefghij2')
        ->and($result->url)->toBe('https://bsky.app/profile/ana.example.com/post/3kabcdefghij2');

    [$sent] = sentTo('createRecord');
    expect($sent->hasHeader('Authorization', 'Bearer OLD-ACCESS'))->toBeTrue()
        ->and($sent['repo'])->toBe(BlueskyFake::DID)->and($sent['collection'])->toBe('app.bsky.feed.post')
        ->and($sent['record']['text'])->toBe('Hola Bluesky')->and($sent['record']['langs'])->toBe(['es'])
        ->and($sent['record'])->not->toHaveKey('embed')->not->toHaveKey('facets')
        ->and($sent['record']['createdAt'])->toMatch('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}\.\d{3}Z$/');
    expect(sentTo('uploadBlob'))->toBe([]);
});

test('4 imagenes: 4 blobs dentro del tope, aspectRatio y facets de enlaces y hashtags', function () {
    fakeBskyPublish();
    $media = array_map(fn ($i) => bskyMedia($i), [1, 2, 3, 4]);

    $this->publisher->publish($this->account, bskyRequest('Mira https://example.com/a #verano', $media));

    $uploads = sentTo('uploadBlob');
    expect($uploads)->toHaveCount(4);
    foreach ($uploads as $upload) {
        expect(strlen($upload->body()))->toBeLessThanOrEqual(2_000_000)->and($upload->hasHeader('Content-Type', 'image/jpeg'))->toBeTrue();
    }

    [$sent] = sentTo('createRecord');
    $record = $sent['record'];
    expect($record['embed']['$type'])->toBe('app.bsky.embed.images')->and($record['embed']['images'])->toHaveCount(4)
        ->and($record['embed']['images'][0]['image']['ref']['$link'])->toBe('bafkrei1')
        ->and($record['embed']['images'][3]['image']['ref']['$link'])->toBe('bafkrei4')
        ->and($record['embed']['images'][0]['aspectRatio'])->toBe(['width' => 64, 'height' => 42])
        ->and(array_map(fn ($f) => $f['features'][0]['$type'], $record['facets']))->toBe(['app.bsky.richtext.facet#link', 'app.bsky.richtext.facet#tag']);
});

test('los indices de los facets coinciden en bytes con emojis compuestos', function () {
    fakeBskyPublish();
    $family = "\u{1F468}\u{200D}\u{1F469}\u{200D}\u{1F467}\u{200D}\u{1F466}";
    $text = "{$family} #hola";

    $this->publisher->publish($this->account, bskyRequest($text));

    [$sent] = sentTo('createRecord');
    $facet = $sent['record']['facets'][0];
    expect($facet['index']['byteStart'])->toBe(strlen("{$family} "))
        ->and(substr($text, $facet['index']['byteStart'], $facet['index']['byteEnd'] - $facet['index']['byteStart']))->toBe('#hola');
});

test('una mencion resoluble lleva su DID y una no resoluble queda como texto plano', function () {
    fakeBskyPublish([
        'bsky.social/xrpc/com.atproto.identity.resolveHandle?handle=amiga.example.com' => Http::response(['did' => 'did:plc:amiga0123456789abcdefghi']),
        'bsky.social/xrpc/com.atproto.identity.resolveHandle?handle=nadie.example.com' => Http::response(['error' => 'InvalidRequest', 'message' => 'Unable to resolve handle'], 400),
    ]);

    $this->publisher->publish($this->account, bskyRequest('Hola @amiga.example.com y @nadie.example.com'));

    [$sent] = sentTo('createRecord');
    expect($sent['record']['facets'])->toHaveCount(1)
        ->and($sent['record']['facets'][0]['features'][0])->toBe(['$type' => 'app.bsky.richtext.facet#mention', 'did' => 'did:plc:amiga0123456789abcdefghi'])
        ->and($sent['record']['text'])->toBe('Hola @amiga.example.com y @nadie.example.com');
});

test('una imagen por encima del tope se sube como copia recomprimida', function () {
    fakeBskyPublish();
    $big = bskyMedia(9, 1600, 100, noise: true);
    expect($big->size)->toBeGreaterThan(2_000_000);

    $this->publisher->publish($this->account, bskyRequest('Foto grande', [$big]));

    [$upload] = sentTo('uploadBlob');
    expect(strlen($upload->body()))->toBeLessThanOrEqual(2_000_000)->and(strlen($upload->body()))->toBeLessThan($big->size);
    // La original sigue intacta.
    expect(Storage::disk('local')->size($big->path))->toBe($big->size);
});

test('un token caducado se renueva y la publicacion continua', function () {
    $new = BlueskyFake::jwt();
    Http::fake([
        'pds.example.com/xrpc/com.atproto.repo.createRecord' => Http::sequence()
            ->push(['error' => 'ExpiredToken', 'message' => 'Token has expired'], 400)
            ->push(['uri' => 'at://'.BlueskyFake::DID.'/app.bsky.feed.post/3kabcdefghij2', 'cid' => 'c']),
        'pds.example.com/xrpc/com.atproto.server.refreshSession' => Http::response(['accessJwt' => $new, 'refreshJwt' => 'NEW-REFRESH', 'did' => BlueskyFake::DID]),
    ]);

    $result = $this->publisher->publish($this->account, bskyRequest('Hola'));

    expect($result->isPublished())->toBeTrue();
    $sent = sentTo('createRecord');
    expect($sent)->toHaveCount(2)->and($sent[1]->hasHeader('Authorization', 'Bearer '.$new))->toBeTrue();
});

test('errores traducidos: sesion -> reconectar, 5xx y limite -> transitorio, rechazo -> permanente', function (array $fake, string $exception) {
    Http::fake($fake);

    expect(fn () => $this->publisher->publish($this->account, bskyRequest('Hola')))->toThrow($exception);
})->with([
    '5xx' => [['pds.example.com/xrpc/com.atproto.repo.createRecord' => fn () => Http::response('boom', 502)], TransientPublishException::class],
    'limite' => [['pds.example.com/xrpc/com.atproto.repo.createRecord' => fn () => Http::response(['error' => 'RateLimitExceeded', 'message' => 'slow down'], 429)], TransientPublishException::class],
    'rechazo' => [['pds.example.com/xrpc/com.atproto.repo.createRecord' => fn () => Http::response(['error' => 'InvalidRequest', 'message' => 'Record/text must not be longer than 300 graphemes'], 400)], PermanentPublishException::class],
    'cuenta suspendida' => [['pds.example.com/xrpc/com.atproto.repo.createRecord' => fn () => Http::response(['error' => 'AccountTakedown', 'message' => 'x'], 400)], PermanentPublishException::class],
    'sesion perdida' => [[
        'pds.example.com/xrpc/com.atproto.repo.createRecord' => fn () => Http::response(['error' => 'ExpiredToken', 'message' => 'x'], 400),
        'pds.example.com/xrpc/com.atproto.server.refreshSession' => fn () => Http::response(['error' => 'InvalidToken', 'message' => 'x'], 400),
        'pds.example.com/xrpc/com.atproto.server.createSession' => fn () => Http::response(['error' => 'AuthenticationRequired', 'message' => 'x'], 401),
    ], ReconnectRequiredException::class],
]);

test('un tipo que no es de este publicador se rechaza sin llamar a la red', function () {
    Http::fake();

    expect(fn () => $this->publisher->publish($this->account, bskyRequest('x', type: ContentType::InstagramImage)))->toThrow(PermanentPublishException::class);
    Http::assertNothingSent();
});

test('la contrasena de aplicacion nunca viaja en una publicacion', function () {
    fakeBskyPublish();

    $this->publisher->publish($this->account, bskyRequest('Hola'));

    Http::assertNotSent(fn (Request $r) => str_contains($r->body(), 'APP-PASS-WORD-1234') || str_contains(json_encode($r->headers()), 'APP-PASS-WORD-1234'));
});

test('una imagen que no se puede leer falla con motivo permanente', function () {
    Http::fake();
    $media = new PublishMedia(id: 5, kind: 'image', mime: 'image/jpeg', url: 'x', size: 100, disk: 'local', path: 'media/test/no-existe.jpg');

    expect(fn () => $this->publisher->publish($this->account, bskyRequest('Hola', [$media])))->toThrow(PermanentPublishException::class);
});

test('un video en un post de imagenes se rechaza', function () {
    $video = new PublishMedia(id: 6, kind: 'video', mime: 'video/mp4', url: 'x', disk: 'local', path: 'media/test/v.mp4');
    Http::fake();

    expect(fn () => $this->publisher->publish($this->account, bskyRequest('Hola', [$video])))->toThrow(PermanentPublishException::class);
});
