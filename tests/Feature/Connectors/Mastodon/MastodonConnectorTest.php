<?php

use App\Connectors\ConnectorRegistry;
use App\Connectors\Contracts\CredentialConnector;
use App\Connectors\Data\PublishMedia;
use App\Connectors\Data\PublishRequest;
use App\Connectors\Exceptions\PermanentPublishException;
use App\Connectors\Exceptions\QuotaExceededException;
use App\Connectors\Exceptions\ReconnectRequiredException;
use App\Connectors\Exceptions\TransientPublishException;
use App\Connectors\Mastodon\MastodonClient;
use App\Connectors\Mastodon\MastodonException;
use App\Connectors\Mastodon\MastodonPublisher;
use App\Connectors\Network;
use App\Connectors\Support\StepBudget;
use App\Enums\ContentType;
use App\Models\Brand;
use App\Models\MediaAsset;
use App\Models\SocialAccount;
use App\Models\User;
use App\Services\Media\UrlGuard;
use App\Services\MediaValidator;
use Carbon\CarbonImmutable;
use GuzzleHttp\Psr7\Response as Psr7Response;
use Illuminate\Encryption\Encrypter;
use Illuminate\Http\Client\Request;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Tests\Support\FixtureRecorder;

// T-87 · Mastodon: conectar con instancia + token, limites de la instancia, publicar texto e imagen (incluido el 202 con sondeo), errores y secretos.
// Todo con `Http::fake` y fixtures grabados (T-74): ninguna peticion sale a la red.

const MA_TOKEN = 'MA-TOKEN-SECRET-0123456789';

beforeEach(function () {
    Storage::fake('local');
    config(['filesystems.media' => 'local', 'social.mastodon.media_poll_delay_ms' => 0, 'social.mastodon.media_poll_attempts' => 3]);
    // DNS simulado para la guardia SSRF: todo host con nombre resuelve a una IP publica salvo los de la lista (privados).
    app()->instance(UrlGuard::class, new UrlGuard(fn (string $host) => $host === 'interno.example' ? ['10.0.0.5'] : ['93.184.216.34']));
    $this->user = User::factory()->create();
    $this->brand = Brand::factory()->for($this->user)->create();
    $this->url = route('brands.connections.credentials', [$this->brand, 'mastodon']);
    $this->publisher = app(MastodonPublisher::class);
});

/** @param array<string, mixed> $over */
function maInstanceJson(array $over = []): array
{
    return array_replace_recursive([
        'domain' => 'mastodon.example',
        'configuration' => [
            'statuses' => ['max_characters' => 300, 'max_media_attachments' => 2, 'characters_reserved_per_url' => 23],
            'media_attachments' => ['supported_mime_types' => ['image/jpeg', 'image/png', 'video/mp4'], 'image_size_limit' => 2_000_000, 'description_limit' => 100],
        ],
    ], $over);
}

function maFakeConnect(array $extra = []): void
{
    Http::fake($extra + [
        'mastodon.example/api/v1/accounts/verify_credentials' => Http::response(['id' => '109', 'username' => 'ana', 'acct' => 'ana', 'display_name' => 'Ana', 'avatar' => 'https://mastodon.example/avatars/ana.png', 'url' => 'https://mastodon.example/@ana']),
        'mastodon.example/api/v2/instance' => Http::response(maInstanceJson()),
    ]);
}

function maConnect(string $instance = 'mastodon.example'): SocialAccount
{
    maFakeConnect();
    test()->actingAs(test()->user)->post(test()->url, ['instance_url' => $instance, 'access_token' => MA_TOKEN])->assertSessionHas('success');

    return SocialAccount::withoutUserScope()->where('network', 'mastodon')->firstOrFail();
}

/** Cuenta ya conectada (sin pasar por el formulario), con los limites que se indiquen. */
function maAccount(array $limits = [], array $over = []): SocialAccount
{
    $fresh = ['fetched_at' => now()->toIso8601String()];

    return SocialAccount::factory()->for(test()->user)->create(array_merge([
        'brand_id' => test()->brand->id, 'network' => Network::Mastodon, 'external_id' => 'mastodon.example:109', 'name' => 'ana@mastodon.example',
        'access_token' => MA_TOKEN, 'refresh_token' => null, 'token_expires_at' => null,
        'meta' => ['instance' => 'https://mastodon.example', 'limits' => $limits + $fresh + ['max_characters' => 300, 'max_media_attachments' => 2, 'image_size_limit' => 2_000_000, 'description_limit' => 100]],
    ], $over));
}

function maImage(int $id, string $alt = ''): PublishMedia
{
    $im = imagecreatetruecolor(64, 48);
    ob_start();
    imagejpeg($im);
    $bytes = (string) ob_get_clean();
    $path = "media/test/ma-$id.jpg";
    Storage::disk('local')->put($path, $bytes);

    return new PublishMedia(id: $id, kind: 'image', mime: 'image/jpeg', url: 'https://signed.example/'.$id, width: 64, height: 48, size: strlen($bytes), disk: 'local', path: $path, alt: $alt);
}

