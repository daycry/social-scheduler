<?php

use App\Connectors\ConnectorRegistry;
use App\Connectors\Contracts\CredentialConnector;
use App\Connectors\Data\PublishMedia;
use App\Connectors\Data\PublishRequest;
use App\Connectors\Exceptions\PermanentPublishException;
use App\Connectors\Exceptions\QuotaExceededException;
use App\Connectors\Exceptions\ReconnectRequiredException;
use App\Connectors\Exceptions\TransientPublishException;
use App\Connectors\Network;
use App\Connectors\Telegram\TelegramPublisher;
use App\Enums\ContentType;
use App\Enums\PostStatus;
use App\Jobs\DispatchDuePosts;
use App\Models\Brand;
use App\Models\MediaAsset;
use App\Models\PostTarget;
use App\Models\SocialAccount;
use App\Models\User;
use App\Services\Media\UrlGuard;
use App\Services\MediaValidator;
use App\Services\PostService;
use GuzzleHttp\Promise\PromiseInterface;
use GuzzleHttp\Psr7\Response as Psr7Response;
use Illuminate\Encryption\Encrypter;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Tests\Support\FixtureRecorder;

// T-87 · Telegram: conectar un canal con el token de un bot (getMe, getChat, getChatMember), publicar texto y foto, errores y secretos (el token va en la ruta).
// Todo con `Http::fake` y fixtures grabados (T-74): ninguna peticion sale a la red.

const TG_TOKEN = '123456789:TEST_token-abcdefghijklmnopqrstuv';

beforeEach(function () {
    Storage::fake('local');
    config(['filesystems.media' => 'local']);
    app()->instance(UrlGuard::class, new UrlGuard(fn () => ['149.154.167.220']));
    $this->user = User::factory()->create();
    $this->brand = Brand::factory()->for($this->user)->create();
    $this->url = route('brands.connections.credentials', [$this->brand, 'telegram']);
    $this->publisher = app(TelegramPublisher::class);
});

function tgOk(array $result): PromiseInterface
{
    return Http::response(['ok' => true, 'result' => $result]);
}

function tgFail(int $code, string $description, array $parameters = []): PromiseInterface
{
    return Http::response(['ok' => false, 'error_code' => $code, 'description' => $description] + ($parameters !== [] ? ['parameters' => $parameters] : []), $code);
}

/** Simula getMe, getChat y getChatMember de un canal donde el bot puede publicar; `$over` sustituye cualquiera de las tres. */
function tgFakeConnect(array $over = []): void
{
    Http::fake($over + [
        'api.telegram.org/bot'.TG_TOKEN.'/getMe' => tgOk(['id' => 777, 'is_bot' => true, 'first_name' => 'Mi bot', 'username' => 'mi_bot']),
        'api.telegram.org/bot'.TG_TOKEN.'/getChat' => tgOk(['id' => -1001234567890, 'type' => 'channel', 'title' => 'Mi canal', 'username' => 'micanal']),
        'api.telegram.org/bot'.TG_TOKEN.'/getChatMember' => tgOk(['status' => 'administrator', 'can_post_messages' => true, 'user' => ['id' => 777]]),
    ]);
}

function tgConnect(string $channel = '@micanal'): SocialAccount
{
    tgFakeConnect();
    test()->actingAs(test()->user)->post(test()->url, ['bot_token' => TG_TOKEN, 'channel' => $channel])->assertSessionHas('success');

    return SocialAccount::withoutUserScope()->where('network', 'telegram')->firstOrFail();
}

function tgAccount(array $meta = ['chat_username' => 'micanal'], array $over = []): SocialAccount
{
    return SocialAccount::factory()->for(test()->user)->create(array_merge([
        'brand_id' => test()->brand->id, 'network' => Network::Telegram, 'external_id' => '-1001234567890', 'name' => 'Mi canal',
        'access_token' => TG_TOKEN, 'refresh_token' => null, 'token_expires_at' => null, 'meta' => $meta,
    ], $over));
}

