<?php

use App\Connectors\Network;
use App\Models\Brand;
use App\Models\Post;
use App\Models\SocialAccount;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\McpClient;

uses(McpClient::class);

// C-03 (T-24, CA-08..CA-12): guardado y lectura de `targets.*.text` por la web.

beforeEach(function () {
    Carbon::setTestNow('2026-11-01 12:00:00');
    $this->user = User::factory()->create();
    $this->brand = Brand::factory()->for($this->user)->create(['timezone' => 'Europe/Madrid']);
    $this->fbA = SocialAccount::factory()->for($this->user)->create(['brand_id' => $this->brand->id, 'network' => Network::Facebook, 'name' => 'FB A']);
    $this->fbB = SocialAccount::factory()->for($this->user)->create(['brand_id' => $this->brand->id, 'network' => Network::Facebook, 'name' => 'FB B']);
});

function ttBody(?string $textA, ?string $textB, array $over = []): array
{
    return array_merge([
        'text' => 'Texto comun', 'schedule' => false, 'media' => [],
        'targets' => [
            ['account_id' => test()->fbA->id, 'content_type' => 'facebook_text', 'text' => $textA],
            ['account_id' => test()->fbB->id, 'content_type' => 'facebook_text', 'text' => $textB],
        ],
    ], $over);
}

test('TargetText: CA-08 la web guarda el texto propio de una red y deja la otra vinculada', function () {
    $this->actingAs($this->user)->post(route('brands.posts.store', $this->brand), ttBody(null, 'Solo para B'))->assertSessionHasNoErrors();

    $post = Post::withoutUserScope()->firstOrFail();
    $byAccount = $post->targets->keyBy('social_account_id');
    expect($post->text)->toBe('Texto comun')
        ->and($byAccount[$this->fbA->id]->text)->toBeNull()->and($byAccount[$this->fbA->id]->effectiveText())->toBe('Texto comun')
        ->and($byAccount[$this->fbB->id]->text)->toBe('Solo para B')->and($byAccount[$this->fbB->id]->effectiveText())->toBe('Solo para B');
});

test('TargetText: reabrir el post devuelve el texto propio por destino (null = vinculado)', function () {
    $this->actingAs($this->user)->post(route('brands.posts.store', $this->brand), ttBody(null, 'Solo para B'));
    $post = Post::withoutUserScope()->firstOrFail();

    $this->actingAs($this->user)->get(route('posts.edit', $post))->assertInertia(fn (Assert $page) => $page
        ->component('Posts/Compose')
        ->where('post.text', 'Texto comun')
        ->where('post.targets.0.text', null)
        ->where('post.targets.1.text', 'Solo para B'));
});

test('TargetText: CA-09 volver a vincular (text null) borra el texto propio y editar la plantilla solo cambia lo vinculado', function () {
    $this->actingAs($this->user)->post(route('brands.posts.store', $this->brand), ttBody('Propio A', 'Propio B'));
    $post = Post::withoutUserScope()->firstOrFail();

    $this->actingAs($this->user)->put(route('posts.update', $post), ttBody(null, 'Propio B', ['text' => 'Comun EDITADO']))->assertSessionHasNoErrors();

    $byAccount = $post->fresh()->targets->keyBy('social_account_id');
    expect($byAccount[$this->fbA->id]->text)->toBeNull()->and($byAccount[$this->fbA->id]->effectiveText())->toBe('Comun EDITADO')
        ->and($byAccount[$this->fbB->id]->effectiveText())->toBe('Propio B');
});

test('TargetText: un texto propio vacio o solo con espacios llega a la web como null (vinculado): documentado, el MCP si lo conserva', function () {
    $this->actingAs($this->user)->post(route('brands.posts.store', $this->brand), ttBody('', '   '))->assertSessionHasNoErrors();

    $targets = Post::withoutUserScope()->firstOrFail()->targets;
    expect($targets->pluck('text')->all())->toBe([null, null]);
});

test('TargetText: la validacion en vivo y el guardado aplican el maximo y los hashtags por red al texto efectivo', function () {
    $ig = SocialAccount::factory()->for($this->user)->create(['brand_id' => $this->brand->id, 'network' => Network::Instagram, 'name' => 'IG']);
    $tags = implode(' ', array_map(fn ($i) => "#t$i", range(1, 31)));
    $body = [
        'text' => 'ok', 'schedule' => false, 'media' => [],
        'targets' => [
            ['account_id' => $this->fbA->id, 'content_type' => 'facebook_text', 'text' => $tags],
            ['account_id' => $ig->id, 'content_type' => 'instagram_image', 'text' => $tags],
        ],
    ];

    $errors = $this->actingAs($this->user)->postJson(route('brands.posts.validate', $this->brand), $body)->assertOk()->json('errors');

    // Facebook no limita los hashtags; Instagram (30) si: el error es del destino 1 (y el de falta de media, tambien suyo).
    expect($errors)->not->toHaveKey('targets.0')->and($errors['targets.1'])->toContain('El texto tiene 31 hashtags y una publicacion de imagen de Instagram admite como maximo 30.');

    $this->actingAs($this->user)->post(route('brands.posts.store', $this->brand), $body)->assertSessionHasErrors('targets.1');
    expect(Post::withoutUserScope()->count())->toBe(0);
});

test('TargetText: CA-12 el MCP lee lo que la web guardo con el mismo texto por destino', function () {
    Http::preventStrayRequests();
    $this->actingAs($this->user)->post(route('brands.posts.store', $this->brand), ttBody(null, 'Solo para B'));
    $post = Post::withoutUserScope()->firstOrFail();

    [$err, $read] = $this->callTool($this->mcpKey($this->user), 'get_post', ['post_id' => $post->id]);

    $byAccount = collect($read['targets'])->keyBy('account_id');
    expect($err)->toBeFalse()
        ->and($byAccount[$this->fbA->id])->toMatchArray(['text' => null, 'effective_text' => 'Texto comun'])
        ->and($byAccount[$this->fbB->id])->toMatchArray(['text' => 'Solo para B', 'effective_text' => 'Solo para B']);
});