function maRequest(?string $text, array $media = [], array $options = [], int $target = 7, array $progress = []): PublishRequest
{
    return new PublishRequest(targetId: $target, type: ContentType::MastodonPost, text: $text, media: $media, options: $options, since: CarbonImmutable::parse('2026-11-26 10:00:00'), progress: $progress);
}

function maSent(string $suffix, ?string $method = null): array
{
    return Http::recorded()->filter(fn ($pair) => str_ends_with(parse_url($pair[0]->url(), PHP_URL_PATH), $suffix) && ($method === null || $pair[0]->method() === $method))->map(fn ($pair) => $pair[0])->values()->all();
}

// ------------------------------------------------------------------ registro

test('esta registrado como conector de credenciales que solo publica: sin comentarios ni metricas', function () {
    $registry = app(ConnectorRegistry::class);

    expect($registry->forNetwork(Network::Mastodon))->toBeInstanceOf(CredentialConnector::class)
        ->and($registry->publisherFor(Network::Mastodon))->toBeInstanceOf(MastodonPublisher::class)
        ->and($registry->commentNetworks())->not->toContain(Network::Mastodon)
        ->and($registry->insightsFor(Network::Mastodon))->toBeNull()
        ->and(Network::Mastodon->authKind())->toBe('credentials')
        ->and(ContentType::forNetwork(Network::Mastodon))->toBe([ContentType::MastodonPost]);
});

// ------------------------------------------------------------------ conectar

test('conecta con instancia y token: guarda id, handle, avatar y limites, y el token cifrado', function () {
    $account = maConnect('Mastodon.Example/');

    expect($account->status)->toBe('activa')->and($account->external_id)->toBe('mastodon.example:109')
        ->and($account->name)->toBe('ana@mastodon.example')->and($account->username)->toBe('ana@mastodon.example')
        ->and($account->access_token)->toBe(MA_TOKEN)->and($account->token_expires_at)->toBeNull()
        ->and($account->meta['instance'])->toBe('https://mastodon.example')->and($account->meta['avatar'])->toBe('https://mastodon.example/avatars/ana.png')
        ->and($account->meta['limits'])->toMatchArray(['max_characters' => 300, 'max_media_attachments' => 2, 'characters_reserved_per_url' => 23, 'image_size_limit' => 2_000_000, 'description_limit' => 100])
        ->and($account->scopes)->toBe(['write:statuses', 'write:media', 'read:accounts']);
    expect(DB::table('social_accounts')->first()->access_token)->not->toContain(MA_TOKEN);

    [$verify] = maSent('/verify_credentials');
    expect($verify->hasHeader('Authorization', 'Bearer '.MA_TOKEN))->toBeTrue();
    [$instance] = maSent('/api/v2/instance');
    expect($instance->hasHeader('Authorization'))->toBeFalse();

    $this->get(route('brands.connections.index', $this->brand))->assertOk()->assertDontSee(MA_TOKEN);
});

test('una cuenta local con acct completo (otra instancia) no duplica el dominio', function () {
    maFakeConnect(['mastodon.example/api/v1/accounts/verify_credentials' => Http::response(['id' => '9', 'acct' => 'ana@otra.example', 'url' => 'https://mastodon.example/@ana'])]);

    $this->actingAs($this->user)->post($this->url, ['instance_url' => 'mastodon.example', 'access_token' => MA_TOKEN])->assertSessionHas('success');

    expect(SocialAccount::withoutUserScope()->first()->name)->toBe('ana@otra.example');
});

test('SSRF: una instancia http, privada, con puerto, con ruta o con credenciales se rechaza sin hacer ninguna peticion', function (string $instance) {
    Http::fake();

    $this->actingAs($this->user)->post($this->url, ['instance_url' => $instance, 'access_token' => MA_TOKEN])
        ->assertRedirect()->assertSessionHasErrors('credentials');

    Http::assertNothingSent();
    expect(SocialAccount::withoutUserScope()->count())->toBe(0)->and(session('errors')->first('credentials'))->not->toContain(MA_TOKEN);
})->with([
    'http' => 'http://mastodon.example',
    'red privada' => 'https://interno.example',
    'localhost' => 'https://localhost',
    'IP literal privada' => 'https://10.0.0.5',
    'puerto' => 'https://mastodon.example:8443',
    'ruta' => 'https://mastodon.example/api',
    'credenciales' => 'https://ana:secreto@mastodon.example',
]);

