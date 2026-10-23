<?php

use App\Connectors\Network;
use App\Models\Brand;
use App\Models\MediaAsset;
use App\Models\Post;
use App\Models\PostTemplate;
use App\Models\SocialAccount;
use App\Models\User;
use App\Services\PostService;
use Illuminate\Support\Carbon;

// C-03 (T-22, CA-12): la biblioteca conserva el texto propio de cada destino; una plantilla antigua (sin `text`) sigue funcionando.

beforeEach(function () {
    Carbon::setTestNow('2026-11-01 12:00:00');
    $this->user = User::factory()->create();
    $this->brand = Brand::factory()->for($this->user)->create();
    $this->ig = SocialAccount::factory()->for($this->user)->create(['brand_id' => $this->brand->id, 'network' => Network::Instagram]);
    $this->fb = SocialAccount::factory()->for($this->user)->create(['brand_id' => $this->brand->id, 'network' => Network::Facebook]);
    $this->image = MediaAsset::factory()->for($this->user)->create();
});

function ltBody(array $over = []): array
{
    return array_merge([
        'name' => 'Con textos', 'text' => 'Plantilla comun', 'media' => [test()->image->id],
        'targets' => [
            ['account_id' => test()->ig->id, 'content_type' => 'instagram_image', 'text' => 'Propio IG'],
            ['account_id' => test()->fb->id, 'content_type' => 'facebook_photo', 'text' => null],
        ],
    ], $over);
}

test('LibraryTargetText: guardar desde el compositor y usar la plantilla conserva los textos propios (vinculado sigue vinculado)', function () {
    $this->actingAs($this->user)->post(route('brands.library.store', $this->brand), ltBody())->assertSessionHasNoErrors();

    $template = PostTemplate::query()->firstOrFail();
    expect($template->targets)->toBe([
        ['account_id' => $this->ig->id, 'content_type' => 'instagram_image', 'text' => 'Propio IG'],
        ['account_id' => $this->fb->id, 'content_type' => 'facebook_photo'],
    ]);

    $this->actingAs($this->user)->post(route('library.use', $template))->assertRedirect();

    $post = Post::firstOrFail();
    $byAccount = $post->targets->keyBy('social_account_id');
    expect($post->text)->toBe('Plantilla comun')
        ->and($byAccount[$this->ig->id]->text)->toBe('Propio IG')
        ->and($byAccount[$this->fb->id]->text)->toBeNull()
        ->and($byAccount[$this->fb->id]->effectiveText())->toBe('Plantilla comun');
});

test('LibraryTargetText: guardar un post existente desde el calendario conserva el texto propio (un texto vacio es vinculado)', function () {
    $post = app(PostService::class)->create($this->user, $this->brand, [
        'text' => 'Comun', 'schedule' => false, 'media' => [$this->image->id],
        'targets' => [
            ['account_id' => $this->ig->id, 'content_type' => 'instagram_image', 'text' => ''],
            ['account_id' => $this->fb->id, 'content_type' => 'facebook_photo', 'text' => 'Propio FB'],
        ],
    ]);

    $this->actingAs($this->user)->post(route('library.store'), ['post_id' => $post->id, 'name' => 'Desde post'])->assertSessionHasNoErrors();

    expect(PostTemplate::query()->firstOrFail()->targets)->toBe([
        ['account_id' => $this->ig->id, 'content_type' => 'instagram_image'],
        ['account_id' => $this->fb->id, 'content_type' => 'facebook_photo', 'text' => 'Propio FB'],
    ]);
});

test('LibraryTargetText: una plantilla antigua sin text por destino sigue funcionando (todo vinculado)', function () {
    $template = new PostTemplate(['name' => 'Antigua', 'text' => 'Texto viejo', 'targets' => [['account_id' => $this->ig->id, 'content_type' => 'instagram_image']]]);
    $template->user_id = $this->user->id;
    $template->brand_id = $this->brand->id;
    $template->save();
    $template->media()->sync([$this->image->id => ['position' => 0]]);

    $this->actingAs($this->user)->post(route('library.use', $template))->assertRedirect();

    $target = Post::firstOrFail()->targets->first();
    expect($target->text)->toBeNull()->and($target->effectiveText())->toBe('Texto viejo');
});

test('LibraryTargetText: un texto propio que supera el maximo de su red se guarda en la plantilla pero el borrador queda sin destinos', function () {
    $this->actingAs($this->user)->post(route('brands.library.store', $this->brand), ltBody([
        'targets' => [['account_id' => $this->ig->id, 'content_type' => 'instagram_image', 'text' => str_repeat('a', 2201)]],
    ]))->assertSessionHasNoErrors();
    $template = PostTemplate::query()->firstOrFail();
    expect($template->targets[0]['text'])->toHaveLength(2201);

    $this->actingAs($this->user)->post(route('library.use', $template))->assertRedirect();

    // Mismo comportamiento que ante cualquier destino invalido: borrador con el contenido y sin destinos.
    $post = Post::firstOrFail();
    expect($post->text)->toBe('Plantilla comun')->and($post->targets)->toHaveCount(0);
});