function tgPhoto(int $id = 1, int $width = 64, int $height = 48): PublishMedia
{
    $im = imagecreatetruecolor($width, $height);
    ob_start();
    imagejpeg($im);
    $bytes = (string) ob_get_clean();
    $path = "media/test/tg-$id.jpg";
    Storage::disk('local')->put($path, $bytes);

    return new PublishMedia(id: $id, kind: 'image', mime: 'image/jpeg', url: 'https://signed.example/'.$id, width: $width, height: $height, size: strlen($bytes), disk: 'local', path: $path);
}

function tgRequest(?string $text, array $media = [], ContentType $type = ContentType::TelegramText): PublishRequest
{
    return new PublishRequest(targetId: 7, type: $type, text: $text, media: $media);
}

function tgSent(string $method): array
{
    return Http::recorded()->filter(fn ($pair) => str_ends_with(parse_url($pair[0]->url(), PHP_URL_PATH), '/'.$method))->map(fn ($pair) => $pair[0])->values()->all();
}

// ------------------------------------------------------------------ registro

test('esta registrado como conector de credenciales que solo publica: sin comentarios ni metricas', function () {
    $registry = app(ConnectorRegistry::class);

    expect($registry->forNetwork(Network::Telegram))->toBeInstanceOf(CredentialConnector::class)
        ->and($registry->publisherFor(Network::Telegram))->toBeInstanceOf(TelegramPublisher::class)
        ->and($registry->commentNetworks())->not->toContain(Network::Telegram)
        ->and($registry->insightsFor(Network::Telegram))->toBeNull()
        ->and(ContentType::forNetwork(Network::Telegram))->toBe([ContentType::TelegramText, ContentType::TelegramPhoto]);
});

// ------------------------------------------------------------------ conectar

test('conecta un canal: comprueba el bot, el canal y su permiso, y guarda el token cifrado', function () {
    $account = tgConnect();

    expect($account->status)->toBe('activa')->and($account->external_id)->toBe('-1001234567890')->and($account->name)->toBe('Mi canal')
        ->and($account->username)->toBe('@micanal')->and($account->access_token)->toBe(TG_TOKEN)->and($account->token_expires_at)->toBeNull()
        ->and($account->meta)->toMatchArray(['bot_id' => '777', 'bot_username' => 'mi_bot', 'chat_type' => 'channel', 'chat_username' => 'micanal']);
    expect(DB::table('social_accounts')->first()->access_token)->not->toContain(TG_TOKEN);

    expect(tgSent('getChat')[0]->data())->toBe(['chat_id' => '@micanal'])
        ->and(tgSent('getChatMember')[0]->data())->toBe(['chat_id' => '-1001234567890', 'user_id' => '777']);

    $this->get(route('brands.connections.index', $this->brand))->assertOk()->assertDontSee(TG_TOKEN);
});

test('el creador del canal tambien puede publicar, y un canal con id numerico sin @usuario se guarda con su titulo y sin url publica', function () {
    tgFakeConnect([
        'api.telegram.org/bot'.TG_TOKEN.'/getChat' => tgOk(['id' => -1009, 'type' => 'channel', 'title' => 'Privado']),
        'api.telegram.org/bot'.TG_TOKEN.'/getChatMember' => tgOk(['status' => 'creator']),
    ]);

    $this->actingAs($this->user)->post($this->url, ['bot_token' => TG_TOKEN, 'channel' => '-1009'])->assertSessionHas('success');

    $account = SocialAccount::withoutUserScope()->first();
    expect($account->name)->toBe('Privado')->and($account->username)->toBeNull()->and($account->meta)->not->toHaveKey('chat_username');
});