test('un token rechazado (401) da un error claro y no guarda nada ni lo refleja', function () {
    maFakeConnect(['mastodon.example/api/v1/accounts/verify_credentials' => Http::response(['error' => 'The access token is invalid'], 401)]);

    $this->actingAs($this->user)->post($this->url, ['instance_url' => 'mastodon.example', 'access_token' => MA_TOKEN])->assertSessionHasErrors('credentials');

    expect(session('errors')->first('credentials'))->toContain('rechazó el token')->not->toContain(MA_TOKEN)
        ->and(SocialAccount::withoutUserScope()->count())->toBe(0)
        ->and(session()->getOldInput())->not->toHaveKey('access_token');
});

test('si no se pueden leer los limites al conectar se conecta con los por defecto y se leen al publicar', function () {
    maFakeConnect(['mastodon.example/api/v2/instance' => Http::response([], 500)]);

    $this->actingAs($this->user)->post($this->url, ['instance_url' => 'mastodon.example', 'access_token' => MA_TOKEN])->assertSessionHas('success');

    $account = SocialAccount::withoutUserScope()->first();
    expect($account->meta['limits'])->toBe([])
        ->and(app(MediaValidator::class)->validate(ContentType::MastodonPost, [], str_repeat('a', 501), $account))->not->toBeEmpty();
});

test('faltan campos: errores por campo y el token no se refleja', function () {
    $this->actingAs($this->user)->post($this->url, ['instance_url' => 'mastodon.example'])->assertSessionHasErrors('access_token');

    expect(session()->getOldInput())->not->toHaveKey('access_token');
});

test('desconectar borra el token y deja la cuenta desconectada', function () {
    $account = maConnect();

    $this->actingAs($this->user)->delete(route('brands.connections.destroy', [$this->brand, $account]))->assertRedirect();

    $account->refresh();
    expect($account->status)->toBe('desconectada')->and($account->access_token)->toBeNull();
});

// ------------------------------------------------------------------ limites de la instancia

test('el validador aplica los limites de ESA cuenta: texto, numero de imagenes, formato y peso', function () {
    $account = maAccount(['supported_mime_types' => ['image/jpeg', 'image/png', 'video/mp4']]);
    $validator = app(MediaValidator::class);
    $image = fn (array $over = []) => MediaAsset::factory()->for($this->user)->create($over);

    expect($validator->validate(ContentType::MastodonPost, [], str_repeat('a', 300), $account))->toBe([])
        ->and($validator->validate(ContentType::MastodonPost, [], str_repeat('a', 301), $account))->toHaveCount(1)
        ->and($validator->validate(ContentType::MastodonPost, [$image(), $image()], 'hola', $account))->toBe([])
        ->and(implode(' ', $validator->validate(ContentType::MastodonPost, [$image(), $image(), $image()], 'hola', $account)))->toContain('como maximo 2')
        ->and(implode(' ', $validator->validate(ContentType::MastodonPost, [$image(['size' => 3_000_000])], 'hola', $account)))->toContain('como maximo')
        ->and(implode(' ', $validator->validate(ContentType::MastodonPost, [$image(['mime' => 'image/webp'])], 'hola', $account)))->toContain('solo admite');
});

test('sin limites guardados se usa el valor prudente: 500 caracteres y 4 imagenes', function () {
    $account = SocialAccount::factory()->for($this->user)->create(['brand_id' => $this->brand->id, 'network' => Network::Mastodon, 'meta' => ['instance' => 'https://mastodon.example']]);
    $validator = app(MediaValidator::class);
    $image = fn () => MediaAsset::factory()->for($this->user)->create();

    expect($validator->validate(ContentType::MastodonPost, [], str_repeat('a', 500), $account))->toBe([])
        ->and($validator->validate(ContentType::MastodonPost, [], str_repeat('a', 501), $account))->not->toBeEmpty()
        ->and($validator->validate(ContentType::MastodonPost, array_map($image, range(1, 4)), 'hola', $account))->toBe([])
        ->and($validator->validate(ContentType::MastodonPost, array_map($image, range(1, 5)), 'hola', $account))->not->toBeEmpty()
        ->and($validator->validate(ContentType::MastodonPost, [], '', $account))->not->toBeEmpty(); // sin texto ni imagen
});

test('el compositor recibe los limites de cada cuenta y las visibilidades', function () {
    maAccount(['max_characters' => 1000, 'max_media_attachments' => 6]);

    $this->actingAs($this->user)->get(route('brands.posts.create', $this->brand))->assertInertia(fn ($page) => $page
        ->where('accounts.0.limits', ['max_chars' => 1000, 'max_media' => 6, 'url_length' => 23])
        ->where('limits.mastodon_post.count', 'mastodon')
        ->where('accounts.0.visibilities.0', ['value' => 'public', 'label' => 'Pública'])
        ->where('contentTypes.mastodon.0.value', 'mastodon_post'));
});

