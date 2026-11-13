<?php

use App\Connectors\Data\PublishMedia;
use App\Connectors\Data\PublishRequest;
use App\Connectors\Exceptions\PermanentPublishException;
use App\Connectors\LinkedIn\LinkedInPublisher;
use App\Connectors\Meta\Facebook\FacebookPublisher;
use App\Connectors\Meta\Instagram\InstagramPostOptions;
use App\Connectors\Network;
use App\Enums\ContentType;
use App\Exceptions\PostValidationException;
use App\Models\Brand;
use App\Models\MediaAsset;
use App\Models\SocialAccount;
use App\Models\User;
use App\Services\PostService;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\Support\FakeGraph;
use Tests\Support\NewNetworksFake;

// T-34: portada de video en LinkedIn, `place` de Facebook, busqueda de lugares de Meta y etiquetas de usuario. Todo con Http::fake.

beforeEach(function () {
    Http::preventStrayRequests();
    Storage::fake('local');
    config(['social.meta.graph_version' => 'v23.0']);
});

function poMedia(int $id, string $kind, string $bytes): PublishMedia
{
    $path = "m/$id.".($kind === 'video' ? 'mp4' : 'jpg');
    Storage::disk('local')->put($path, $bytes);

    return new PublishMedia(id: $id, kind: $kind, mime: $kind === 'video' ? 'video/mp4' : 'image/jpeg', url: 'https://signed.example/'.$id, size: strlen($bytes), disk: 'local', path: $path);
}

function poVideoWithCover(): PublishMedia
{
    $video = poMedia(9, 'video', 'AAAABBBB');
    $cover = poMedia(10, 'image', 'JPEGDATA');

    return new PublishMedia(id: $video->id, kind: 'video', mime: 'video/mp4', url: $video->url, size: 8, disk: 'local', path: $video->path, cover: $cover);
}

function poLinkedInFake(array $init): void
{
    Http::fake([
        'api.linkedin.com/rest/videos*' => Http::sequence()->push(['value' => $init])->push('', 200),
        'www.linkedin.com/dms-uploads/*' => Http::response('', 201, ['ETag' => '"etag-1"']),
        'api.linkedin.com/rest/posts' => Http::response('', 201, ['x-restli-id' => 'urn:li:share:7100']),
    ]);
}

function poLinkedInRequest(PublishMedia $media): PublishRequest
{
    return new PublishRequest(targetId: 1, type: ContentType::LinkedInVideo, text: 'Video', media: [$media], options: [], since: now()->subMinute(), progress: [], checkpoint: null);
}

test('LinkedIn: un video con portada pide uploadThumbnail y sube la imagen a la URL de la miniatura', function () {
    $account = NewNetworksFake::linkedinAccount();
    poLinkedInFake(['video' => 'urn:li:video:V1', 'uploadToken' => '', 'thumbnailUploadUrl' => 'https://www.linkedin.com/dms-uploads/V1/thumb', 'uploadInstructions' => [
        ['uploadUrl' => 'https://www.linkedin.com/dms-uploads/V1/video', 'firstByte' => 0, 'lastByte' => 7],
    ]]);

    app(LinkedInPublisher::class)->publish($account, poLinkedInRequest(poVideoWithCover()));

    Http::assertSent(fn (Request $r) => str_contains($r->url(), 'action=initializeUpload') && $r['initializeUploadRequest']['uploadThumbnail'] === true);
    Http::assertSent(fn (Request $r) => $r->url() === 'https://www.linkedin.com/dms-uploads/V1/thumb' && $r->method() === 'PUT' && $r->body() === 'JPEGDATA'
        && $r->hasHeader('media-type-family', 'STILLIMAGE') && $r->hasHeader('Content-Type', 'application/octet-stream'));
    Http::assertSent(fn (Request $r) => $r->url() === 'https://www.linkedin.com/dms-uploads/V1/video' && $r->body() === 'AAAABBBB');
});

function poInit(string $id): array
{
    return ['video' => "urn:li:video:$id", 'uploadToken' => '', 'uploadInstructions' => [['uploadUrl' => "https://www.linkedin.com/dms-uploads/$id/video", 'firstByte' => 0, 'lastByte' => 7]]];
}