test('un bot sin permisos de publicar se rechaza con instrucciones para hacerlo administrador', function (array $member) {
    tgFakeConnect(['api.telegram.org/bot'.TG_TOKEN.'/getChatMember' => tgOk($member)]);

    $this->actingAs($this->user)->post($this->url, ['bot_token' => TG_TOKEN, 'channel' => '@micanal'])->assertSessionHasErrors('credentials');

    expect(session('errors')->first('credentials'))->toContain('Administradores')->toContain('Publicar mensajes')->not->toContain(TG_TOKEN)
        ->and(SocialAccount::withoutUserScope()->count())->toBe(0);
})->with([
    'administrador sin can_post_messages' => [['status' => 'administrator', 'can_post_messages' => false]],
    'administrador sin el campo' => [['status' => 'administrator']],
    'miembro' => [['status' => 'member']],
    'expulsado' => [['status' => 'kicked']],
]);

test('solo se admiten canales: un grupo, un supergrupo o un chat privado se rechazan', function (string $type) {
    tgFakeConnect(['api.telegram.org/bot'.TG_TOKEN.'/getChat' => tgOk(['id' => -100, 'type' => $type, 'title' => 'Chat'])]);

    $this->actingAs($this->user)->post($this->url, ['bot_token' => TG_TOKEN, 'channel' => '@micanal'])->assertSessionHasErrors('credentials');

    expect(session('errors')->first('credentials'))->toContain('no es un canal')->and(tgSent('getChatMember'))->toBe([]);
})->with(['group', 'supergroup', 'private']);

test('un token rechazado (401) da un error claro sin reflejar el token', function () {
    tgFakeConnect(['api.telegram.org/bot'.TG_TOKEN.'/getMe' => tgFail(401, 'Unauthorized')]);

    $this->actingAs($this->user)->post($this->url, ['bot_token' => TG_TOKEN, 'channel' => '@micanal'])->assertSessionHasErrors('credentials');

    expect(session('errors')->first('credentials'))->toContain('rechazó el token')->not->toContain(TG_TOKEN)->and(SocialAccount::withoutUserScope()->count())->toBe(0);
});

test('un canal inexistente da un error claro', function () {
    tgFakeConnect(['api.telegram.org/bot'.TG_TOKEN.'/getChat' => tgFail(400, 'Bad Request: chat not found')]);
    $this->actingAs($this->user)->post($this->url, ['bot_token' => TG_TOKEN, 'channel' => '@noexiste'])->assertSessionHasErrors('credentials');
    expect(session('errors')->first('credentials'))->toContain('no encuentra ese canal')->and(SocialAccount::withoutUserScope()->count())->toBe(0);
});

test('el token y el canal con una forma que no es la de Telegram no llegan a la red', function (string $token, string $channel) {
    Http::fake();

    $this->actingAs($this->user)->post($this->url, ['bot_token' => $token, 'channel' => $channel])->assertSessionHasErrors('credentials');

    Http::assertNothingSent();
})->with([
    'token con barra (alteraria la ruta)' => ['123456789:abc/../../x'.str_repeat('a', 20), '@micanal'],
    'token sin dos puntos' => ['sintoken', '@micanal'],
    'canal con espacios' => [TG_TOKEN, '@mi canal'],
    'canal que es una URL' => [TG_TOKEN, 'https://t.me/micanal'],
]);

test('faltan campos: errores por campo y el token no se refleja', function () {
    $this->actingAs($this->user)->post($this->url, ['channel' => '@micanal'])->assertSessionHasErrors('bot_token');

    expect(session()->getOldInput())->not->toHaveKey('bot_token');
});

test('desconectar borra el token y deja la cuenta desconectada', function () {
    $account = tgConnect();

    $this->actingAs($this->user)->delete(route('brands.connections.destroy', [$this->brand, $account]))->assertRedirect();

    $account->refresh();
    expect($account->status)->toBe('desconectada')->and($account->access_token)->toBeNull();
});

// ------------------------------------------------------------------ limites