test('al publicar, los limites con mas de un dia se vuelven a leer de la instancia y se guardan', function () {
    $account = maAccount(['fetched_at' => now()->subDays(3)->toIso8601String(), 'max_characters' => 300]);
    Http::fake([
        'mastodon.example/api/v2/instance' => Http::response(maInstanceJson(['configuration' => ['statuses' => ['max_characters' => 800]]])),
        'mastodon.example/api/v1/statuses' => Http::response(['id' => '1', 'url' => 'https://mastodon.example/@ana/1']),
    ]);

    $this->publisher->publish($account, maRequest(str_repeat('a', 700)));

    expect($account->fresh()->meta['limits']['max_characters'])->toBe(800)->and(maSent('/api/v2/instance'))->toHaveCount(1);
});

test('con limites recientes no se vuelve a leer la instancia; y si la lectura falla se publica con lo guardado', function () {
    $account = maAccount();
    $stale = maAccount(['fetched_at' => now()->subDays(3)->toIso8601String()], ['external_id' => 'mastodon.example:110']);
    Http::fake([
        'mastodon.example/api/v2/instance' => Http::response([], 503),
        'mastodon.example/api/v1/statuses' => Http::response(['id' => '1', 'url' => 'https://mastodon.example/@ana/1']),
    ]);

    $this->publisher->publish($account, maRequest('hola'));
    expect(maSent('/api/v2/instance'))->toBe([]);

    expect($this->publisher->publish($stale, maRequest('hola'))->isPublished())->toBeTrue()
        ->and(maSent('/api/v2/instance'))->toHaveCount(1)->and($stale->fresh()->meta['limits']['max_characters'])->toBe(300);
});

test('un texto que no cabe en los limites de la cuenta no llega a la red', function () {
    Http::fake();

    expect(fn () => $this->publisher->publish(maAccount(), maRequest(str_repeat('a', 301))))->toThrow(PermanentPublishException::class);

    Http::assertNothingSent();
});

// ------------------------------------------------------------------ publicar

test('publica texto: un solo POST de estado con visibilidad publica, Idempotency-Key y la url del Status', function () {
    $account = maAccount();
    Http::fake(['mastodon.example/api/v1/statuses' => Http::response([
        'id' => '110000000000000001', 'created_at' => '2026-11-26T10:00:00.000Z',
        'url' => 'https://mastodon.example/@ana/110000000000000001', 'uri' => 'https://mastodon.example/users/ana/statuses/110000000000000001',
    ])]);

    $result = $this->publisher->publish($account, maRequest("  Hola Mastodon \n"));

    expect($result->isPublished())->toBeTrue()->and($result->externalId)->toBe('110000000000000001')
        ->and($result->url)->toBe('https://mastodon.example/@ana/110000000000000001');
    [$sent] = maSent('/api/v1/statuses');
    expect($sent->hasHeader('Authorization', 'Bearer '.MA_TOKEN))->toBeTrue()
        ->and($sent->hasHeader('Idempotency-Key', MastodonPublisher::idempotencyKey(maRequest("  Hola Mastodon \n"))))->toBeTrue()
        ->and($sent->data())->toBe(['status' => 'Hola Mastodon', 'visibility' => 'public']);
    expect(maSent('/api/v2/media'))->toBe([]);
    FixtureRecorder::assertRecorded('mastodon/success');
});

test('la visibilidad es una opcion del destino', function () {
    $account = maAccount();
    Http::fake(['mastodon.example/api/v1/statuses' => Http::response(['id' => '1', 'url' => 'https://mastodon.example/@ana/1'])]);

    $this->publisher->publish($account, maRequest('hola', options: ['mastodon_visibility' => 'unlisted']));

    expect(maSent('/api/v1/statuses')[0]->data()['visibility'])->toBe('unlisted');
});

test('Idempotency-Key: la misma en todos los reintentos de un destino y distinta entre destinos, intentos y contenidos', function () {
    $account = maAccount();
    Http::fake(['mastodon.example/api/v1/statuses' => Http::response(['id' => '1', 'url' => 'https://mastodon.example/@ana/1'])]);

    $this->publisher->publish($account, maRequest('hola'));
    $this->publisher->publish($account, maRequest('hola'));
    $this->publisher->publish($account, maRequest('hola', target: 8));
    $other = new PublishRequest(targetId: 7, type: ContentType::MastodonPost, text: 'hola', media: [], since: CarbonImmutable::parse('2026-11-27 10:00:00'));
    $this->publisher->publish($account, $other);

    $keys = array_map(fn (Request $r) => $r->header('Idempotency-Key')[0], maSent('/api/v1/statuses'));
    expect($keys[0])->toBe($keys[1])->not->toBe($keys[2])->not->toBe($keys[3])
        ->and($keys[0])->toMatch('/^[a-f0-9]{64}$/')->not->toContain(MA_TOKEN);
});

