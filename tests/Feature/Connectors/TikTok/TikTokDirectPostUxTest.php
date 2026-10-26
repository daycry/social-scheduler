<?php

use App\Connectors\Network;
use App\Models\Brand;
use App\Models\MediaAsset;
use App\Models\SocialAccount;
use App\Models\User;
use Illuminate\Support\Facades\Http;

// Content Sharing Guidelines de TikTok (auditoria de Direct Post): datos del creador al abrir el compositor y visibilidad elegida a mano.

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->brand = Brand::factory()->for($this->user)->create();
    $this->tt = SocialAccount::factory()->for($this->user)->create([
        'brand_id' => $this->brand->id, 'network' => Network::TikTok, 'name' => 'marble', 'username' => 'marble',
        'access_token' => 'tt', 'meta' => ['direct_post' => true],
    ]);
});

function fakeCreatorInfo(array $data, string $code = 'ok', int $status = 200): void
{
    Http::fake(['open.tiktokapis.com/v2/post/publish/creator_info/query/' => Http::response(['data' => $data, 'error' => ['code' => $code, 'message' => $code]], $status)]);
}

test('TikTokDirectPostUx: el compositor recibe nombre, visibilidades permitidas e interacciones desactivadas de la cuenta', function () {
    fakeCreatorInfo([
        'creator_nickname' => 'Marble Party Race', 'creator_username' => 'marblepartyrace', 'creator_avatar_url' => 'https://p16.tiktokcdn.com/a.jpg',
        'privacy_level_options' => ['SELF_ONLY', 'FOLLOWER_OF_CREATOR', 'PUBLIC_TO_EVERYONE', 'DESCONOCIDA'],
        'comment_disabled' => false, 'duet_disabled' => true, 'stitch_disabled' => false, 'max_video_post_duration_sec' => 600,
    ]);

    $this->actingAs($this->user)->getJson(route('brands.connections.tiktok-creator', [$this->brand, $this->tt]))
        ->assertOk()
        ->assertExactJson([
            'nickname' => 'Marble Party Race', 'username' => 'marblepartyrace', 'avatar_url' => 'https://p16.tiktokcdn.com/a.jpg',
            // Solo las conocidas, de la mas abierta a la mas cerrada.
            'privacy_level_options' => ['PUBLIC_TO_EVERYONE', 'FOLLOWER_OF_CREATOR', 'SELF_ONLY'],
            'comment_disabled' => false, 'duet_disabled' => true, 'stitch_disabled' => false, 'max_video_post_duration_sec' => 600,
        ]);

    // El cuerpo de creator_info es un objeto JSON vacio (con `[]` TikTok responde invalid_params).
    Http::assertSent(fn ($r) => str_contains($r->url(), 'creator_info/query') && $r->body() === '{}');
});

test('TikTokDirectPostUx: si TikTok no deja publicar ahora, el compositor recibe el motivo', function () {
    fakeCreatorInfo([], 'spam_risk_too_many_posts', 403);

    $this->actingAs($this->user)->getJson(route('brands.connections.tiktok-creator', [$this->brand, $this->tt]))
        ->assertStatus(422)->assertJsonStructure(['message']);
});

test('TikTokDirectPostUx: solo cuentas de TikTok del propietario y de esa marca', function () {
    Http::fake();
    $yt = SocialAccount::factory()->for($this->user)->create(['brand_id' => $this->brand->id, 'network' => Network::YouTube]);
    $this->actingAs($this->user)->getJson(route('brands.connections.tiktok-creator', [$this->brand, $yt]))->assertNotFound();

    $other = Brand::factory()->for($this->user)->create();
    $this->actingAs($this->user)->getJson(route('brands.connections.tiktok-creator', [$other, $this->tt]))->assertNotFound();

    $this->actingAs(User::factory()->create())->getJson(route('brands.connections.tiktok-creator', [$this->brand, $this->tt]))->assertNotFound();
    Http::assertNothingSent();
});

test('TikTokDirectPostUx: con Direct Post, programar exige elegir la visibilidad; un borrador no, y en modo bandeja tampoco', function () {
    $video = MediaAsset::factory()->video(duration: 20)->for($this->user)->create();
    $body = fn (array $options, bool $schedule) => [
        'text' => 'Hola', 'schedule' => $schedule, 'scheduled_at' => now()->addDay()->format('Y-m-d H:i'),
        'media' => [$video->id], 'targets' => [['account_id' => $this->tt->id, 'content_type' => 'tiktok_video', 'options' => $options]],
    ];

    $this->actingAs($this->user)->post(route('brands.posts.store', $this->brand), $body([], true))
        ->assertSessionHasErrors(['targets.0' => 'Elige la visibilidad de TikTok antes de programar o publicar.']);

    $this->actingAs($this->user)->post(route('brands.posts.store', $this->brand), $body([], false))->assertSessionHasNoErrors();
    $this->actingAs($this->user)->post(route('brands.posts.store', $this->brand), $body(['privacy_level' => 'SELF_ONLY'], true))->assertSessionHasNoErrors();

    $this->tt->forceFill(['meta' => ['direct_post' => false]])->save();
    $this->actingAs($this->user)->post(route('brands.posts.store', $this->brand), $body([], true))->assertSessionHasNoErrors();
});