test('el texto de un mensaje admite 4096 caracteres y el pie de una foto 1024, validados al guardar', function () {
    $validator = app(MediaValidator::class);
    $photo = MediaAsset::factory()->for($this->user)->create();

    expect($validator->validate(ContentType::TelegramText, [], str_repeat('a', 4096)))->toBe([])
        ->and($validator->validate(ContentType::TelegramText, [], str_repeat('a', 4097)))->not->toBeEmpty()
        ->and($validator->validate(ContentType::TelegramText, [], ''))->not->toBeEmpty()
        ->and($validator->validate(ContentType::TelegramPhoto, [$photo], str_repeat('a', 1024)))->toBe([])
        ->and($validator->validate(ContentType::TelegramPhoto, [$photo], str_repeat('a', 1025)))->not->toBeEmpty()
        ->and($validator->validate(ContentType::TelegramPhoto, [$photo], null))->toBe([]);
});

test('una sola foto de hasta 10 MB; varias, otra clase de media o mas peso se rechazan', function () {
    $validator = app(MediaValidator::class);
    $photo = fn (array $over = []) => MediaAsset::factory()->for($this->user)->create($over);

    expect(implode(' ', $validator->validate(ContentType::TelegramPhoto, [$photo(), $photo()], 'x')))->toContain('como maximo 1')
        ->and($validator->validate(ContentType::TelegramPhoto, [], 'x'))->not->toBeEmpty()
        ->and($validator->validate(ContentType::TelegramPhoto, [$photo(['size' => 11 * 1024 * 1024])], 'x'))->not->toBeEmpty()
        ->and($validator->validate(ContentType::TelegramPhoto, [$photo(['width' => 100, 'height' => 3000])], 'x'))->not->toBeEmpty()
        ->and($validator->validate(ContentType::TelegramPhoto, [$photo(['size' => 10 * 1024 * 1024, 'width' => 1000, 'height' => 800])], 'x'))->toBe([])
        ->and($validator->validate(ContentType::TelegramText, [$photo()], 'x'))->not->toBeEmpty();
});

// ------------------------------------------------------------------ publicar

test('publica texto con sendMessage como texto plano, sin parse_mode, y devuelve la url del canal publico', function () {
    $account = tgAccount();
    Http::fake(['api.telegram.org/bot'.TG_TOKEN.'/sendMessage' => tgOk(['message_id' => 321, 'date' => 1790000000, 'chat' => ['id' => -1001234567890, 'type' => 'channel']])]);

    $result = $this->publisher->publish($account, tgRequest("  Hola <b>canal</b> & *amigos*_ \n"));

    expect($result->isPublished())->toBeTrue()->and($result->externalId)->toBe('321')->and($result->url)->toBe('https://t.me/micanal/321');
    [$sent] = tgSent('sendMessage');
    expect($sent->data())->toBe(['chat_id' => '-1001234567890', 'text' => 'Hola <b>canal</b> & *amigos*_'])->not->toHaveKey('parse_mode');
    FixtureRecorder::assertRecorded('telegram/success');
});

test('un canal sin @usuario no tiene url publica: queda null', function () {
    $account = tgAccount([]);
    Http::fake(['api.telegram.org/*' => tgOk(['message_id' => 5])]);

    $result = $this->publisher->publish($account, tgRequest('hola'));

    expect($result->isPublished())->toBeTrue()->and($result->externalId)->toBe('5')->and($result->url)->toBeNull();
});

test('publica una foto con sendPhoto por multipart y el texto como caption', function () {
    $account = tgAccount();
    Http::fake(['api.telegram.org/bot'.TG_TOKEN.'/sendPhoto' => tgOk(['message_id' => 322, 'photo' => [['file_id' => 'x']]])]);

    $result = $this->publisher->publish($account, tgRequest('Una foto', [tgPhoto()], ContentType::TelegramPhoto));

    expect($result->isPublished())->toBeTrue()->and($result->url)->toBe('https://t.me/micanal/322');
    [$sent] = tgSent('sendPhoto');
    $parts = collect($sent->data())->keyBy('name');
    expect($sent->isMultipart())->toBeTrue()->and($parts['chat_id']['contents'])->toBe('-1001234567890')->and($parts['caption']['contents'])->toBe('Una foto')
        ->and($parts)->toHaveKey('photo')->not->toHaveKey('parse_mode')->and(strlen($parts['photo']['contents']))->toBeGreaterThan(100);
    expect(tgSent('sendMessage'))->toBe([]);
});