test('G4: si se edita el texto, la visibilidad o las imagenes tras un intento ambiguo, la clave cambia y Mastodon no devuelve el estado viejo', function () {
    $base = MastodonPublisher::idempotencyKey(maRequest('hola', [maImage(1, 'alt')]));

    expect(MastodonPublisher::idempotencyKey(maRequest('hola', [maImage(1, 'alt')])))->toBe($base)
        ->and(MastodonPublisher::idempotencyKey(maRequest('hola otra vez', [maImage(1, 'alt')])))->not->toBe($base)
        ->and(MastodonPublisher::idempotencyKey(maRequest('hola', [maImage(1, 'alt')], ['mastodon_visibility' => 'private'])))->not->toBe($base)
        ->and(MastodonPublisher::idempotencyKey(maRequest('hola', [maImage(2, 'alt')])))->not->toBe($base)
        ->and(MastodonPublisher::idempotencyKey(maRequest('hola', [maImage(1, 'otro alt')])))->not->toBe($base)
        ->and(MastodonPublisher::idempotencyKey(maRequest('hola')))->not->toBe($base);
});

test('con imagenes: sube cada una con su alt como description y publica con media_ids en orden', function () {
    $account = maAccount();
    Http::fake([
        'mastodon.example/api/v2/media' => Http::sequence()->push(['id' => 'm1', 'type' => 'image', 'url' => 'https://mastodon.example/m1.jpg'], 200)->push(['id' => 'm2', 'type' => 'image', 'url' => 'https://mastodon.example/m2.jpg'], 200),
        'mastodon.example/api/v1/statuses' => Http::response(['id' => '5', 'url' => 'https://mastodon.example/@ana/5']),
    ]);

    $result = $this->publisher->publish($account, maRequest('Dos fotos', [maImage(1, 'Una playa'), maImage(2)]));

    expect($result->isPublished())->toBeTrue();
    $uploads = maSent('/api/v2/media');
    expect($uploads)->toHaveCount(2);
    $parts = collect($uploads[0]->data())->keyBy('name');
    expect($parts['description']['contents'])->toBe('Una playa')->and($parts['file']['headers']['Content-Type'] ?? null)->toBe('image/jpeg')
        ->and(collect($uploads[1]->data())->pluck('name')->all())->not->toContain('description'); // sin alt no se envia el campo
    expect(maSent('/api/v1/statuses')[0]->data())->toBe(['status' => 'Dos fotos', 'media_ids' => ['m1', 'm2'], 'visibility' => 'public']);
});

test('el alt se recorta al description_limit de la instancia', function () {
    $account = maAccount(['description_limit' => 10]);
    Http::fake([
        'mastodon.example/api/v2/media' => Http::response(['id' => 'm1', 'url' => 'https://mastodon.example/m1.jpg']),
        'mastodon.example/api/v1/statuses' => Http::response(['id' => '5', 'url' => 'https://mastodon.example/@ana/5']),
    ]);

    $this->publisher->publish($account, maRequest('hola', [maImage(1, str_repeat('x', 40))]));

    expect(collect(maSent('/api/v2/media')[0]->data())->keyBy('name')['description']['contents'])->toBe(str_repeat('x', 10));
});

test('un 202 al subir se sondea con GET /api/v1/media/:id hasta el 200 y solo entonces se publica', function () {
    $account = maAccount();
    Http::fake([
        'mastodon.example/api/v2/media' => Http::response(['id' => 'm9', 'type' => 'image', 'url' => null], 202),
        'mastodon.example/api/v1/media/m9' => Http::sequence()->push(['id' => 'm9', 'url' => null], 206)->push(['id' => 'm9', 'url' => 'https://mastodon.example/m9.jpg'], 200),
        'mastodon.example/api/v1/statuses' => Http::response(['id' => '5', 'url' => 'https://mastodon.example/@ana/5']),
    ]);

    $result = $this->publisher->publish($account, maRequest('hola', [maImage(1)]));

    expect($result->isPublished())->toBeTrue()->and(maSent('/api/v1/media/m9', 'GET'))->toHaveCount(2);
    $order = Http::recorded()->map(fn ($pair) => $pair[0]->method().' '.parse_url($pair[0]->url(), PHP_URL_PATH))->values()->all();
    expect($order)->toBe(['POST /api/v2/media', 'GET /api/v1/media/m9', 'GET /api/v1/media/m9', 'POST /api/v1/statuses']);
});

