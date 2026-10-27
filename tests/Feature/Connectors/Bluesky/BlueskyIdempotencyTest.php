<?php

use App\Connectors\Bluesky\BlueskyPublisher;
use App\Connectors\Bluesky\Tid;
use App\Connectors\Data\PublishRequest;
use App\Connectors\Exceptions\PermanentPublishException;
use App\Connectors\Exceptions\TransientPublishException;
use App\Enums\ContentType;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Tests\Support\BlueskyFake;

beforeEach(function () {
    $this->account = BlueskyFake::account();
    $this->publisher = app(BlueskyPublisher::class);
    $this->saved = []; // lo que el destino habria persistido con checkpoint()
});

function bskyTextRequest(array $progress = [], ?Closure $checkpoint = null, ?string $reference = null): PublishRequest
{
    return new PublishRequest(targetId: 1, type: ContentType::BlueskyPost, text: 'Hola Bluesky', media: [], reference: $reference, progress: $progress, checkpoint: $checkpoint);
}

function recordUri(string $rkey): string
{
    return 'at://'.BlueskyFake::DID.'/app.bsky.feed.post/'.$rkey;
}

function recordCalls(string $suffix): int
{
    return Http::recorded()->filter(fn ($pair) => str_contains($pair[0]->url(), $suffix))->count();
}

test('la rkey se persiste antes de llamar a createRecord y es la que se envia', function () {
    $order = [];
    Http::fake(['pds.example.com/xrpc/com.atproto.repo.createRecord' => function ($request) use (&$order) {
        $order[] = 'createRecord:'.$request['rkey'];

        return Http::response(['uri' => recordUri($request['rkey']), 'cid' => 'c']);
    }]);

    $result = $this->publisher->publish($this->account, bskyTextRequest([], function (?string $reference, ?array $progress) use (&$order) {
        $order[] = 'checkpoint:'.$progress['rkey'];
    }));

    $rkey = explode(':', $order[0])[1];
    expect($order)->toBe(["checkpoint:$rkey", "createRecord:$rkey"])
        ->and(Tid::isValid($rkey))->toBeTrue()
        ->and($result->externalId)->toBe(recordUri($rkey));
});

test('timeout en createRecord con la respuesta perdida: el registro existe y no se crea un segundo post', function () {
    $rkey = null;
    $createCalls = 0;
    Http::fake([
        'pds.example.com/xrpc/com.atproto.repo.createRecord' => function ($request) use (&$rkey, &$createCalls) {
            $createCalls++;
            $rkey = $request['rkey'];
            throw new ConnectionException('cURL error 28: Operation timed out');
        },
        'pds.example.com/xrpc/com.atproto.repo.getRecord*' => function () use (&$rkey) {
            return Http::response(['uri' => recordUri($rkey), 'cid' => 'c', 'value' => ['text' => 'Hola Bluesky']]);
        },
    ]);

    $result = $this->publisher->publish($this->account, bskyTextRequest());

    expect($result->isPublished())->toBeTrue()->and($result->externalId)->toBe(recordUri($rkey))
        ->and($createCalls)->toBe(1);
});

test('reintento tras un fallo ambiguo: findPublished encuentra el registro y no se vuelve a publicar', function () {
    $progress = null;
    $createCalls = 0;
    $exists = false; // el PDS acaba creando el registro aunque la respuesta se perdio
    Http::fake([
        'pds.example.com/xrpc/com.atproto.repo.createRecord' => function () use (&$createCalls) {
            $createCalls++;

            return Http::response('Bad gateway', 502);
        },
        'pds.example.com/xrpc/com.atproto.repo.getRecord*' => function () use (&$exists, &$progress) {
            return $exists
                ? Http::response(['uri' => recordUri($progress['rkey']), 'cid' => 'c', 'value' => []])
                : Http::response(['error' => 'RecordNotFound', 'message' => 'Could not locate record'], 400);
        },
    ]);

    // Intento 1: 502 y el registro aun no aparece -> transitorio; la rkey ya estaba guardada.
    $attempt = function () use (&$progress) {
        return $this->publisher->publish($this->account, bskyTextRequest([], function ($ref, $p) use (&$progress) {
            $progress = $p;
        }));
    };
    expect($attempt)->toThrow(TransientPublishException::class);
    expect(Tid::isValid($progress['rkey']))->toBeTrue();

    // Intento 2: el registro ya existe; el job llama a findPublished con la rkey persistida.
    $exists = true;
    $found = $this->publisher->findPublished($this->account, bskyTextRequest($progress));

    expect($found?->isPublished())->toBeTrue()->and($found->externalId)->toBe(recordUri($progress['rkey']))
        ->and($createCalls)->toBe(1);
});