test('una foto sin texto se envia sin caption', function () {
    $account = tgAccount();
    Http::fake(['api.telegram.org/*' => tgOk(['message_id' => 9])]);

    $this->publisher->publish($account, tgRequest(null, [tgPhoto()], ContentType::TelegramPhoto));

    expect(collect(tgSent('sendPhoto')[0]->data())->pluck('name')->all())->not->toContain('caption');
});

test('una foto con ancho + alto por encima de 10000 px no se envia', function () {
    $account = tgAccount();
    Http::fake();

    expect(fn () => $this->publisher->publish($account, tgRequest('x', [tgPhoto(1, 5001, 5001)], ContentType::TelegramPhoto)))->toThrow(PermanentPublishException::class);

    Http::assertNothingSent();
});

test('un tipo que no es el suyo se rechaza sin llamar a la red', function () {
    Http::fake();

    expect(fn () => $this->publisher->publish(tgAccount(), tgRequest('x', [], ContentType::BlueskyPost)))->toThrow(PermanentPublishException::class);

    Http::assertNothingSent();
});

// ------------------------------------------------------------------ errores

test('retry_after (429): cuota con espera de esos segundos', function () {
    Http::fake(['api.telegram.org/*' => tgFail(429, 'Too Many Requests: retry after 5', ['retry_after' => 5])]);

    try {
        $this->publisher->publish(tgAccount(), tgRequest('hola'));
        $this->fail('Debia fallar');
    } catch (QuotaExceededException $e) {
        expect((int) round(now()->diffInSeconds($e->retryAt, false)))->toBeBetween(4, 6)->and($e->getMessage())->not->toContain(TG_TOKEN);
    }
    FixtureRecorder::assertRecorded('telegram/error');
});

test('401, 403, «bot was kicked» y «not enough rights»: la cuenta necesita reconectarse', function (int $status, string $description) {
    Http::fake(['api.telegram.org/*' => tgFail($status, $description)]);

    expect(fn () => $this->publisher->publish(tgAccount(), tgRequest('hola')))->toThrow(ReconnectRequiredException::class);
})->with([
    '401' => [401, 'Unauthorized'],
    '403' => [403, 'Forbidden: bot was blocked by the user'],
    'expulsado' => [400, 'Bad Request: bot was kicked from the channel chat'],
    'sin permisos' => [400, 'Bad Request: not enough rights to send text messages to the chat'],
]);

test('el resto de errores del contenido son permanentes con el motivo de Telegram', function () {
    Http::fake(['api.telegram.org/*' => tgFail(400, 'Bad Request: message text is empty')]);

    try {
        $this->publisher->publish(tgAccount(), tgRequest('hola'));
        $this->fail('Debia fallar');
    } catch (PermanentPublishException $e) {
        expect($e->getMessage())->toBe('Telegram rechazó la publicación: Bad Request: message text is empty');
    }
});

test('5xx y una conexion que no llego a salir (rechazada, DNS) son transitorios', function () {
    Http::fake(['api.telegram.org/*' => Http::sequence()->push(['ok' => false, 'error_code' => 502, 'description' => 'Bad Gateway'], 502)->pushFailedConnection('cURL error 6: Could not resolve host: api.telegram.org')]);

    $account = tgAccount();

    expect(fn () => $this->publisher->publish($account, tgRequest('hola')))->toThrow(TransientPublishException::class)
        ->and(fn () => $this->publisher->publish($account, tgRequest('hola')))->toThrow(TransientPublishException::class);
});

// ------------------------------------------------------------------ el token va en la ruta: nunca se registra