test('si la imagen sigue procesandose tras los sondeos se reintenta despues y no se vuelve a subir', function () {
    $account = maAccount();
    $saved = null;
    $ready = false;
    Http::fake([
        'mastodon.example/api/v2/media' => Http::response(['id' => 'm9', 'url' => null], 202),
        'mastodon.example/api/v1/media/m9' => function () use (&$ready) {
            return $ready ? Http::response(['id' => 'm9', 'url' => 'https://mastodon.example/m9.jpg'], 200) : Http::response(['id' => 'm9', 'url' => null], 206);
        },
        'mastodon.example/api/v1/statuses' => Http::response(['id' => '5', 'url' => 'https://mastodon.example/@ana/5']),
    ]);
    $request = new PublishRequest(targetId: 7, type: ContentType::MastodonPost, text: 'hola', media: [maImage(1)], checkpoint: function ($reference, $progress) use (&$saved) {
        $saved = $progress;
    });

    expect(fn () => $this->publisher->publish($account, $request))->toThrow(TransientPublishException::class);
    expect($saved)->toBe(['media_ids' => ['m9']])->and(maSent('/api/v1/media/m9', 'GET'))->toHaveCount(3)->and(maSent('/api/v1/statuses'))->toBe([]);

    // Reintento: el progreso trae el id; la imagen ya esta lista y se publica sin subirla otra vez.
    $ready = true;
    $result = $this->publisher->publish($account, maRequest('hola', [maImage(1)], progress: $saved));

    expect($result->isPublished())->toBeTrue()
        ->and(maSent('/api/v1/statuses')[0]->data()['media_ids'])->toBe(['m9'])->and(maSent('/api/v2/media'))->toHaveCount(1);
});

test('una imagen de mas peso o formato que los de la instancia no se envia', function () {
    $account = maAccount(['image_size_limit' => 10]);
    Http::fake();

    expect(fn () => $this->publisher->publish($account, maRequest('hola', [maImage(1)])))->toThrow(PermanentPublishException::class);

    Http::assertNothingSent();
});

test('un tipo que no es el suyo se rechaza sin llamar a la red', function () {
    Http::fake();

    expect(fn () => $this->publisher->publish(maAccount(), new PublishRequest(targetId: 1, type: ContentType::BlueskyPost, text: 'x', media: [])))->toThrow(PermanentPublishException::class);

    Http::assertNothingSent();
});

// ------------------------------------------------------------------ errores

test('401 y 403: la cuenta necesita reconectarse', function (int $status) {
    Http::fake(['mastodon.example/api/v1/statuses' => Http::response(['error' => 'The access token is invalid'], $status)]);

    expect(fn () => $this->publisher->publish(maAccount(), maRequest('hola')))->toThrow(ReconnectRequiredException::class);
})->with([401, 403]);

test('422: error permanente con mensaje en castellano y el motivo de la instancia', function () {
    Http::fake(['mastodon.example/api/v1/statuses' => Http::response(['error' => 'Validation failed: Text character limit of 500 exceeded'], 422)]);

    try {
        $this->publisher->publish(maAccount(), maRequest('hola'));
        $this->fail('Debia fallar');
    } catch (PermanentPublishException $e) {
        expect($e->getMessage())->toStartWith('Mastodon no admite el contenido')->toContain('character limit')->and($e->errorCode)->toBe('contenido_rechazado');
    }
    FixtureRecorder::assertRecorded('mastodon/error');
});

test('429: cuota con espera hasta X-RateLimit-Reset (o Retry-After)', function () {
    $reset = now()->addMinutes(7)->utc()->format('Y-m-d\TH:i:s.v\Z');
    Http::fake(['mastodon.example/api/v1/statuses' => Http::sequence()
        ->push(['error' => 'Too many requests'], 429, ['X-RateLimit-Limit' => '300', 'X-RateLimit-Remaining' => '0', 'X-RateLimit-Reset' => $reset])
        ->push(['error' => 'Too many requests'], 429, ['Retry-After' => '90'])
        ->push(['error' => 'Too many requests'], 429)]);

    $account = maAccount();
    $at = [];
    foreach (range(1, 3) as $i) {
        try {
            $this->publisher->publish($account, maRequest('hola'));
        } catch (QuotaExceededException $e) {
            $at[] = $e->retryAt;
        }
    }

    expect($at)->toHaveCount(3)
        ->and((int) round(now()->diffInSeconds($at[0], false)))->toBeBetween(6 * 60, 7 * 60 + 1)
        ->and((int) round(now()->diffInSeconds($at[1], false)))->toBeBetween(89, 91)
        ->and($at[2]->isFuture())->toBeTrue();
});

test('5xx y caidas de red son transitorios', function () {
    Http::fake(['mastodon.example/api/v1/statuses' => Http::sequence()->push(['error' => 'x'], 503)->pushFailedConnection('timeout')]);

    $account = maAccount();

    expect(fn () => $this->publisher->publish($account, maRequest('hola')))->toThrow(TransientPublishException::class)
        ->and(fn () => $this->publisher->publish($account, maRequest('hola')))->toThrow(TransientPublishException::class);
});

test('SSRF al publicar: una instancia guardada que ahora resuelve a una IP privada no recibe la peticion (rebinding)', function () {
    app()->instance(UrlGuard::class, new UrlGuard(fn () => ['10.0.0.5']));
    Http::fake();

    expect(fn () => $this->publisher->publish(maAccount(), maRequest('hola')))->toThrow(PermanentPublishException::class);

    Http::assertNothingSent();
});

