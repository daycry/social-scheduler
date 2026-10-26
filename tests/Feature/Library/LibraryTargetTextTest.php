<?php

use App\Connectors\Network;
use App\Models\Brand;
use App\Models\Campaign;
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

test('LibraryArchivedCampaign (revision F3-4 i2): una plantilla cuya campaña se archivó después se puede usar: borrador sin campaña y con aviso', function () {
    $campaign = Campaign::factory()->create(['user_id' => $this->user->id, 'brand_id' => $this->brand->id, 'name' => 'Black Friday']);
    $this->actingAs($this->user)->post(route('brands.library.store', $this->brand), [
        'name' => 'Oferta', 'text' => 'Oferta', 'media' => [$this->image->id], 'campaign_id' => $campaign->id,
        'targets' => [['account_id' => $this->ig->id, 'content_type' => 'instagram_image']],
    ])->assertSessionHasNoErrors();
    $template = PostTemplate::query()->firstOrFail();
    expect($template->campaign_id)->toBe($campaign->id);

    // Mientras está activa, el borrador la conserva.
    $this->actingAs($this->user)->post(route('library.use', $template))->assertSessionHasNoErrors();
    expect(Post::latest('id')->firstOrFail()->campaign_id)->toBe($campaign->id);

    // Archivada después de guardar la plantilla: «Usar» ya no falla.
    $campaign->forceFill(['archived_at' => now()])->save();
    $count = Post::count();

    $response = $this->actingAs($this->user)->post(route('library.use', $template));

    $response->assertSessionHasNoErrors()->assertSessionHas('success', fn ($m) => str_contains($m, 'Black Friday') && str_contains($m, 'archivada') && str_contains($m, 'sin campaña'));
    $post = Post::latest('id')->firstOrFail();
    $response->assertRedirect(route('posts.edit', $post));
    expect(Post::count())->toBe($count + 1)->and($post->campaign_id)->toBeNull()->and($post->text)->toBe('Oferta')
        ->and($post->targets)->toHaveCount(1)->and($post->media->pluck('id')->all())->toBe([$this->image->id])
        ->and($campaign->fresh()->archived_at)->not->toBeNull(); // no se desarchiva sola
});

test('LibraryArchivedCampaign: Library/Index.vue muestra el error de «Usar» (el borrador no se pudo crear)', function () {
    $vue = file_get_contents(resource_path('js/Pages/Library/Index.vue'));

    expect($vue)->toContain('onError')->toContain('useError')->toContain('data-testid="library-use-error"');
});