test('el token nunca aparece en logs, excepciones ni trazas', function () {
    $logged = [];
    Log::listen(function ($event) use (&$logged) {
        $logged[] = $event->message.json_encode($event->context);
    });
    $account = tgAccount();
    Http::fake(['api.telegram.org/*' => Http::sequence()
        ->push(['ok' => false, 'error_code' => 500, 'description' => 'x'], 500)
        ->push(['ok' => false, 'error_code' => 401, 'description' => 'Unauthorized'], 401)
        ->push(['ok' => false, 'error_code' => 400, 'description' => 'Bad Request'], 400)
        ->push(['ok' => false, 'error_code' => 429, 'description' => 'Too Many Requests', 'parameters' => ['retry_after' => 3]], 429)
        ->pushFailedConnection('cURL error 28: timed out for https://api.telegram.org/bot'.TG_TOKEN.'/sendMessage')]);
    $seen = [];

    foreach (range(1, 5) as $i) {
        try {
            $this->publisher->publish($account, tgRequest('hola'));
        } catch (Throwable $e) {
            $seen[] = (string) $e;
        }
    }
    tgFakeConnect(['api.telegram.org/bot'.TG_TOKEN.'/getMe' => tgFail(401, 'Unauthorized')]);
    $this->actingAs($this->user)->post($this->url, ['bot_token' => TG_TOKEN, 'channel' => '@micanal']);

    expect($seen)->toHaveCount(5)->and(implode("\n", $seen))->not->toContain(TG_TOKEN)->not->toContain('api.telegram.org/bot')
        ->and(implode("\n", $logged))->not->toContain(TG_TOKEN);
});

test('flujo completo con la cola: conectar, programar y publicar; un fallo de red no deja el token en ningun registro', function () {
    Carbon::setTestNow('2026-11-26 12:00:00');
    $account = tgConnect();
    $post = app(PostService::class)->create($this->user, $this->brand, [
        'text' => 'Hola canal', 'scheduled_at' => now()->addHour(), 'schedule' => true, 'media' => [],
        'targets' => [['account_id' => $account->id, 'content_type' => 'telegram_text', 'text' => null]],
    ]);
    $targetId = $post->targets->first()->id;
    $logged = [];
    Log::listen(function ($event) use (&$logged) {
        $logged[] = $event->message.json_encode($event->context);
    });
    Http::fake(['api.telegram.org/bot'.TG_TOKEN.'/sendMessage' => Http::sequence()
        ->pushFailedConnection('cURL error 7: Failed to connect to api.telegram.org port 443 for https://api.telegram.org/bot'.TG_TOKEN.'/sendMessage')
        ->push(['ok' => true, 'result' => ['message_id' => 55, 'date' => 1790000000]])]);

    Carbon::setTestNow('2026-11-26 13:01:00');
    app(DispatchDuePosts::class)();
    $target = PostTarget::withoutUserScope()->find($targetId);
    expect($target->status)->toBe(PostStatus::Scheduled)->and($target->error_message)->not->toContain(TG_TOKEN);

    Carbon::setTestNow('2026-11-26 14:00:00');
    app(DispatchDuePosts::class)();
    $target = PostTarget::withoutUserScope()->find($targetId);
    expect($target->status)->toBe(PostStatus::Published)->and($target->external_id)->toBe('55')->and($target->external_url)->toBe('https://t.me/micanal/55');

    foreach (['post_targets', 'publish_attempts', 'failed_jobs', 'jobs'] as $table) {
        expect(DB::table($table)->get()->toJson())->not->toContain(TG_TOKEN);
    }
    expect(implode("\n", $logged))->not->toContain(TG_TOKEN);
});

