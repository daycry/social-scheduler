<?php

use App\Connectors\Contracts\FirstCommenter;
use App\Connectors\Contracts\Publisher;
use App\Connectors\Data\PublishMedia;
use App\Connectors\Data\PublishRequest;
use App\Connectors\Exceptions\PermanentPublishException;
use App\Connectors\Exceptions\QuotaExceededException;
use App\Connectors\Exceptions\ReconnectRequiredException;
use App\Connectors\Exceptions\TransientPublishException;
use App\Connectors\LinkedIn\LinkedInPublisher;
use App\Enums\ContentType;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\Support\NewNetworksFake;

beforeEach(function () {
    Http::preventStrayRequests();
    Storage::fake('local');
    $this->account = NewNetworksFake::linkedinAccount();
    $this->publisher = app(LinkedInPublisher::class);
});

function liMedia(int $id, string $kind = 'image', ?string $alt = null, string $bytes = 'IMG'): PublishMedia
{
    $path = "m/$id.".($kind === 'video' ? 'mp4' : 'jpg');
    Storage::disk('local')->put($path, $bytes);

    return new PublishMedia(id: $id, kind: $kind, mime: $kind === 'video' ? 'video/mp4' : 'image/jpeg', url: 'https://signed.example/'.$id, size: strlen($bytes), disk: 'local', path: $path, alt: $alt);
}

function liRequest(ContentType $type, ?string $text, array $media = [], array $options = [], array $progress = [], ?Closure $checkpoint = null): PublishRequest
{
    return new PublishRequest(targetId: 1, type: $type, text: $text, media: $media, options: $options, since: now()->subMinute(), progress: $progress, checkpoint: $checkpoint);
}

function liPosted(): ?array
{
    $body = null;
    Http::assertSent(function (Request $r) use (&$body) {
        if (str_ends_with($r->url(), '/rest/posts') && $r->method() === 'POST') {
            $body = $r->data();
        }

        return true;
    });

    return $body;
}

test('es un publicador y un primer comentador', function () {
    expect($this->publisher)->toBeInstanceOf(Publisher::class)->toBeInstanceOf(FirstCommenter::class);
});

test('publica texto como perfil con la Posts API, cabeceras de version y texto escapado', function () {
    Http::fake(['api.linkedin.com/rest/posts' => Http::response('', 201, ['x-restli-id' => 'urn:li:share:7001'])]);

    $result = $this->publisher->publish($this->account, liRequest(ContentType::LinkedInText, 'Hola (mundo) #lanzamiento_1 @ana'));

    expect($result->isPublished())->toBeTrue()->and($result->externalId)->toBe('urn:li:share:7001')->and($result->url)->toBe('https://www.linkedin.com/feed/update/urn:li:share:7001/');
    $body = liPosted();
    expect($body['author'])->toBe('urn:li:person:abc123')->and($body['visibility'])->toBe('PUBLIC')->and($body['lifecycleState'])->toBe('PUBLISHED')
        ->and($body['commentary'])->toBe('Hola \(mundo\) #lanzamiento\_1 \@ana')->and($body)->not->toHaveKey('content')
        ->and($body['distribution']['feedDistribution'])->toBe('MAIN_FEED')->and($body['isReshareDisabledByAuthor'])->toBeFalse();
    Http::assertSent(fn (Request $r) => $r->header('Authorization') === ['Bearer LI-TOKEN'] && $r->header('X-Restli-Protocol-Version') === ['2.0.0'] && $r->header('LinkedIn-Version') === ['202511']);
});

test('una imagen se sube desde el disco y se publica con su texto alternativo', function () {
    Http::fake([
        'api.linkedin.com/rest/images*' => Http::response(['value' => ['uploadUrl' => 'https://www.linkedin.com/dms-uploads/img1', 'image' => 'urn:li:image:C1']]),
        'www.linkedin.com/dms-uploads/img1' => Http::response('', 201),
        'api.linkedin.com/rest/posts' => Http::response('', 201, ['x-restli-id' => 'urn:li:share:7002']),
    ]);

    $this->publisher->publish($this->account, liRequest(ContentType::LinkedInImage, 'Foto', [liMedia(1, 'image', 'Un gato naranja', 'JPEGBYTES')]));

    Http::assertSent(fn (Request $r) => str_contains($r->url(), 'rest/images') && str_contains($r->url(), 'action=initializeUpload') && $r['initializeUploadRequest']['owner'] === 'urn:li:person:abc123');
    Http::assertSent(fn (Request $r) => $r->method() === 'PUT' && $r->url() === 'https://www.linkedin.com/dms-uploads/img1' && $r->body() === 'JPEGBYTES');
    expect(liPosted()['content'])->toBe(['media' => ['id' => 'urn:li:image:C1', 'altText' => 'Un gato naranja']]);
});

