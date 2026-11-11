<?php

use App\Connectors\Exceptions\PermanentPublishException;
use App\Connectors\Network;
use App\Models\AuditLog;
use App\Models\Brand;
use App\Models\MediaAsset;
use App\Models\SocialAccount;
use App\Models\User;
use App\Services\MediaUploadService;
use App\Services\PostService;
use App\Services\PublishRequestFactory;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;

// T-17, revision intento 1: borrado de media referenciada por destino, fallo claro al publicar, PATCH parcial y auditoria de la API.

beforeEach(function () {
    Carbon::setTestNow('2026-11-01 12:00:00');
    Queue::fake();
    Storage::fake('local');
    config(['filesystems.media' => 'local']);
    $this->user = User::factory()->create();
    $this->brand = Brand::factory()->for($this->user)->create(['timezone' => 'UTC']);
    $this->ig = SocialAccount::factory()->for($this->user)->create(['brand_id' => $this->brand->id, 'network' => Network::Instagram, 'name' => 'ana_ig']);
    $mk = fn (string $n) => MediaAsset::factory()->for($this->user)->create(['original_name' => "$n.jpg", 'width' => 400, 'height' => 400]);
    [$this->a, $this->b, $this->c] = [$mk('A'), $mk('B'), $mk('C')];
    $this->post = app(PostService::class)->create($this->user, $this->brand, [
        'text' => 'Hola', 'schedule' => false, 'media' => [$this->a->id],
        'targets' => [['account_id' => $this->ig->id, 'content_type' => 'instagram_carousel', 'media' => [['id' => $this->b->id], ['id' => $this->c->id]]]],
    ]);
});

test('G1: una media usada solo por la lista propia de un destino no se puede borrar', function () {
    expect(fn () => app(MediaUploadService::class)->delete($this->b))->toThrow(DomainException::class, 'El archivo se usa en una publicacion');
    expect(MediaAsset::withoutUserScope()->find($this->b->id))->not->toBeNull();
});

test('G1: una portada usada solo por un destino no se puede borrar', function () {
    $video = MediaAsset::factory()->video()->for($this->user)->create();
    app(PostService::class)->update($this->user, $this->post, [
        'text' => 'Hola', 'schedule' => false, 'media' => [],
        'targets' => [['account_id' => $this->ig->id, 'content_type' => 'instagram_reel', 'media' => [['id' => $video->id, 'cover_id' => $this->c->id]]]],
    ]);

    expect(fn () => app(MediaUploadService::class)->delete($this->c))->toThrow(DomainException::class);
});

test('G1: una media sin ninguna referencia sigue pudiendo borrarse', function () {
    $free = MediaAsset::factory()->for($this->user)->create();
    app(MediaUploadService::class)->delete($free);
    expect(MediaAsset::withoutUserScope()->find($free->id))->toBeNull();
});

test('G1: si una media efectiva falta al publicar, el destino falla con un error claro', function () {
    MediaAsset::withoutUserScope()->whereKey($this->b->id)->delete();

    expect(fn () => app(PublishRequestFactory::class)->make($this->post->targets()->first()))
        ->toThrow(PermanentPublishException::class, 'ya no existe');
});

test('G2: PATCH parcial fusiona lo enviado sobre el post y conserva texto, destinos y media', function () {
    $key = $this->user->createToken('t', ['*'])->plainTextToken;

    $this->withToken($key)->patchJson("/api/v1/posts/{$this->post->id}", ['notes' => 'x'])->assertOk()
        ->assertJsonPath('notes', 'x')->assertJsonPath('text', 'Hola')
        ->assertJsonPath('media_ids', [$this->a->id])
        ->assertJsonPath('targets.0.media.0.id', $this->b->id)->assertJsonPath('targets.0.media.1.id', $this->c->id);
    expect($this->post->fresh()->targets()->count())->toBe(1);

    // Un PATCH que rompe la validacion se rechaza y no cambia nada.
    app('auth')->forgetGuards();
    $this->withToken($key)->patchJson("/api/v1/posts/{$this->post->id}", ['targets' => [['account_id' => $this->ig->id, 'content_type' => 'instagram_carousel', 'media' => [['id' => $this->b->id]]]]])->assertUnprocessable();
    expect($this->post->fresh()->targets->first()->media)->toHaveCount(2);
});

test('Fuera de lente: la API REST de posts registra en la auditoria', function () {
    $key = $this->user->createToken('t', ['*'])->plainTextToken;

    $this->withToken($key)->patchJson("/api/v1/posts/{$this->post->id}", ['notes' => 'x'])->assertOk();

    expect(AuditLog::query()->where('user_id', $this->user->id)->where('resource', 'post')->where('resource_id', $this->post->id)->exists())->toBeTrue();
});
