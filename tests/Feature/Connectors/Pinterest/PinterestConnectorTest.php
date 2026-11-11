<?php

use App\Connectors\Contracts\OAuthConnector;
use App\Connectors\Contracts\StatusPoller;
use App\Connectors\Data\PublishMedia;
use App\Connectors\Data\PublishRequest;
use App\Connectors\Exceptions\ConnectorException;
use App\Connectors\Exceptions\PermanentPublishException;
use App\Connectors\Exceptions\QuotaExceededException;
use App\Connectors\Exceptions\ReconnectRequiredException;
use App\Connectors\Exceptions\TransientPublishException;
use App\Connectors\Network;
use App\Connectors\Pinterest\PinterestConnector;
use App\Connectors\Pinterest\PinterestPublisher;
use App\Enums\ContentType;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\Support\NewNetworksFake;

beforeEach(function () {
    Http::preventStrayRequests();
    Storage::fake('local');
    NewNetworksFake::pinterest();
    $this->connector = app(PinterestConnector::class);
    $this->publisher = app(PinterestPublisher::class);
});

function piRequest(ContentType $type, ?string $text, array $media, array $options = [], ?string $reference = null): PublishRequest
{
    return new PublishRequest(targetId: 1, type: $type, text: $text, media: $media, options: $options + ['pinterest_board_id' => '549755885175'], reference: $reference, since: now()->subMinute());
}

function piImage(int $id, ?string $alt = null): PublishMedia
{
    return new PublishMedia(id: $id, kind: 'image', mime: 'image/jpeg', url: 'https://signed.example/i'.$id.'.jpg', alt: $alt);
}

function piVideo(?PublishMedia $cover = null): PublishMedia
{
    Storage::disk('local')->put('m/v.mp4', 'VIDEOBYTES');

    return new PublishMedia(id: 5, kind: 'video', mime: 'video/mp4', url: 'https://signed.example/v.mp4', size: 10, disk: 'local', path: 'm/v.mp4', cover: $cover);
}

function piCreated(): ?array
{
    $body = null;
    Http::assertSent(function (Request $r) use (&$body) {
        if (str_ends_with($r->url(), '/v5/pins') && $r->method() === 'POST') {
            $body = $r->data();
        }

        return true;
    });

    return $body;
}

test('conector OAuth de pinterest con su red, margen y URL de autorizacion con state y permisos, sin secreto', function () {
    $url = $this->connector->authorizationUrl('ST1', 'https://app.test/connections/pinterest/callback');

    expect($this->connector)->toBeInstanceOf(OAuthConnector::class)->and($this->connector->provider())->toBe('pinterest')
        ->and($this->connector->networks())->toBe([Network::Pinterest])->and($this->connector->refreshMarginHours())->toBe(72)
        ->and($url)->toStartWith('https://www.pinterest.com/oauth/?')->toContain('client_id=PIAPP1')->toContain('state=ST1')->toContain('response_type=code')
        ->toContain('pins%3Awrite')->toContain('redirect_uri='.rawurlencode('https://app.test/connections/pinterest/callback'))->not->toContain('PISECRET1');
});

test('canjea el codigo con Basic, lee la cuenta y guarda los tableros', function () {
    Http::fake([
        'api.pinterest.com/v5/oauth/token' => Http::response(['access_token' => 'PI-A', 'refresh_token' => 'PI-R', 'expires_in' => 2592000, 'refresh_token_expires_in' => 5184000, 'scope' => 'boards:read pins:write']),
        'api.pinterest.com/v5/user_account' => Http::response(['username' => 'ana_pins', 'account_type' => 'BUSINESS']),
        'api.pinterest.com/v5/boards*' => Http::response(['items' => [['id' => '549755885175', 'name' => 'Recetas'], ['id' => 'no-numerico', 'name' => 'X']]]),
    ]);

    [$c] = $this->connector->exchangeCode('CODE1', 'https://app.test/connections/pinterest/callback');

    expect($c->network)->toBe(Network::Pinterest)->and($c->externalId)->toBe('ana_pins')->and($c->accessToken)->toBe('PI-A')->and($c->refreshToken)->toBe('PI-R')
        ->and($c->tokenExpiresAt->isFuture())->toBeTrue()->and($c->refreshExpiresAt->isFuture())->toBeTrue()->and($c->scopes)->toBe(['boards:read', 'pins:write'])
        ->and($c->meta['boards'])->toBe([['id' => '549755885175', 'name' => 'Recetas']]);
    Http::assertSent(fn (Request $r) => str_ends_with($r->url(), '/v5/oauth/token') && $r->header('Authorization') === ['Basic '.base64_encode('PIAPP1:PISECRET1')]
        && $r['grant_type'] === 'authorization_code' && $r['code'] === 'CODE1');
});