test('varias imagenes van como multiImage con el alt de cada una y los URN se checkpointean', function () {
    Http::fake([
        'api.linkedin.com/rest/images*' => Http::sequence()
            ->push(['value' => ['uploadUrl' => 'https://www.linkedin.com/dms-uploads/a', 'image' => 'urn:li:image:A']])
            ->push(['value' => ['uploadUrl' => 'https://www.linkedin.com/dms-uploads/b', 'image' => 'urn:li:image:B']]),
        'www.linkedin.com/dms-uploads/*' => Http::response('', 201),
        'api.linkedin.com/rest/posts' => Http::response('', 201, ['x-restli-id' => 'urn:li:share:7003']),
    ]);
    $saved = [];

    $this->publisher->publish($this->account, liRequest(ContentType::LinkedInCarousel, null, [liMedia(1, 'image', 'Uno'), liMedia(2)], checkpoint: function ($ref, $progress) use (&$saved) {
        $saved[] = $progress;
    }));

    expect(liPosted()['content'])->toBe(['multiImage' => ['images' => [['id' => 'urn:li:image:A', 'altText' => 'Uno'], ['id' => 'urn:li:image:B']]]])
        ->and(end($saved))->toBe(['assets' => ['urn:li:image:A', 'urn:li:image:B']]);
});

test('un reintento con la media ya subida no la sube de nuevo', function () {
    Http::fake(['api.linkedin.com/rest/posts' => Http::response('', 201, ['x-restli-id' => 'urn:li:share:7004'])]);

    $this->publisher->publish($this->account, liRequest(ContentType::LinkedInImage, 'x', [liMedia(1)], progress: ['assets' => ['urn:li:image:OLD']]));

    expect(liPosted()['content']['media']['id'])->toBe('urn:li:image:OLD');
    Http::assertNotSent(fn (Request $r) => str_contains($r->url(), 'rest/images'));
});

test('un video se sube por partes con los rangos de LinkedIn, se finaliza con los ETag y se publica', function () {
    Http::fake([
        'api.linkedin.com/rest/videos*' => Http::sequence()
            ->push(['value' => ['video' => 'urn:li:video:V1', 'uploadToken' => 'TOK', 'uploadInstructions' => [
                ['uploadUrl' => 'https://www.linkedin.com/dms-uploads/p1', 'firstByte' => 0, 'lastByte' => 3],
                ['uploadUrl' => 'https://www.linkedin.com/dms-uploads/p2', 'firstByte' => 4, 'lastByte' => 7],
            ]]])
            ->push('', 200),
        'www.linkedin.com/dms-uploads/p1' => Http::response('', 200, ['ETag' => '"etag-1"']),
        'www.linkedin.com/dms-uploads/p2' => Http::response('', 200, ['ETag' => '"etag-2"']),
        'api.linkedin.com/rest/posts' => Http::response('', 201, ['x-restli-id' => 'urn:li:share:7005']),
    ]);

    $this->publisher->publish($this->account, liRequest(ContentType::LinkedInVideo, 'Video', [liMedia(9, 'video', null, 'AAAABBBB')]));

    Http::assertSent(fn (Request $r) => $r->url() === 'https://www.linkedin.com/dms-uploads/p1' && $r->body() === 'AAAA');
    Http::assertSent(fn (Request $r) => $r->url() === 'https://www.linkedin.com/dms-uploads/p2' && $r->body() === 'BBBB');
    Http::assertSent(fn (Request $r) => str_contains($r->url(), 'action=finalizeUpload') && $r['finalizeUploadRequest'] === ['video' => 'urn:li:video:V1', 'uploadToken' => 'TOK', 'uploadedPartIds' => ['etag-1', 'etag-2']]);
    expect(liPosted()['content'])->toBe(['media' => ['id' => 'urn:li:video:V1']]);
});

test('una URL de subida fuera de LinkedIn se rechaza sin enviar la media', function () {
    Http::fake(['api.linkedin.com/rest/images*' => Http::response(['value' => ['uploadUrl' => 'https://evil.example/steal', 'image' => 'urn:li:image:X']])]);

    expect(fn () => $this->publisher->publish($this->account, liRequest(ContentType::LinkedInImage, 'x', [liMedia(1)])))->toThrow(PermanentPublishException::class, 'URL de subida');
    Http::assertNotSent(fn (Request $r) => str_contains($r->url(), 'evil.example'));
});

