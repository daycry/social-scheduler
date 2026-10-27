<?php

use App\Connectors\Network;
use App\Exceptions\PostValidationException;
use App\Models\Brand;
use App\Models\Post;
use App\Models\SocialAccount;
use App\Models\User;
use App\Services\PostService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\McpClient;

uses(McpClient::class);

// Bluesky en el MCP y en el compositor: limite de 300 grafemas (A-6) y red apagada con BLUESKY_ENABLED=false (B-4).

beforeEach(function () {
    Http::preventStrayRequests();
    Carbon::setTestNow('2026-11-01 08:00:00');
    $this->user = User::factory()->create();
    $this->brand = Brand::factory()->for($this->user)->create(['timezone' => 'Europe/Madrid']);
    $this->bsky = SocialAccount::factory()->for($this->user)->create(['brand_id' => $this->brand->id, 'network' => Network::Bluesky, 'name' => 'ana.example.com']);
    $this->ig = SocialAccount::factory()->for($this->user)->create(['brand_id' => $this->brand->id, 'network' => Network::Instagram]);
    $this->key = $this->mcpKey($this->user);
});

function bskyArgs(string $text): array
{
    return [
        'brand_id' => test()->brand->id, 'text' => $text, 'scheduled_at' => '2026-11-02 10:00', 'schedule' => true,
        'targets' => [['account_id' => test()->bsky->id, 'content_type' => 'bluesky_post']],
    ];
}

test('A-6: create_post rechaza 301 grafemas en Bluesky y acepta 300 (cuentan grafemas, no code points)', function () {
    [$err, $msg] = $this->callTool($this->key, 'create_post', bskyArgs(str_repeat('a', 301)));
    expect($err)->toBeTrue()->and($msg)->toContain('301')->toContain('Bluesky admite como maximo 300');

    // 300 grafemas pero 600 code points: cada e + acento combinante son 2 code points y un grafema.
    [$err, $post] = $this->callTool($this->key, 'create_post', bskyArgs(str_repeat("e\u{0301}", 300)));
    expect($err)->toBeFalse('falla: '.json_encode($post))->and($post['targets'][0]['content_type'])->toBe('bluesky_post');

    [$err] = $this->callTool($this->key, 'create_post', bskyArgs(str_repeat('b', 300)));
    expect($err)->toBeFalse();
});

test('B-4: con BLUESKY_ENABLED=false list_accounts marca Bluesky como no disponible y create_post lo rechaza', function () {
    config(['social.bluesky.enabled' => false]);

    [$err, $data] = $this->callTool($this->key, 'list_accounts', ['brand_id' => $this->brand->id]);
    $byNetwork = collect($data['accounts'])->keyBy('network');

    expect($err)->toBeFalse()
        ->and($byNetwork['bluesky']['available'])->toBeFalse()->and($byNetwork['bluesky']['content_types'])->toBe([])
        ->and($byNetwork['instagram']['available'])->toBeTrue();

    [$err, $msg] = $this->callTool($this->key, 'create_post', bskyArgs('Hola'));
    expect($err)->toBeTrue()->and($msg)->toContain('Bluesky no esta disponible');
    expect(Post::withoutUserScope()->count())->toBe(0);
});

test('B-4: con Bluesky activo todo sigue igual (disponible, con sus tipos)', function () {
    config(['social.bluesky.enabled' => true]);

    [, $data] = $this->callTool($this->key, 'list_accounts', ['brand_id' => $this->brand->id]);
    $bsky = collect($data['accounts'])->firstWhere('network', 'bluesky');

    expect($bsky['available'])->toBeTrue()->and($bsky['content_types'])->toBe(['bluesky_post', 'bluesky_video']);
});

test('B-4: con BLUESKY_ENABLED=false el compositor no ofrece los tipos de Bluesky y su cuenta sale no disponible', function () {
    config(['social.bluesky.enabled' => false]);

    $this->actingAs($this->user)->get(route('brands.posts.create', $this->brand))
        ->assertInertia(fn (Assert $page) => $page
            ->where('contentTypes.bluesky', [])
            ->missing('limits.bluesky_post')
            ->where('accounts', fn ($accounts) => collect($accounts)->firstWhere('network', 'bluesky')['available'] === false
                && collect($accounts)->firstWhere('network', 'instagram')['available'] === true));

    // Y la web tampoco deja guardar un destino de Bluesky.
    $this->actingAs($this->user)->postJson(route('brands.posts.validate', $this->brand), [
        'text' => 'Hola', 'schedule' => false, 'media' => [], 'targets' => [['account_id' => $this->bsky->id, 'content_type' => 'bluesky_post']],
    ])->assertOk()->assertExactJson(['errors' => ['targets.0' => ['Bluesky no esta disponible en esta instalacion.']]]);
});

test('N2: reschedule rechaza un destino de una red apagada y no mueve la fecha', function () {
    $post = app(PostService::class)->create($this->user, $this->brand, [
        'text' => 'Hola', 'targets' => [['account_id' => $this->bsky->id, 'content_type' => 'bluesky_post']],
    ]);
    config(['social.bluesky.enabled' => false]);

    expect(fn () => app(PostService::class)->reschedule($this->user, $post, '2026-11-05 18:30'))
        ->toThrow(PostValidationException::class, 'Bluesky no esta disponible');
    expect($post->fresh()->scheduled_at)->toBeNull();

    config(['social.bluesky.enabled' => true]);
    expect(app(PostService::class)->reschedule($this->user, $post, '2026-11-05 18:30')->scheduled_at)->not->toBeNull();
});
