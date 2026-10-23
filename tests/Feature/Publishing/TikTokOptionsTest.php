<?php

use App\Connectors\Data\PublishMedia;
use App\Connectors\Data\PublishRequest;
use App\Connectors\Network;
use App\Connectors\TikTok\TikTokPublisher;
use App\Enums\ContentType;
use App\Models\Brand;
use App\Models\MediaAsset;
use App\Models\SocialAccount;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Tests\Support\FakeHttp;

// C-04 (T-28, CA-14): opciones de TikTok en `post_info`, solo con publicacion directa.

beforeEach(function () {
    Carbon::setTestNow('2026-11-01 12:00:00');
    Storage::fake('local');
    config(['filesystems.media' => 'local', 'social.tiktok.verified_domain' => 'https://media.midominio.test', 'social.tiktok.single_chunk_max_bytes' => 64 * 1024 * 1024]);

    $this->user = User::factory()->create();
    $this->brand = Brand::factory()->for($this->user)->create();
    $this->account = SocialAccount::factory()->for($this->user)->create(['brand_id' => $this->brand->id, 'network' => Network::TikTok, 'access_token' => 'tt']);
    $init = ['json' => ['data' => ['publish_id' => 'v1', 'upload_url' => 'https://open-upload.tiktokapis.com/video/?upload_id=U1&upload_token=T'], 'error' => ['code' => 'ok']]];
    $this->http = new FakeHttp;
    $this->http->on('POST', '#creator_info/query/$#', ['json' => ['data' => ['privacy_level_options' => ['SELF_ONLY', 'PUBLIC_TO_EVERYONE']], 'error' => ['code' => 'ok']]])
        ->on('POST', '#/publish/video/init/$#', $init)
        ->on('POST', '#/inbox/video/init/$#', $init)
        ->on('POST', '#/content/init/$#', ['json' => ['data' => ['publish_id' => 'p1'], 'error' => ['code' => 'ok']]])
        ->on('PUT', '#upload_id=U1#', ['status' => 201]);
    $this->tt = new TikTokPublisher;
});

function ttoVideoRequest(array $options): PublishRequest
{
    $asset = MediaAsset::factory()->video(duration: 20)->for(test()->user)->create(['size' => 1024]);
    Storage::disk('local')->put($asset->path, str_repeat('t', 1024));
    $media = new PublishMedia($asset->id, 'video', 'video/mp4', 'https://cdn.test/v.mp4', 1080, 1920, 20.0, 30.0, 1024, $asset->disk, $asset->path);

    return new PublishRequest(55, ContentType::TikTokVideo, 'Mi video', [$media], $options, null, now()->subMinutes(5));
}

function ttoPhotoRequest(array $options): PublishRequest
{
    $img = new PublishMedia(1, 'image', 'image/jpeg', 'https://x.test/1.jpg', 1080, 1080, disk: 'local', path: 'media/1.jpg');

    return new PublishRequest(56, ContentType::TikTokPhoto, 'Mi foto', [$img], $options, null, now()->subMinutes(5));
}

test('TikTokOptions: en publicacion directa post_info lleva disable_duet, disable_stitch y brand_organic_toggle (CA-14)', function () {
    $this->account->forceFill(['meta' => ['direct_post' => true]])->save();

    $this->tt->publish($this->account->fresh(), ttoVideoRequest([
        'privacy_level' => 'PUBLIC_TO_EVERYONE', 'disable_duet' => true, 'disable_stitch' => true, 'brand_organic_toggle' => true, 'is_aigc' => true,
    ]));

    $info = $this->http->callsTo('POST', '#/publish/video/init/#')[0]['json']['post_info'];
    expect($info)->toMatchArray(['disable_duet' => true, 'disable_stitch' => true, 'brand_organic_toggle' => true, 'is_aigc' => true, 'privacy_level' => 'PUBLIC_TO_EVERYONE'])
        ->and($info)->not->toHaveKey('brand_content_toggle');
});