test('LinkedIn: sin portada no pide miniatura', function () {
    $account = NewNetworksFake::linkedinAccount();
    poLinkedInFake(poInit('V2'));

    app(LinkedInPublisher::class)->publish($account, poLinkedInRequest(poMedia(9, 'video', 'AAAABBBB')));

    Http::assertSent(fn (Request $r) => str_contains($r->url(), 'action=initializeUpload') && $r['initializeUploadRequest']['uploadThumbnail'] === false);
});

test('LinkedIn: si LinkedIn no da la URL de la portada falla sin subir el video ni publicar', function () {
    $account = NewNetworksFake::linkedinAccount();
    poLinkedInFake(poInit('V3'));

    expect(fn () => app(LinkedInPublisher::class)->publish($account, poLinkedInRequest(poVideoWithCover())))->toThrow(PermanentPublishException::class, 'portada');
    Http::assertNotSent(fn (Request $r) => $r->url() === 'https://www.linkedin.com/dms-uploads/V3/video');
});

test('LinkedIn: el video de un destino acepta portada propia en el servicio y la portada debe ser una imagen', function () {
    NewNetworksFake::linkedin();
    $user = User::factory()->create();
    $brand = Brand::factory()->for($user)->create();
    $account = SocialAccount::factory()->for($user)->create(['brand_id' => $brand->id, 'network' => Network::LinkedIn, 'meta' => ['kind' => 'profile']]);
    $video = MediaAsset::factory()->video(duration: 30)->for($user)->create();
    $image = MediaAsset::factory()->for($user)->create();
    $payload = fn (int $coverId) => ['text' => 'Video', 'schedule' => false, 'media' => [$video->id], 'targets' => [[
        'account_id' => $account->id, 'content_type' => 'linkedin_video', 'media' => [['id' => $video->id, 'cover_id' => $coverId]],
    ]]];

    $post = app(PostService::class)->create($user, $brand, $payload($image->id));
    expect($post->targets()->first()->media)->toBe([['id' => $video->id, 'alt' => null, 'cover_id' => $image->id]]);
    expect(fn () => app(PostService::class)->create($user, $brand, $payload($video->id)))->toThrow(PostValidationException::class);
});

test('Facebook: place va en el feed de texto y en la foto; un video lo rechaza', function () {
    $user = User::factory()->create();
    $account = SocialAccount::factory()->for($user)->create(['brand_id' => Brand::factory()->for($user), 'network' => Network::Facebook, 'external_id' => '555', 'access_token' => 'tok']);
    $graph = new FakeGraph;
    $graph->on('POST', '#^555/feed$#', ['id' => '555_1'])->on('POST', '#^555/photos$#', ['id' => '9', 'post_id' => '555_2']);
    $fb = new FacebookPublisher;
    $photo = new PublishMedia(1, 'image', 'image/png', 'https://cdn.test/a.png', 1080, 1080);

    $fb->publish($account, new PublishRequest(1, ContentType::FacebookText, 'Hola', [], ['place' => '106078429431815'], null, now()->subMinute()));
    $fb->publish($account, new PublishRequest(2, ContentType::FacebookPhoto, 'Hola', [$photo], ['place' => '106078429431815'], null, now()->subMinute()));

    expect($graph->callsTo('POST', '#/feed$#')[0]['params'])->toMatchArray(['message' => 'Hola', 'place' => '106078429431815']);
    expect($graph->callsTo('POST', '#/photos$#')[0]['params'])->toMatchArray(['place' => '106078429431815']);
    expect(fn () => $fb->publish($account, new PublishRequest(3, ContentType::FacebookVideo, 'x', [new PublishMedia(2, 'video', 'video/mp4', 'https://cdn.test/v.mp4')], ['place' => '1'], null, now()->subMinute())))
        ->toThrow(PermanentPublishException::class, 'lugar');
});

