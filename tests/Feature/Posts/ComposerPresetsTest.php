<?php

use App\Connectors\Network;
use App\Models\Brand;
use App\Models\ComposerPreset;
use App\Models\MediaAsset;
use App\Models\Post;
use App\Models\PostTarget;
use App\Models\SocialAccount;
use App\Models\User;
use App\Services\ComposerPresetService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\McpClient;

uses(McpClient::class);

// C-04 (T-29, CA-15): interruptores recordados por (marca, red); marca ajena rechazada; textos no persisten.

beforeEach(function () {
    Carbon::setTestNow('2026-11-01 12:00:00');
    Http::preventStrayRequests();
    $this->user = User::factory()->create();
    $this->brandA = Brand::factory()->for($this->user)->create(['name' => 'Marca A']);
    $this->brandB = Brand::factory()->for($this->user)->create(['name' => 'Marca B']);
    $this->ytA = SocialAccount::factory()->for($this->user)->create(['brand_id' => $this->brandA->id, 'network' => Network::YouTube]);
    $this->video = MediaAsset::factory()->video(duration: 45)->for($this->user)->create();
    $this->ytB = SocialAccount::factory()->for($this->user)->create(['brand_id' => $this->brandB->id, 'network' => Network::YouTube]);
});

function cpBody($account, array $options): array
{
    return ['text' => 'Hola', 'schedule' => false, 'media' => [test()->video->id], 'targets' => [['account_id' => $account->id, 'content_type' => 'youtube_video', 'options' => $options]]];
}

test('ComposerPresets: CA-15 el interruptor guardado en la marca A aparece en un post nuevo de A y no en B; los textos no persisten', function () {
    $this->actingAs($this->user)->post(route('brands.posts.store', $this->brandA), cpBody($this->ytA, [
        'notify_subscribers' => false, 'made_for_kids' => true, 'title' => 'Titulo', 'tags' => ['uno'], 'link' => 'https://x.test', 'category_id' => '27',
    ]))->assertSessionHasNoErrors();

    $row = ComposerPreset::withoutUserScope()->where('brand_id', $this->brandA->id)->sole();
    expect($row->network)->toBe('youtube')->and($row->options)->toBe(['made_for_kids' => true, 'notify_subscribers' => false]);

    $this->actingAs($this->user)->get(route('brands.posts.create', $this->brandA))
        ->assertInertia(fn (Assert $p) => $p->where('presets.youtube', ['made_for_kids' => true, 'notify_subscribers' => false]));
    $this->actingAs($this->user)->get(route('brands.posts.create', $this->brandB))
        ->assertInertia(fn (Assert $p) => $p->where('presets', []));
    expect(ComposerPreset::withoutUserScope()->where('brand_id', $this->brandB->id)->count())->toBe(0);
});

test('ComposerPresets: guardar de nuevo actualiza la fila de (marca, red) en vez de duplicarla y mezcla los interruptores', function () {
    $this->actingAs($this->user)->post(route('brands.posts.store', $this->brandA), cpBody($this->ytA, ['notify_subscribers' => false, 'made_for_kids' => true]))->assertSessionHasNoErrors();
    $this->actingAs($this->user)->post(route('brands.posts.store', $this->brandA), cpBody($this->ytA, ['notify_subscribers' => true]));

    expect(ComposerPreset::withoutUserScope()->where('brand_id', $this->brandA->id)->count())->toBe(1)
        ->and(ComposerPreset::withoutUserScope()->where('brand_id', $this->brandA->id)->value('options'))->toBe(['made_for_kids' => true, 'notify_subscribers' => true]);
});

test('ComposerPresets: editar un post tambien recuerda los interruptores', function () {
    $this->actingAs($this->user)->post(route('brands.posts.store', $this->brandA), cpBody($this->ytA, []));
    $post = Post::withoutUserScope()->firstOrFail();

    $this->actingAs($this->user)->put(route('posts.update', $post), cpBody($this->ytA, ['contains_synthetic_media' => true]))->assertSessionHasNoErrors();

    expect(ComposerPreset::withoutUserScope()->where('brand_id', $this->brandA->id)->value('options'))->toBe(['contains_synthetic_media' => true]);
});

test('ComposerPresets: no se recuerdan los campos de texto ni la divulgacion comercial de TikTok', function () {
    $tt = SocialAccount::factory()->for($this->user)->create(['brand_id' => $this->brandA->id, 'network' => Network::TikTok, 'meta' => ['direct_post' => true]]);
    $body = ['text' => 'Hola', 'schedule' => false, 'media' => [test()->video->id], 'targets' => [['account_id' => $tt->id, 'content_type' => 'tiktok_video', 'options' => [
        'privacy_level' => 'PUBLIC_TO_EVERYONE', 'disable_duet' => true, 'brand_content_toggle' => true, 'brand_organic_toggle' => true,
    ]]]];

    $this->actingAs($this->user)->post(route('brands.posts.store', $this->brandA), $body)->assertSessionHasNoErrors();

    expect(ComposerPreset::withoutUserScope()->where('brand_id', $this->brandA->id)->value('options'))->toBe(['disable_duet' => true, 'brand_organic_toggle' => true]);
});

test('ComposerPresets: un usuario no lee ni escribe los presets de una marca ajena', function () {
    $other = User::factory()->create();
    $foreign = Brand::factory()->for($other)->create();
    $acc = SocialAccount::factory()->for($other)->create(['brand_id' => $foreign->id, 'network' => Network::YouTube]);
    $row = new ComposerPreset(['network' => 'youtube', 'options' => ['notify_subscribers' => false]]);
    $row->user_id = $other->id;
    $row->brand_id = $foreign->id;
    $row->save();
    $service = app(ComposerPresetService::class);

    // Lectura: ni por el servicio ni por la web.
    expect($service->forBrand($this->user, $foreign))->toBe([]);
    $this->actingAs($this->user)->get(route('brands.posts.create', $foreign))->assertNotFound();

    // Escritura: ni por el servicio ni por la web; la fila ajena queda intacta.
    $target = new PostTarget(['options' => ['notify_subscribers' => true]]);
    $target->network = Network::YouTube;
    $service->remember($this->user, $foreign, [$target]);
    $this->actingAs($this->user)->post(route('brands.posts.store', $foreign), cpBody($acc, ['notify_subscribers' => true]))->assertNotFound();

    expect(ComposerPreset::withoutUserScope()->where('brand_id', $foreign->id)->sole()->options)->toBe(['notify_subscribers' => false]);
});

test('ComposerPresets: el MCP no escribe presets', function () {
    $key = $this->mcpKey($this->user);
    [$err] = $this->callTool($key, 'create_post', ['brand_id' => $this->brandA->id, 'text' => 'Hola', 'schedule' => false, 'media_ids' => [$this->video->id], 'targets' => [
        ['account_id' => $this->ytA->id, 'content_type' => 'youtube_video', 'options' => ['notify_subscribers' => false]],
    ]]);

    expect($err)->toBeFalse()->and(Post::withoutUserScope()->count())->toBe(1)->and(ComposerPreset::withoutUserScope()->count())->toBe(0);
});

test('ComposerPresets: una validacion fallida no guarda presets', function () {
    $this->actingAs($this->user)->post(route('brands.posts.store', $this->brandA), cpBody($this->ytA, ['category_id' => 'mal', 'notify_subscribers' => false]))->assertSessionHasErrors();

    expect(ComposerPreset::withoutUserScope()->count())->toBe(0);
});
