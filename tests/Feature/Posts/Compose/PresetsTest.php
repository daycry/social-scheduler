<?php

use App\Connectors\Network;
use App\Models\Brand;
use App\Models\ComposerPreset;
use App\Models\MediaAsset;
use App\Models\SocialAccount;
use App\Models\User;
use Illuminate\Support\Carbon;
use Inertia\Testing\AssertableInertia as Assert;

// C-04 (T-32, CA-14..CA-16): lo que el compositor web recibe y guarda de los interruptores por red.

beforeEach(function () {
    Carbon::setTestNow('2026-11-01 12:00:00');
    $this->user = User::factory()->create();
    $this->brand = Brand::factory()->for($this->user)->create();
    $this->yt = SocialAccount::factory()->for($this->user)->create(['brand_id' => $this->brand->id, 'network' => Network::YouTube, 'name' => 'YT']);
    $this->ttDraft = SocialAccount::factory()->for($this->user)->create(['brand_id' => $this->brand->id, 'network' => Network::TikTok, 'name' => 'TT borrador']);
    $this->ttDirect = SocialAccount::factory()->for($this->user)->create(['brand_id' => $this->brand->id, 'network' => Network::TikTok, 'name' => 'TT directo', 'meta' => ['direct_post' => true]]);
    $this->ig = SocialAccount::factory()->for($this->user)->create(['brand_id' => $this->brand->id, 'network' => Network::Instagram, 'name' => 'IG']);
    $this->video = MediaAsset::factory()->video(duration: 45)->for($this->user)->create();
});

function psPost(array $targets): array
{
    return ['text' => 'Hola', 'schedule' => false, 'media' => [test()->video->id], 'targets' => $targets];
}

test('Presets: CA-14 el compositor sabe que cuentas de TikTok publican en directo (las demas no ofrecen las opciones)', function () {
    $this->actingAs($this->user)->get(route('brands.posts.create', $this->brand))->assertInertia(function (Assert $page) {
        $accounts = collect($page->toArray()['props']['accounts'])->keyBy('name');
        expect($accounts['TT directo']['direct_post'])->toBeTrue()
            ->and($accounts['TT borrador']['direct_post'])->toBeFalse()
            ->and($accounts['YT']['direct_post'])->toBeFalse();
    });
});

test('Presets: CA-14 las opciones de TikTok se guardan con la cuenta en directo y se rechazan con la cuenta en modo borrador', function () {
    $options = ['privacy_level' => 'PUBLIC_TO_EVERYONE', 'disable_duet' => true, 'disable_stitch' => true, 'brand_organic_toggle' => true];

    $this->actingAs($this->user)->post(route('brands.posts.store', $this->brand), psPost([['account_id' => $this->ttDirect->id, 'content_type' => 'tiktok_video', 'options' => $options]]))
        ->assertSessionHasNoErrors();
    expect(ComposerPreset::withoutUserScope()->where('network', 'tiktok')->value('options'))->toBe(['disable_duet' => true, 'disable_stitch' => true, 'brand_organic_toggle' => true]);

    $this->actingAs($this->user)->post(route('brands.posts.store', $this->brand), psPost([['account_id' => $this->ttDraft->id, 'content_type' => 'tiktok_video', 'options' => $options]]))
        ->assertSessionHasErrors('targets.0');
});

test('Presets: CA-15 la web recibe el interruptor recordado de la marca y no los campos de texto', function () {
    $this->actingAs($this->user)->post(route('brands.posts.store', $this->brand), psPost([['account_id' => $this->yt->id, 'content_type' => 'youtube_video', 'options' => [
        'notify_subscribers' => false, 'title' => 'Titulo', 'tags' => ['a', 'b'], 'category_id' => '27',
    ]]]))->assertSessionHasNoErrors();

    $this->actingAs($this->user)->get(route('brands.posts.create', $this->brand))
        ->assertInertia(fn (Assert $page) => $page->where('presets', ['youtube' => ['notify_subscribers' => false]]));
});

test('Presets: CA-16 la etiqueta de IA solo se acepta en las redes cuya API la admite (YouTube y TikTok directo)', function () {
    $post = fn ($account, string $type, array $options) => $this->actingAs($this->user)->post(route('brands.posts.store', $this->brand), psPost([['account_id' => $account->id, 'content_type' => $type, 'options' => $options]]));

    $post($this->yt, 'youtube_video', ['contains_synthetic_media' => true])->assertSessionHasNoErrors();
    $post($this->ttDirect, 'tiktok_video', ['is_aigc' => true, 'privacy_level' => 'PUBLIC_TO_EVERYONE'])->assertSessionHasNoErrors();

    // Instagram no tiene campo de etiqueta IA en lo que implementamos (Meta fuera de alcance): se rechaza, no se finge.
    $post($this->ig, 'instagram_image', ['is_aigc' => true])->assertSessionHasErrors('targets.0');
    $post($this->ig, 'instagram_image', ['contains_synthetic_media' => true])->assertSessionHasErrors('targets.0');
    $post($this->ttDraft, 'tiktok_video', ['is_aigc' => true])->assertSessionHasErrors('targets.0');
});