test('TikTokOptions: sin opciones post_info queda como antes', function () {
    $this->account->forceFill(['meta' => ['direct_post' => true]])->save();

    $this->tt->publish($this->account->fresh(), ttoVideoRequest([]));

    expect(array_keys($this->http->callsTo('POST', '#/publish/video/init/#')[0]['json']['post_info']))->toBe(['title', 'privacy_level', 'disable_comment']);
});

test('TikTokOptions: con la cuenta en modo borrador esas opciones no se envian a ningun sitio', function () {
    $this->tt->publish($this->account, ttoVideoRequest(['disable_duet' => true, 'brand_content_toggle' => true, 'is_aigc' => true]));

    $init = $this->http->callsTo('POST', '#/inbox/video/init/#')[0]['json'];
    expect(array_keys($init))->toBe(['source_info'])
        ->and($this->http->callsTo('POST', '#/publish/video/init/#'))->toBe([]);
});

test('TikTokOptions: en fotos no hay Duet ni Stitch y is_aigc va en el nivel superior del cuerpo', function () {
    $this->account->forceFill(['meta' => ['direct_post' => true]])->save();

    $this->tt->publish($this->account->fresh(), ttoPhotoRequest(['privacy_level' => 'PUBLIC_TO_EVERYONE', 'disable_duet' => true, 'disable_stitch' => true, 'brand_content_toggle' => true, 'is_aigc' => true]));

    $body = $this->http->callsTo('POST', '#/content/init/#')[0]['json'];
    expect($body['is_aigc'])->toBeTrue()
        ->and($body['post_info'])->toMatchArray(['brand_content_toggle' => true])
        ->and($body['post_info'])->not->toHaveKeys(['disable_duet', 'disable_stitch', 'is_aigc']);
});

test('TikTokOptions: la validacion del destino rechaza lo que la cuenta no admite y el contenido de marca privado', function () {
    // Sin media el destino siempre trae su error de media: se comprueba solo lo relativo a las opciones.
    $validate = fn (array $options, string $type = 'tiktok_video') => $this->actingAs($this->user)->postJson(route('brands.posts.validate', $this->brand, false), [
        'schedule' => false, 'targets' => [['account_id' => $this->account->id, 'content_type' => $type, 'options' => $options]],
    ]);

    // Cuenta en modo borrador: la opcion no se acepta.
    expect(json_encode($validate(['disable_duet' => true])->json('errors')))->toContain('publicacion directa');
    // Valores vacios (false) no cuentan como pedidos.
    expect(json_encode($validate(['disable_duet' => false])->json('errors')))->not->toContain('opcion')->not->toContain('privado');

    $this->account->forceFill(['meta' => ['direct_post' => true]])->save();
    expect(json_encode($validate(['disable_duet' => true, 'is_aigc' => true, 'privacy_level' => 'PUBLIC_TO_EVERYONE'])->json('errors')))->not->toContain('opcion')->not->toContain('privado');
    expect(json_encode($validate(['brand_content_toggle' => true])->json('errors')))->toContain('no puede ser privado');
    expect(json_encode($validate(['brand_content_toggle' => true, 'privacy_level' => 'PUBLIC_TO_EVERYONE'])->json('errors')))->not->toContain('opcion')->not->toContain('privado');
    expect(json_encode($validate(['disable_duet' => true], 'tiktok_photo')->json('errors')))->toContain('solo existe en los videos');
});

test('TikTokOptions: las opciones de TikTok y de YouTube no se aceptan en otra red', function () {
    $ig = SocialAccount::factory()->for($this->user)->create(['brand_id' => $this->brand->id, 'network' => Network::Instagram]);
    $r = $this->actingAs($this->user)->postJson(route('brands.posts.validate', $this->brand, false), [
        'schedule' => false, 'targets' => [['account_id' => $ig->id, 'content_type' => 'instagram_image', 'options' => ['is_aigc' => true, 'notify_subscribers' => true]]],
    ]);

    expect(json_encode($r->json('errors')))->toContain('is_aigc solo existe en TikTok')->toContain('notify_subscribers solo existe en YouTube');
});
