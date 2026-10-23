<?php

use App\Connectors\Network;
use App\Models\Brand;
use App\Models\MediaAsset;
use App\Models\Post;
use App\Models\SocialAccount;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Tests\Support\McpClient;

uses(McpClient::class);

// CA-02 / CA-12 / API-03: la key de B nunca ve ni toca datos de A, en NINGUNA herramienta del MCP.
beforeEach(function () {
    Http::preventStrayRequests();
    Carbon::setTestNow('2026-11-01 08:00:00');
    $this->a = User::factory()->create();
    $this->b = User::factory()->create();
    $this->brandA = Brand::factory()->for($this->a)->create(['name' => 'SecretoDeA', 'timezone' => 'UTC']);
    $this->igA = SocialAccount::factory()->for($this->a)->create(['brand_id' => $this->brandA->id, 'network' => Network::Instagram, 'name' => 'CuentaDeA']);
    $this->mediaA = MediaAsset::factory()->for($this->a)->create(['original_name' => 'fotoDeA.jpg']);
    $this->keyA = $this->mcpKey($this->a);

    [, $post] = $this->callTool($this->keyA, 'create_post', [
        'brand_id' => $this->brandA->id, 'text' => 'TextoDeA', 'scheduled_at' => '2026-11-02 10:00', 'schedule' => true,
        'media_ids' => [$this->mediaA->id], 'targets' => [['account_id' => $this->igA->id, 'content_type' => 'instagram_image']],
    ]);
    $this->postA = $post['id'];

    // B tiene lo suyo, para que sus listas no sean vacias por casualidad.
    $this->brandB = Brand::factory()->for($this->b)->create(['name' => 'MarcaB', 'timezone' => 'UTC']);
    $this->igB = SocialAccount::factory()->for($this->b)->create(['brand_id' => $this->brandB->id, 'network' => Network::Instagram]);
    $this->mediaB = MediaAsset::factory()->for($this->b)->create();
    $this->keyB = $this->mcpKey($this->b);
});

/** Ninguna respuesta de B puede contener nada de A. */
function expectNoLeakOfA(string $content): void
{
    foreach (['SecretoDeA', 'CuentaDeA', 'TextoDeA', 'fotoDeA'] as $secret) {
        expect($content)->not->toContain($secret);
    }
}

test('Isolation MCP: las herramientas de lectura de B no devuelven datos de A', function () {
    [, , $brands] = $this->callTool($this->keyB, 'list_brands');
    [, , $media] = $this->callTool($this->keyB, 'list_media');
    [, , $cal] = $this->callTool($this->keyB, 'get_calendar', ['view' => 'week', 'date' => '2026-11-02']);

    expectNoLeakOfA($brands->getContent());
    expectNoLeakOfA($media->getContent());
    expectNoLeakOfA($cal->getContent());
    expect($brands->json('result.content.0.text'))->toContain('MarcaB')
        ->and(json_decode($cal->json('result.content.0.text'), true)['items'])->toBe([]);
});

test('Isolation MCP: cada herramienta responde igual a un id ajeno que a uno inexistente', function (string $tool, string $argument, string $owner) {
    $foreign = ['brand' => $this->brandA->id, 'post' => $this->postA][$owner];
    $missing = 999999;
    $extra = in_array($tool, ['update_post', 'create_post'], true)
        ? ['text' => 'hack', 'schedule' => false]
        : (($tool === 'reschedule_post') ? ['scheduled_at' => '2026-11-09 10:00'] : []);

    [$errF, $msgF, $rF] = $this->callTool($this->keyB, $tool, [$argument => $foreign] + $extra);
    [$errM, $msgM] = $this->callTool($this->keyB, $tool, [$argument => $missing] + $extra);

    expect($errF)->toBeTrue()->and($errM)->toBeTrue()->and($msgF)->toBe($msgM);
    expectNoLeakOfA($rF->getContent());
})->with([
    'list_accounts' => ['list_accounts', 'brand_id', 'brand'],
    'get_calendar' => ['get_calendar', 'brand_id', 'brand'],
    'create_post' => ['create_post', 'brand_id', 'brand'],
    'get_post' => ['get_post', 'post_id', 'post'],
    'update_post' => ['update_post', 'post_id', 'post'],
    'reschedule_post' => ['reschedule_post', 'post_id', 'post'],
    'cancel_post' => ['cancel_post', 'post_id', 'post'],
    'delete_post' => ['delete_post', 'post_id', 'post'],
]);

