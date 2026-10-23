<?php

use App\Connectors\Network;
use App\Enums\ContentType;
use App\Models\Brand;
use App\Models\SocialAccount;
use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->brand = Brand::factory()->for($this->user)->create();
    $this->tt = SocialAccount::factory()->for($this->user)->create(['brand_id' => $this->brand->id, 'network' => Network::TikTok]);
    $this->yt = SocialAccount::factory()->for($this->user)->create(['brand_id' => $this->brand->id, 'network' => Network::YouTube]);
});

test('TikTokPublish: Direct Post esta desactivado por defecto y se activa/desactiva por cuenta en Conexiones', function () {
    $url = route('brands.connections.index', $this->brand, false);
    $this->actingAs($this->user)->get($url)->assertInertia(fn (Assert $p) => $p->where('accounts.0.direct_post', false)->where('accounts.1.direct_post', false));

    $this->actingAs($this->user)->patch(route('brands.connections.direct-post', [$this->brand, $this->tt], false), ['enabled' => true])->assertRedirect($url);
    expect($this->tt->fresh()->meta['direct_post'])->toBeTrue();

    $this->actingAs($this->user)->patch(route('brands.connections.direct-post', [$this->brand, $this->tt], false), ['enabled' => false]);
    expect($this->tt->fresh()->meta['direct_post'])->toBeFalse();

    // Solo cuentas de TikTok y solo del propietario.
    $this->actingAs($this->user)->patch(route('brands.connections.direct-post', [$this->brand, $this->yt], false), ['enabled' => true])->assertNotFound();
    $this->actingAs(User::factory()->create())->patch(route('brands.connections.direct-post', [$this->brand, $this->tt], false), ['enabled' => true])->assertNotFound();
});

test('YouTubePublish: el compositor ofrece video y short; TikTok solo video salvo con dominio verificado', function () {
    $types = fn () => $this->actingAs($this->user)->get(route('brands.posts.create', $this->brand, false))->viewData('page')['props']['contentTypes'];

    expect(collect($types()['youtube'])->pluck('value')->all())->toBe(['youtube_video', 'youtube_short']);
    expect(collect($types()['tiktok'])->pluck('value')->all())->toBe(['tiktok_video']);

    config(['social.tiktok.verified_domain' => 'https://media.midominio.test']);
    expect(collect($types()['tiktok'])->pluck('value')->all())->toBe(['tiktok_video', 'tiktok_photo']);
});

test('T-43: un tipo guardado que ya no se ofrece sigue teniendo su nombre real en typeLabels y no entra en contentTypes', function () {
    config(['social.tiktok.verified_domain' => '']);
    $props = $this->actingAs($this->user)->get(route('brands.posts.create', $this->brand, false))->viewData('page')['props'];

    expect(collect($props['contentTypes']['tiktok'])->pluck('value')->all())->toBe(['tiktok_video'])
        ->and($props['typeLabels']['tiktok_photo'])->toBe('Fotos (carrusel)')
        ->and($props['typeLabels'])->toHaveCount(count(ContentType::cases()));
});