test('sin permiso de tableros la cuenta se conecta igualmente', function () {
    Http::fake([
        'api.pinterest.com/v5/oauth/token' => Http::response(['access_token' => 'PI-A', 'refresh_token' => 'PI-R', 'expires_in' => 2592000]),
        'api.pinterest.com/v5/user_account' => Http::response(['username' => 'ana_pins']),
        'api.pinterest.com/v5/boards*' => Http::response(['message' => 'forbidden'], 403),
    ]);

    expect($this->connector->exchangeCode('C', 'https://app.test/cb')[0]->meta['boards'])->toBe([]);
});

test('errores del canje: legible, y un refresh invalido pide reconectar', function () {
    Http::fake(['api.pinterest.com/v5/oauth/token' => Http::response(['message' => 'Invalid redirect uri'], 400)]);
    expect(fn () => $this->connector->exchangeCode('MAL', 'https://app.test/cb'))->toThrow(ConnectorException::class, 'Pinterest rechazo la conexion');
});

test('refresh canjea el refresh_token y guarda el nuevo', function () {
    Http::fake(['api.pinterest.com/v5/oauth/token' => Http::response(['access_token' => 'PI-2', 'refresh_token' => 'PI-R2', 'expires_in' => 2592000, 'refresh_token_expires_in' => 5184000])]);
    $account = NewNetworksFake::pinterestAccount();

    $r = $this->connector->refresh($account);

    expect($r->accessToken)->toBe('PI-2')->and($r->refreshToken)->toBe('PI-R2')->and($r->refreshExpiresAt->isFuture())->toBeTrue();
    Http::assertSent(fn (Request $r) => $r['grant_type'] === 'refresh_token' && $r['refresh_token'] === 'PI-REFRESH');
});

test('un refresh revocado pide reconectar', function () {
    Http::fake(['api.pinterest.com/v5/oauth/token' => Http::response(['error' => 'invalid_grant', 'message' => 'revoked'], 400)]);

    expect(fn () => $this->connector->refresh(NewNetworksFake::pinterestAccount()))->toThrow(ReconnectRequiredException::class);
    expect(fn () => $this->connector->refresh(NewNetworksFake::pinterestAccount(['refresh_token' => null])))->toThrow(ReconnectRequiredException::class, 'reconecta');
});

test('sin credenciales de la app la red no se ofrece', function () {
    config(['services.pinterest.client_id' => '']);

    expect(Network::Pinterest->isAvailable())->toBeFalse()->and(Network::availableValues())->not->toContain('pinterest');
});

test('un pin de imagen lleva tablero, titulo derivado, descripcion, enlace y alt', function () {
    Http::fake(['api.pinterest.com/v5/pins' => Http::response(['id' => '9001'], 201)]);
    $this->publisher->publish(NewNetworksFake::pinterestAccount(), piRequest(ContentType::PinterestPin, "Tarta de queso\nReceta facil", [piImage(1, 'Una tarta')], ['pinterest_link' => 'https://example.com/receta']));

    expect(piCreated())->toBe([
        'board_id' => '549755885175', 'title' => 'Tarta de queso', 'description' => "Tarta de queso\nReceta facil", 'link' => 'https://example.com/receta', 'alt_text' => 'Una tarta',
        'media_source' => ['source_type' => 'image_url', 'url' => 'https://signed.example/i1.jpg'],
    ]);
    Http::assertSent(fn (Request $r) => $r->header('Authorization') === ['Bearer PI-TOKEN']);
});

test('el titulo explicito manda sobre el derivado y el resultado trae el id y la URL del pin', function () {
    Http::fake(['api.pinterest.com/v5/pins' => Http::response(['id' => '9002'], 201)]);
    $r = $this->publisher->publish(NewNetworksFake::pinterestAccount(), piRequest(ContentType::PinterestPin, 'Texto', [piImage(1)], ['pinterest_title' => 'Titulo propio']));

    expect(piCreated()['title'])->toBe('Titulo propio')->and($r->externalId)->toBe('9002')->and($r->url)->toBe('https://www.pinterest.com/pin/9002/');
});

test('un carrusel usa multiple_image_urls en el orden dado', function () {
    Http::fake(['api.pinterest.com/v5/pins' => Http::response(['id' => '9003'], 201)]);
    $this->publisher->publish(NewNetworksFake::pinterestAccount(), piRequest(ContentType::PinterestCarousel, 'Set', [piImage(1), piImage(2)]));

    expect(piCreated()['media_source'])->toBe(['source_type' => 'multiple_image_urls', 'items' => [['url' => 'https://signed.example/i1.jpg'], ['url' => 'https://signed.example/i2.jpg']]]);
});