// ------------------------------------------------------------------ secretos

test('el token nunca aparece en logs ni excepciones, ni en su traza', function () {
    $logged = [];
    Log::listen(function ($event) use (&$logged) {
        $logged[] = $event->message.json_encode($event->context);
    });
    $account = maAccount();
    $seen = [];

    Http::fake(['mastodon.example/api/v1/statuses' => Http::sequence()
        ->push(['error' => 'x'], 500)->push(['error' => 'The access token is invalid'], 401)->push(['error' => 'Bad'], 422)
        ->pushFailedConnection('cURL error 28 https://mastodon.example Bearer '.MA_TOKEN)]);
    foreach (range(1, 4) as $i) {
        try {
            $this->publisher->publish($account, maRequest('hola'));
        } catch (Throwable $e) {
            $seen[] = (string) $e;
        }
    }
    maFakeConnect(['mastodon.example/api/v1/accounts/verify_credentials' => Http::response(['error' => 'bad'], 401)]);
    $this->actingAs($this->user)->post($this->url, ['instance_url' => 'mastodon.example', 'access_token' => MA_TOKEN]);

    expect($seen)->toHaveCount(4)->and(implode("\n", $seen))->not->toContain(MA_TOKEN)
        ->and(implode("\n", $logged))->not->toContain(MA_TOKEN);
});

test('app:reencrypt recifra el token de Mastodon con la clave actual', function () {
    $cipher = config('app.cipher');
    $old = new Encrypter(str_repeat('o', 32), $cipher);
    $current = new Encrypter(str_repeat('n', 32), $cipher);
    Crypt::swap((new Encrypter($current->getKey(), $cipher))->previousKeys([$old->getKey()]));
    $account = maAccount();
    DB::table('social_accounts')->where('id', $account->id)->update(['access_token' => $old->encryptString(MA_TOKEN)]);

    $this->artisan('app:reencrypt')->expectsOutput('Registros recifrados: 1')->assertSuccessful();

    $stored = DB::table('social_accounts')->where('id', $account->id)->value('access_token');
    expect($current->decryptString($stored))->toBe(MA_TOKEN);
});

// ------------------------------------------------------------------ G1: el texto se cuenta como lo cuenta Mastodon

test('G1: 450 caracteres con un UTM largo caben (la URL cuenta 23) y 480 con un enlace corto no caben (tambien cuenta 23)', function () {
    $account = maAccount(['max_characters' => 500, 'characters_reserved_per_url' => 23]);
    $validator = app(MediaValidator::class);
    $utm = str_repeat('a', 450).' https://ejemplo.org/p?'.str_repeat('u', 100); // 573 caracteres reales, 474 para Mastodon
    $short = str_repeat('a', 480).' https://bit.ly/3xYz';                        // 500 caracteres reales, 504 para Mastodon

    expect($validator->validate(ContentType::MastodonPost, [], $utm, $account))->toBe([])
        ->and($validator->validate(ContentType::MastodonPost, [], $short, $account))->toHaveCount(1);

    Http::fake(['mastodon.example/api/v1/statuses' => Http::response(['id' => '1', 'url' => 'https://mastodon.example/@ana/1'])]);
    expect($this->publisher->publish($account, maRequest($utm))->isPublished())->toBeTrue();
    Http::fake();
    expect(fn () => $this->publisher->publish($account, maRequest($short)))->toThrow(PermanentPublishException::class);
    Http::assertNothingSent();
});

test('G1: la reserva por URL es la de la instancia y la cuenta una mencion remota como @usuario', function () {
    $validator = app(MediaValidator::class);
    $account = maAccount(['max_characters' => 100, 'characters_reserved_per_url' => 40]);

    expect($validator->validate(ContentType::MastodonPost, [], str_repeat('a', 61).' https://x.io/a', $account))->toHaveCount(1) // 61 + 1 + 40 = 102
        ->and($validator->validate(ContentType::MastodonPost, [], str_repeat('a', 59).' https://x.io/a', $account))->toBe([])      // 100
        ->and($validator->validate(ContentType::MastodonPost, [], str_repeat('a', 90).' @ana@mastodon.social', $account))->toBe([]) // 90 + 1 + 4 = 95
        ->and($validator->validate(ContentType::MastodonPost, [], str_repeat('a', 100).' 👨‍👩‍👧‍👦', $account))->toHaveCount(1)       // la familia cuenta 1 grafema: 102
        ->and($validator->validate(ContentType::MastodonPost, [], str_repeat('a', 99).'👨‍👩‍👧‍👦', $account))->toBe([]);               // 99 + 1
});

// ------------------------------------------------------------------ G2: URLs de la instancia saneadas