test('app:reencrypt recifra el token del bot con la clave actual', function () {
    $cipher = config('app.cipher');
    $old = new Encrypter(str_repeat('o', 32), $cipher);
    $current = new Encrypter(str_repeat('n', 32), $cipher);
    Crypt::swap((new Encrypter($current->getKey(), $cipher))->previousKeys([$old->getKey()]));
    $account = tgAccount();
    DB::table('social_accounts')->where('id', $account->id)->update(['access_token' => $old->encryptString(TG_TOKEN)]);

    $this->artisan('app:reencrypt')->expectsOutput('Registros recifrados: 1')->assertSuccessful();

    $stored = DB::table('social_accounts')->where('id', $account->id)->value('access_token');
    expect($current->decryptString($stored))->toBe(TG_TOKEN);
});

// ------------------------------------------------------------------ G3: un timeout tras enviar es ambiguo: no se reintenta

test('G3: un timeout tras enviar (resultado incierto) no se reintenta: falla pidiendo revisar el canal', function (string $error) {
    Http::fake(['api.telegram.org/*' => Http::sequence()->pushFailedConnection($error)]);

    try {
        $this->publisher->publish(tgAccount(), tgRequest('hola'));
        $this->fail('Debia fallar');
    } catch (PermanentPublishException $e) {
        expect($e->errorCode)->toBe('resultado_incierto')->and($e->getMessage())->toContain('revisa el canal')->not->toContain(TG_TOKEN);
    }
})->with([
    'timeout' => 'cURL error 28: Operation timed out after 15001 milliseconds with 0 bytes received',
    'respuesta vacia' => 'cURL error 52: Empty reply from server',
    'corte de lectura' => 'cURL error 56: Recv failure: Connection reset by peer',
    'sin codigo' => 'Connection failed',
]);

test('G3: el job no vuelve a enviar tras un timeout ambiguo: queda fallido con el motivo y un solo envio', function () {
    Carbon::setTestNow('2026-11-26 12:00:00');
    $account = tgConnect();
    $post = app(PostService::class)->create($this->user, $this->brand, [
        'text' => 'Hola canal', 'scheduled_at' => now()->addHour(), 'schedule' => true, 'media' => [],
        'targets' => [['account_id' => $account->id, 'content_type' => 'telegram_text', 'text' => null]],
    ]);
    $targetId = $post->targets->first()->id;
    Http::fake(['api.telegram.org/bot'.TG_TOKEN.'/sendMessage' => Http::sequence()->pushFailedConnection('cURL error 28: Operation timed out')->push(['ok' => true, 'result' => ['message_id' => 1]])]);

    Carbon::setTestNow('2026-11-26 13:01:00');
    app(DispatchDuePosts::class)();
    Carbon::setTestNow('2026-11-26 15:00:00');
    app(DispatchDuePosts::class)();

    $target = PostTarget::withoutUserScope()->find($targetId);
    expect($target->status)->toBe(PostStatus::Failed)->and($target->error_code)->toBe('resultado_incierto')->and($target->error_message)->toContain('revisa el canal')
        ->and(tgSent('sendMessage'))->toHaveCount(1);
});

test('una RequestException con el cuerpo cortado al conectar da un error claro y no un 500', function () {
    tgFakeConnect(['api.telegram.org/bot'.TG_TOKEN.'/getMe' => fn () => throw new RequestException(new Response(new Psr7Response(502, [], '{"ok":true,"res')))]);

    $this->actingAs($this->user)->post($this->url, ['bot_token' => TG_TOKEN, 'channel' => '@micanal'])->assertRedirect()->assertSessionHasErrors('credentials');

    expect(session('errors')->first('credentials'))->not->toContain(TG_TOKEN)->and(SocialAccount::withoutUserScope()->count())->toBe(0);
});

test('G5: el servidor de la Bot API es fijo (https://api.telegram.org): no hay ajuste para cambiarlo', function () {
    expect(config('social.telegram'))->not->toHaveKey('api_host');
    Http::fake(['api.telegram.org/*' => tgOk(['message_id' => 1])]);

    $this->publisher->publish(tgAccount(), tgRequest('hola'));

    expect(tgSent('sendMessage')[0]->url())->toStartWith('https://api.telegram.org/bot');
});