test('la visibilidad y el bloqueo de comparticion se respetan; una pagina no admite CONNECTIONS', function () {
    Http::fake(['api.linkedin.com/rest/posts' => Http::response('', 201, ['x-restli-id' => 'urn:li:share:7006'])]);

    $this->publisher->publish($this->account, liRequest(ContentType::LinkedInText, 'x', options: ['linkedin_visibility' => 'CONNECTIONS', 'linkedin_disable_reshare' => true]));
    expect(liPosted())->toMatchArray(['visibility' => 'CONNECTIONS', 'isReshareDisabledByAuthor' => true]);

    $page = NewNetworksFake::linkedinAccount(kind: 'page');
    expect(fn () => $this->publisher->publish($page, liRequest(ContentType::LinkedInText, 'x', options: ['linkedin_visibility' => 'CONNECTIONS'])))
        ->toThrow(PermanentPublishException::class, 'siempre en abierto');
});

test('una pagina publica con autor organizacion', function () {
    Http::fake(['api.linkedin.com/rest/posts' => Http::response('', 201, ['x-restli-id' => 'urn:li:share:7007'])]);

    $this->publisher->publish(NewNetworksFake::linkedinAccount(kind: 'page'), liRequest(ContentType::LinkedInText, 'x'));

    expect(liPosted()['author'])->toBe('urn:li:organization:777');
});

test('el limite de 3000 caracteres y un tipo ajeno se rechazan', function () {
    expect(fn () => $this->publisher->publish($this->account, liRequest(ContentType::LinkedInText, str_repeat('a', 3001))))->toThrow(PermanentPublishException::class, '3000');
    expect(fn () => $this->publisher->publish($this->account, liRequest(ContentType::ThreadsText, 'x')))->toThrow(PermanentPublishException::class, 'no puede publicar');
});

test('los errores de LinkedIn se traducen a la excepcion que la cola entiende', function (int $status, string $class) {
    Http::fake(['api.linkedin.com/rest/posts' => Http::response(['message' => 'boom'], $status)]);

    expect(fn () => $this->publisher->publish($this->account, liRequest(ContentType::LinkedInText, 'x')))->toThrow($class);
})->with([[401, ReconnectRequiredException::class], [403, PermanentPublishException::class], [422, PermanentPublishException::class], [429, QuotaExceededException::class], [503, TransientPublishException::class]]);

test('un fallo de red es transitorio', function () {
    Http::fake(fn () => throw new ConnectionException('timeout'));

    expect(fn () => $this->publisher->publish($this->account, liRequest(ContentType::LinkedInText, 'x')))->toThrow(TransientPublishException::class);
});

test('findPublished encuentra la publicacion reciente con el mismo texto y no una anterior o ajena', function () {
    $recent = now()->getTimestamp() * 1000;
    Http::fake(['api.linkedin.com/rest/posts*' => Http::response(['elements' => [
        ['id' => 'urn:li:share:OLD', 'author' => 'urn:li:person:abc123', 'commentary' => 'Hola', 'createdAt' => (now()->getTimestamp() - 86400) * 1000],
        ['id' => 'urn:li:share:OTRO', 'author' => 'urn:li:person:zzz', 'commentary' => 'Hola', 'createdAt' => $recent],
        ['id' => 'urn:li:share:NEW', 'author' => 'urn:li:person:abc123', 'commentary' => 'Hola', 'createdAt' => $recent],
    ]])]);

    $found = $this->publisher->findPublished($this->account, liRequest(ContentType::LinkedInText, 'Hola'));
    expect($found?->externalId)->toBe('urn:li:share:NEW');
    expect($this->publisher->findPublished($this->account, liRequest(ContentType::LinkedInText, 'Otro texto')))->toBeNull();
    Http::assertSent(fn (Request $r) => str_contains($r->url(), 'q=author') && $r->header('X-RestLi-Method') === ['FINDER']);
});

test('el primer comentario se publica con el actor del autor y se puede localizar', function () {
    Http::fake([
        'api.linkedin.com/rest/socialActions/*' => Http::sequence()
            ->push(['commentUrn' => 'urn:li:comment:(urn:li:share:7001,55)'], 201)
            ->push(['elements' => [['actor' => 'urn:li:person:abc123', 'message' => ['text' => 'Mas info'], 'commentUrn' => 'urn:li:comment:(urn:li:share:7001,55)']]]),
    ]);

    $id = $this->publisher->comment($this->account, 'urn:li:share:7001', 'Mas info');
    expect($id)->toBe('urn:li:comment:(urn:li:share:7001,55)');
    Http::assertSent(fn (Request $r) => $r->method() === 'POST' && $r['actor'] === 'urn:li:person:abc123' && $r['message']['text'] === 'Mas info');
    expect($this->publisher->findOwnComment($this->account, 'urn:li:share:7001', 'Mas info'))->toBe('urn:li:comment:(urn:li:share:7001,55)');
});