test('G2: la url publicada solo se guarda si es https y del host de la instancia', function (string $url, string $uri, ?string $expected) {
    Http::fake(['mastodon.example/api/v1/statuses' => Http::response(['id' => '1', 'url' => $url, 'uri' => $uri])]);

    expect($this->publisher->publish(maAccount(), maRequest('hola'))->url)->toBe($expected);
})->with([
    'url buena' => ['https://mastodon.example/@ana/1', 'https://otro.example/x', 'https://mastodon.example/@ana/1'],
    'javascript: cae a uri buena' => ['javascript:alert(1)', 'https://mastodon.example/users/ana/statuses/1', 'https://mastodon.example/users/ana/statuses/1'],
    'otro host' => ['https://evil.example/x', 'http://mastodon.example/x', null],
    'http' => ['http://mastodon.example/@ana/1', 'https://otro.example/1', null],
    'sin url' => ['', '', null],
]);

test('G2: avatar y perfil guardados al conectar solo si son https (el perfil, ademas, de la instancia)', function () {
    maFakeConnect(['mastodon.example/api/v1/accounts/verify_credentials' => Http::response(['id' => '9', 'acct' => 'ana', 'avatar' => 'javascript:alert(1)', 'url' => 'https://evil.example/@ana'])]);
    $this->actingAs($this->user)->post($this->url, ['instance_url' => 'mastodon.example', 'access_token' => MA_TOKEN])->assertSessionHas('success');

    expect(SocialAccount::withoutUserScope()->first()->meta)->not->toHaveKey('avatar')->not->toHaveKey('profile_url');
});

// ------------------------------------------------------------------ G6: media guardada que ya caduco

test('G6: si la imagen guardada en el progreso ya no existe (404) se vuelve a subir', function () {
    $account = maAccount();
    Http::fake([
        'mastodon.example/api/v1/media/viejo' => Http::response(['error' => 'Record not found'], 404),
        'mastodon.example/api/v2/media' => Http::response(['id' => 'nuevo', 'url' => 'https://mastodon.example/n.jpg']),
        'mastodon.example/api/v1/statuses' => Http::response(['id' => '5', 'url' => 'https://mastodon.example/@ana/5']),
    ]);
    $saved = null;
    $request = new PublishRequest(targetId: 7, type: ContentType::MastodonPost, text: 'hola', media: [maImage(1)], progress: ['media_ids' => ['viejo']], checkpoint: function ($reference, $progress) use (&$saved) {
        $saved = $progress;
    });

    expect($this->publisher->publish($account, $request)->isPublished())->toBeTrue()
        ->and(maSent('/api/v2/media'))->toHaveCount(1)->and(maSent('/api/v1/statuses')[0]->data()['media_ids'])->toBe(['nuevo'])
        ->and($saved)->toBe(['media_ids' => ['nuevo']]);
});

// ------------------------------------------------------------------ cuerpo cortado, tope de respuesta y presupuesto

test('una RequestException con el cuerpo cortado al conectar da un error claro y no un 500', function () {
    Http::fake(['mastodon.example/api/v1/accounts/verify_credentials' => fn () => throw new RequestException(new Response(new Psr7Response(502, [], '{"id":"1","ac')))]);

    $this->actingAs($this->user)->post($this->url, ['instance_url' => 'mastodon.example', 'access_token' => MA_TOKEN])->assertRedirect()->assertSessionHasErrors('credentials');

    expect(SocialAccount::withoutUserScope()->count())->toBe(0);
});

test('G7: una respuesta mayor que el tope se rechaza aunque no se pueda cortar antes (cuerpo ya leido)', function () {
    config(['social.mastodon.max_response_bytes' => 1000]);
    Http::fake(['mastodon.example/api/v2/instance' => Http::response(['padding' => str_repeat('a', 5000)])]);

    expect(fn () => app(MastodonClient::class)->instance('https://mastodon.example'))->toThrow(MastodonException::class, 'demasiado grande');
});

test('la espera entre sondeos no pasa del presupuesto del paso', function () {
    config(['social.mastodon.media_poll_delay_ms' => 5000, 'social.mastodon.media_poll_attempts' => 3]);
    $account = maAccount();
    Http::fake([
        'mastodon.example/api/v2/media' => Http::response(['id' => 'm9', 'url' => null], 202),
        'mastodon.example/api/v1/media/m9' => Http::response(['id' => 'm9', 'url' => null], 206),
    ]);
    $request = new PublishRequest(targetId: 7, type: ContentType::MastodonPost, text: 'hola', media: [maImage(1)], budget: StepBudget::within(16.0));
    $started = microtime(true);

    expect(fn () => $this->publisher->publish($account, $request))->toThrow(TransientPublishException::class);
    expect(microtime(true) - $started)->toBeLessThan(3.0); // sin tope serian 5 s por espera
});