test('un video se registra, se sube con los parametros prefirmados y queda procesando; poll crea el pin con la portada', function () {
    Http::fake([
        'api.pinterest.com/v5/media' => Http::response(['media_id' => '8001', 'upload_url' => 'https://pinterest-media-upload.s3-accelerate.amazonaws.com/', 'upload_parameters' => ['key' => 'k1', 'policy' => 'p1']], 201),
        'pinterest-media-upload.s3-accelerate.amazonaws.com/*' => Http::response('', 204),
        'api.pinterest.com/v5/media/8001' => Http::sequence()->push(['status' => 'processing'])->push(['status' => 'succeeded']),
        'api.pinterest.com/v5/pins?page_size=25' => Http::response(['items' => []]),
        'api.pinterest.com/v5/pins' => Http::response(['id' => '9004'], 201),
    ]);
    $account = NewNetworksFake::pinterestAccount();
    $cover = piImage(7);
    $request = piRequest(ContentType::PinterestVideo, 'Video', [piVideo($cover)]);

    $first = $this->publisher->publish($account, $request);
    expect($first->isProcessing())->toBeTrue()->and($first->reference)->toBe('8001')->and($this->publisher)->toBeInstanceOf(StatusPoller::class);
    // El video va como stream (Guzzle lo cierra al enviarlo: no se relee el cuerpo); el contenido en streaming se cubre en PinterestVideoStreamingTest.
    Http::assertSent(fn (Request $r) => str_contains($r->url(), 'amazonaws.com') && collect($r->data())->pluck('name')->all() === ['key', 'policy', 'file']);

    $request = piRequest(ContentType::PinterestVideo, 'Video', [piVideo($cover)], reference: '8001');
    expect($this->publisher->poll($account, $request)->isProcessing())->toBeTrue();
    $done = $this->publisher->poll($account, $request);
    expect($done->isPublished())->toBeTrue()->and($done->externalId)->toBe('9004')
        ->and(piCreated()['media_source'])->toBe(['source_type' => 'video_id', 'media_id' => '8001', 'cover_image_url' => 'https://signed.example/i7.jpg']);
});

test('un video que Pinterest no procesa falla de forma permanente; una URL de subida ajena no recibe el video', function () {
    Http::fake(['api.pinterest.com/v5/media/8002' => Http::response(['status' => 'failed', 'failure_code' => 'VIDEO_TOO_LONG'])]);
    expect(fn () => $this->publisher->poll(NewNetworksFake::pinterestAccount(), piRequest(ContentType::PinterestVideo, 'V', [piVideo()], reference: '8002')))
        ->toThrow(PermanentPublishException::class, 'VIDEO_TOO_LONG');

    Http::fake(['api.pinterest.com/v5/media' => Http::response(['media_id' => '8003', 'upload_url' => 'https://evil.example/up', 'upload_parameters' => []], 201)]);
    expect(fn () => $this->publisher->publish(NewNetworksFake::pinterestAccount(), piRequest(ContentType::PinterestVideo, 'V', [piVideo()])))->toThrow(PermanentPublishException::class, 'URL de subida');
    Http::assertNotSent(fn (Request $r) => str_contains($r->url(), 'evil.example'));
});

test('findPublished reconoce el pin ya creado por tablero, titulo y descripcion, y poll no lo duplica', function () {
    Http::fake([
        'api.pinterest.com/v5/pins?page_size=25' => Http::response(['items' => [
            ['id' => 'OLD', 'board_id' => '549755885175', 'title' => 'Hola', 'description' => 'Hola', 'created_at' => now()->subDay()->toIso8601String()],
            ['id' => 'NEW', 'board_id' => '549755885175', 'title' => 'Hola', 'description' => 'Hola', 'created_at' => now()->toIso8601String()],
        ]]),
    ]);

    $found = $this->publisher->findPublished(NewNetworksFake::pinterestAccount(), piRequest(ContentType::PinterestPin, 'Hola', [piImage(1)]));
    expect($found?->externalId)->toBe('NEW');
    expect($this->publisher->findPublished(NewNetworksFake::pinterestAccount(), piRequest(ContentType::PinterestPin, 'Otro', [piImage(1)])))->toBeNull();
});

test('el tablero es obligatorio y los limites de texto se rechazan antes de llamar a la red', function () {
    $account = NewNetworksFake::pinterestAccount();
    $noBoard = new PublishRequest(targetId: 1, type: ContentType::PinterestPin, text: 'x', media: [piImage(1)], options: []);

    expect(fn () => $this->publisher->publish($account, $noBoard))->toThrow(PermanentPublishException::class, 'tablero');
    expect(fn () => $this->publisher->publish($account, piRequest(ContentType::PinterestPin, str_repeat('a', 801), [piImage(1)])))->toThrow(PermanentPublishException::class, '800');
    expect(fn () => $this->publisher->publish($account, piRequest(ContentType::ThreadsText, 'x', [piImage(1)])))->toThrow(PermanentPublishException::class, 'no puede publicar');
    Http::assertNothingSent();
});

test('los errores de Pinterest se traducen a la excepcion que la cola entiende', function (int $status, string $class) {
    Http::fake(['api.pinterest.com/v5/pins' => Http::response(['code' => 1, 'message' => 'boom'], $status)]);

    expect(fn () => $this->publisher->publish(NewNetworksFake::pinterestAccount(), piRequest(ContentType::PinterestPin, 'x', [piImage(1)])))->toThrow($class);
})->with([[401, ReconnectRequiredException::class], [403, PermanentPublishException::class], [400, PermanentPublishException::class], [429, QuotaExceededException::class], [500, TransientPublishException::class]]);