test('el reintento reutiliza la misma rkey cuando el registro no existe', function () {
    $rkey = Tid::generate();
    Http::fake(['pds.example.com/xrpc/com.atproto.repo.createRecord' => fn ($request) => Http::response(['uri' => recordUri($request['rkey']), 'cid' => 'c'])]);

    $result = $this->publisher->publish($this->account, bskyTextRequest(['rkey' => $rkey]));

    expect($result->externalId)->toBe(recordUri($rkey));
    Http::assertSent(fn ($r) => str_ends_with($r->url(), 'createRecord') && $r['rkey'] === $rkey);
});

test('rkey ya usada (error del PDS no verificado): se confirma con getRecord antes de fallar', function () {
    $rkey = Tid::generate();
    Http::fake([
        'pds.example.com/xrpc/com.atproto.repo.createRecord' => Http::response(['error' => 'InvalidRequest', 'message' => 'Record already exists'], 400),
        'pds.example.com/xrpc/com.atproto.repo.getRecord*' => Http::response(['uri' => recordUri($rkey), 'cid' => 'c', 'value' => []]),
    ]);

    $result = $this->publisher->publish($this->account, bskyTextRequest(['rkey' => $rkey]));

    expect($result->isPublished())->toBeTrue();
});

test('un rechazo real (el registro no existe) sigue fallando con su error', function () {
    $rkey = Tid::generate();
    Http::fake([
        'pds.example.com/xrpc/com.atproto.repo.createRecord' => Http::response(['error' => 'InvalidRequest', 'message' => 'Record/text too long'], 400),
        'pds.example.com/xrpc/com.atproto.repo.getRecord*' => Http::response(['error' => 'RecordNotFound', 'message' => 'x'], 400),
    ]);

    expect(fn () => $this->publisher->publish($this->account, bskyTextRequest(['rkey' => $rkey])))
        ->toThrow(PermanentPublishException::class, 'too long');
});

test('findPublished: sin rkey guardada no hay nada publicado y no se llama a la red', function () {
    Http::fake();

    expect($this->publisher->findPublished($this->account, bskyTextRequest()))->toBeNull();
    Http::assertNothingSent();
});

test('findPublished: RecordNotFound es null y un fallo de red es transitorio (no se publica a ciegas)', function () {
    $rkey = Tid::generate();
    Http::fake(['pds.example.com/xrpc/com.atproto.repo.getRecord*' => Http::response(['error' => 'RecordNotFound', 'message' => 'x'], 400)]);
    expect($this->publisher->findPublished($this->account, bskyTextRequest(['rkey' => $rkey])))->toBeNull();

    Http::fake(['pds.example.com/xrpc/com.atproto.repo.getRecord*' => fn () => throw new ConnectionException('timeout')]);
    expect(fn () => $this->publisher->findPublished($this->account, bskyTextRequest(['rkey' => $rkey])))->toThrow(TransientPublishException::class);
});

test('findPublished de un video aun procesandose devuelve el trabajo vivo', function () {
    $rkey = Tid::generate();
    Http::fake(['pds.example.com/xrpc/com.atproto.repo.getRecord*' => Http::response(['error' => 'RecordNotFound', 'message' => 'x'], 400)]);

    $found = $this->publisher->findPublished($this->account, bskyTextRequest(['rkey' => $rkey], reference: 'job-123'));

    expect($found->isProcessing())->toBeTrue()->and($found->reference)->toBe('job-123')->and($found->progress['rkey'])->toBe($rkey);
});
