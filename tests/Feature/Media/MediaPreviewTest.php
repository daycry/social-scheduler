<?php

use App\Models\Brand;
use App\Models\MediaAsset;
use App\Models\Post;
use App\Models\User;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    Storage::fake('local');
    config(['filesystems.media' => 'local']);
    $this->user = User::factory()->create();
});

/** Video guardado en el disco de media, con contenido real para comprobar rangos. */
function storedVideo(User $user, string $bytes = '0123456789abcdef'): MediaAsset
{
    $asset = MediaAsset::factory()->video()->for($user)->create(['size' => strlen($bytes), 'declared_size' => strlen($bytes)]);
    Storage::disk('local')->put($asset->path, $bytes);

    return $asset;
}

test('MediaPreview: el dueño recibe el fichero guardado con su tipo y sin cache publica', function () {
    $asset = storedVideo($this->user);

    $response = $this->actingAs($this->user)->get(route('media.file', $asset));

    $response->assertOk()
        ->assertHeader('Content-Type', 'video/mp4')
        ->assertHeader('X-Content-Type-Options', 'nosniff');
    expect($response->headers->get('Cache-Control'))->toContain('private')
        ->and($response->baseResponse->getFile()->getContent())->toBe('0123456789abcdef');
});

test('MediaPreview: admite peticiones por rangos (el video se puede adelantar)', function () {
    $asset = storedVideo($this->user);

    $response = $this->actingAs($this->user)->get(route('media.file', $asset), ['Range' => 'bytes=4-7']);

    $response->assertStatus(206)->assertHeader('Content-Range', 'bytes 4-7/16');
});

test('MediaPreview: la media de otro usuario, la pendiente y la que falta en disco no se sirven', function () {
    // La de otro usuario responde como inexistente (UserScope en el binding), igual que el resto de recursos.
    $other = storedVideo(User::factory()->create());
    $this->actingAs($this->user)->get(route('media.file', $other))->assertNotFound();

    $pending = MediaAsset::factory()->for($this->user)->create(['status' => MediaAsset::STATUS_PENDING]);
    $this->actingAs($this->user)->get(route('media.file', $pending))->assertNotFound();

    $missing = MediaAsset::factory()->for($this->user)->create();
    $this->actingAs($this->user)->get(route('media.file', $missing))->assertNotFound();
});

test('MediaPreview: un invitado va al login', function () {
    $asset = storedVideo($this->user);

    $this->get(route('media.file', $asset))->assertRedirect(route('login'));
});

test('MediaPreview: la media del post que se edita trae preview_url (para previsualizar un post creado por MCP)', function () {
    $brand = Brand::factory()->for($this->user)->create();
    $asset = storedVideo($this->user);
    $post = Post::factory()->for($this->user)->create(['brand_id' => $brand->id]);
    $post->media()->attach($asset->id, ['position' => 0]);

    $this->actingAs($this->user)->get(route('posts.edit', $post))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->where('post.media.0.preview_url', route('media.file', $asset, absolute: false)));
});

test('MediaPreview: solo la media lista tiene preview_url', function () {
    $ready = MediaAsset::factory()->for($this->user)->create();
    $pending = MediaAsset::factory()->for($this->user)->create(['status' => MediaAsset::STATUS_PENDING]);

    expect($ready->toPublicArray()['preview_url'])->toBe(route('media.file', $ready, absolute: false))
        ->and($pending->toPublicArray()['preview_url'])->toBeNull();
});