test('Isolation MCP: tras los intentos de B la publicacion de A sigue intacta', function () {
    $before = Post::withoutUserScope()->find($this->postA)->only(['text', 'status', 'scheduled_at', 'user_id']);

    $this->callTool($this->keyB, 'update_post', ['post_id' => $this->postA, 'text' => 'hack', 'schedule' => false]);
    $this->callTool($this->keyB, 'reschedule_post', ['post_id' => $this->postA, 'scheduled_at' => '2026-11-09 10:00']);
    $this->callTool($this->keyB, 'cancel_post', ['post_id' => $this->postA]);
    $this->callTool($this->keyB, 'delete_post', ['post_id' => $this->postA]);

    expect(Post::withoutUserScope()->find($this->postA)->only(['text', 'status', 'scheduled_at', 'user_id']))->toEqual($before);
});

test('Isolation MCP: B no puede publicar en la cuenta ni con la media de A usando su propia marca', function () {
    $base = ['brand_id' => $this->brandB->id, 'text' => 'x', 'scheduled_at' => '2026-11-02 10:00', 'schedule' => true];

    [$err] = $this->callTool($this->keyB, 'create_post', $base + ['media_ids' => [$this->mediaB->id], 'targets' => [['account_id' => $this->igA->id, 'content_type' => 'instagram_image']]]);
    expect($err)->toBeTrue();

    [$err] = $this->callTool($this->keyB, 'create_post', $base + ['media_ids' => [$this->mediaA->id], 'targets' => [['account_id' => $this->igB->id, 'content_type' => 'instagram_image']]]);
    expect($err)->toBeTrue()
        ->and(Post::withoutUserScope()->where('user_id', $this->b->id)->count())->toBe(0);
});

test('Isolation MCP: B no puede editar su propia publicacion para colgarle la cuenta o la media de A', function () {
    [, $mine] = $this->callTool($this->keyB, 'create_post', [
        'brand_id' => $this->brandB->id, 'text' => 'mia', 'scheduled_at' => '2026-11-02 10:00', 'schedule' => true,
        'media_ids' => [$this->mediaB->id], 'targets' => [['account_id' => $this->igB->id, 'content_type' => 'instagram_image']],
    ]);

    [$err] = $this->callTool($this->keyB, 'update_post', [
        'post_id' => $mine['id'], 'text' => 'mia', 'scheduled_at' => '2026-11-02 10:00', 'schedule' => true,
        'media_ids' => [$this->mediaA->id], 'targets' => [['account_id' => $this->igA->id, 'content_type' => 'instagram_image']],
    ]);

    expect($err)->toBeTrue()->and(Post::withoutUserScope()->find($mine['id'])->media()->pluck('media_assets.id')->all())->toBe([$this->mediaB->id]);
});

test('Isolation MCP: el intento cruzado se registra como access.denied y como denegacion en auditoria', function () {
    Log::spy();

    $this->callTool($this->keyB, 'get_post', ['post_id' => $this->postA]);

    Log::shouldHaveReceived('warning')->withArgs(fn ($msg, $ctx = []) => $msg === 'access.denied' && ($ctx['user_id'] ?? null) === $this->b->id && ($ctx['resource_id'] ?? null) === $this->postA)->once();
    expect(DB::table('audit_logs')->where(['user_id' => $this->b->id, 'action' => 'get_post', 'outcome' => 'denied'])->count())->toBe(1);
});

test('Isolation MCP: la key de A sigue viendo lo suyo', function () {
    [$err, $post] = $this->callTool($this->keyA, 'get_post', ['post_id' => $this->postA]);

    expect($err)->toBeFalse()->and($post['text'])->toBe('TextoDeA');
});