test('Facebook: el servicio normaliza place y rechaza ids no numericos', function () {
    $user = User::factory()->create();
    $brand = Brand::factory()->for($user)->create();
    $account = SocialAccount::factory()->for($user)->create(['brand_id' => $brand->id, 'network' => Network::Facebook]);
    $make = fn (mixed $place) => app(PostService::class)->create($user, $brand, ['text' => 'Hola', 'schedule' => false, 'targets' => [['account_id' => $account->id, 'content_type' => 'facebook_text', 'options' => ['place' => $place]]]]);

    expect($make(106078429431815)->targets()->first()->options)->toBe(['place' => '106078429431815']);
    expect(fn () => $make('Madrid'))->toThrow(PostValidationException::class);
});

test('Lugares: la busqueda de Meta lista id, nombre y direccion y no se expone a otras redes ni a cuentas ajenas', function () {
    $user = User::factory()->create();
    $brand = Brand::factory()->for($user)->create();
    $ig = SocialAccount::factory()->for($user)->create(['brand_id' => $brand->id, 'network' => Network::Instagram, 'access_token' => 'ig-token']);
    $yt = SocialAccount::factory()->for($user)->create(['brand_id' => $brand->id, 'network' => Network::YouTube]);
    Http::fake(['graph.facebook.com/*/pages/search*' => Http::response(['data' => [
        ['id' => '106078429431815', 'name' => 'Madrid', 'location' => ['city' => 'Madrid', 'country' => 'Spain']],
        ['id' => 'no-numerico', 'name' => 'Descartado'],
        ['id' => '222', 'name' => 'Cafe Central', 'location' => ['street' => 'Plaza del Angel 10', 'city' => 'Madrid']],
    ]])]);

    $this->actingAs($user)->getJson(route('brands.connections.places', [$brand, $ig, 'q' => 'madrid']))->assertOk()->assertExactJson(['places' => [
        ['id' => '106078429431815', 'name' => 'Madrid', 'detail' => 'Madrid, Spain'],
        ['id' => '222', 'name' => 'Cafe Central', 'detail' => 'Plaza del Angel 10, Madrid'],
    ]]);
    Http::assertSent(fn (Request $r) => str_contains($r->url(), 'pages/search') && $r['q'] === 'madrid' && $r->hasHeader('Authorization', 'Bearer ig-token') && ! str_contains($r->url(), 'ig-token'));

    $this->actingAs($user)->getJson(route('brands.connections.places', [$brand, $yt, 'q' => 'madrid']))->assertNotFound();
    $this->actingAs(User::factory()->create())->getJson(route('brands.connections.places', [$brand, $ig, 'q' => 'madrid']))->assertNotFound();
    $this->actingAs($user)->getJson(route('brands.connections.places', [$brand, $ig, 'q' => 'a']))->assertStatus(422);
});

test('Lugares: sin permiso de Meta la API responde 422 con el motivo para pegar el id a mano', function () {
    $user = User::factory()->create();
    $brand = Brand::factory()->for($user)->create();
    $fb = SocialAccount::factory()->for($user)->create(['brand_id' => $brand->id, 'network' => Network::Facebook]);
    Http::fake(['graph.facebook.com/*/pages/search*' => Http::response(['error' => ['message' => '(#10) Application does not have permission for this action', 'code' => 10]], 403)]);

    $this->actingAs($user)->getJson(route('brands.connections.places', [$brand, $fb, 'q' => 'madrid']))
        ->assertStatus(422)->assertJsonPath('message', fn (string $m) => str_contains($m, 'Page Public Metadata Access') && str_contains($m, 'a mano'));
});

test('Instagram: user_tags con coordenadas y location_id llegan al contenedor de la imagen', function () {
    $options = InstagramPostOptions::normalize(['user_tags' => [['username' => '@Ana.Ruiz', 'x' => 0.25, 'y' => 0.75]], 'location_id' => 106078429431815]);

    expect(InstagramPostOptions::errors($options, ContentType::InstagramImage))->toBe([])
        ->and(InstagramPostOptions::containerParams($options))->toBe(['location_id' => '106078429431815', 'user_tags' => '[{"username":"ana.ruiz","x":0.25,"y":0.75}]']);
});
