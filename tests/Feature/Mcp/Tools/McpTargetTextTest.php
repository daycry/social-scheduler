<?php

use App\Connectors\Network;
use App\Models\Brand;
use App\Models\MediaAsset;
use App\Models\Post;
use App\Models\SocialAccount;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Tests\Support\McpClient;

uses(McpClient::class);

// C-03 (T-23, CA-12): paridad del MCP con la web para el texto por destino.

beforeEach(function () {
    Http::preventStrayRequests();
    Carbon::setTestNow('2026-11-01 08:00:00');
    $this->user = User::factory()->create();
    $this->brand = Brand::factory()->for($this->user)->create(['timezone' => 'Europe/Madrid']);
    $this->ig = SocialAccount::factory()->for($this->user)->create(['brand_id' => $this->brand->id, 'network' => Network::Instagram, 'name' => 'IG A']);
    $this->fb = SocialAccount::factory()->for($this->user)->create(['brand_id' => $this->brand->id, 'network' => Network::Facebook, 'name' => 'FB A']);
    $this->image = MediaAsset::factory()->for($this->user)->create();
    $this->key = $this->mcpKey($this->user);
});

function mtArgs(array $targets, array $over = []): array
{
    return array_merge([
        'brand_id' => test()->brand->id, 'text' => 'Plantilla', 'scheduled_at' => '2026-11-02 10:00', 'schedule' => true,
        'media_ids' => [test()->image->id], 'targets' => $targets,
    ], $over);
}

function mtTargets(?string $ig = null, ?string $fb = null): array
{
    $igT = ['account_id' => test()->ig->id, 'content_type' => 'instagram_image'];
    $fbT = ['account_id' => test()->fb->id, 'content_type' => 'facebook_photo'];

    return [$ig === null ? $igT : $igT + ['text' => $ig], $fb === null ? $fbT : $fbT + ['text' => $fb]];
}

test('McpTargetText: create_post acepta el texto por destino y devuelve text y effective_text', function () {
    [$err, $post] = $this->callTool($this->key, 'create_post', mtArgs(mtTargets(null, 'Solo para Facebook')));

    expect($err)->toBeFalse();
    $byAccount = collect($post['targets'])->keyBy('account_id');
    expect($byAccount[$this->ig->id])->toMatchArray(['text' => null, 'effective_text' => 'Plantilla'])
        ->and($byAccount[$this->fb->id])->toMatchArray(['text' => 'Solo para Facebook', 'effective_text' => 'Solo para Facebook']);
});

test('McpTargetText: get_post devuelve el texto por destino y la plantilla vinculada sigue a la plantilla', function () {
    [, $created] = $this->callTool($this->key, 'create_post', mtArgs(mtTargets(null, 'Propio FB')));

    [$err, $post] = $this->callTool($this->key, 'get_post', ['post_id' => $created['id']]);

    $byAccount = collect($post['targets'])->keyBy('account_id');
    expect($err)->toBeFalse()->and($post['text'])->toBe('Plantilla')
        ->and($byAccount[$this->ig->id]['effective_text'])->toBe('Plantilla')
        ->and($byAccount[$this->fb->id]['text'])->toBe('Propio FB');
});

test('McpTargetText: update_post cambia el texto propio, lo vincula de nuevo al omitirlo y un texto vacio o solo con espacios es vinculado', function () {
    [, $created] = $this->callTool($this->key, 'create_post', mtArgs(mtTargets(null, 'Propio FB')));

    [$err, $post] = $this->callTool($this->key, 'update_post', ['post_id' => $created['id']] + mtArgs(mtTargets('Propio IG', null), ['text' => 'Plantilla 2']));
    $byAccount = collect($post['targets'])->keyBy('account_id');
    expect($err)->toBeFalse()
        ->and($byAccount[$this->ig->id])->toMatchArray(['text' => 'Propio IG', 'effective_text' => 'Propio IG'])
        ->and($byAccount[$this->fb->id])->toMatchArray(['text' => null, 'effective_text' => 'Plantilla 2']);

    // A3/B1 (revision de la Fase 3): un texto propio vacio o solo con espacios es `null` (vinculado), como en la web; si no, se
    // publicaria vacio y `findPublished` de Instagram y Facebook no podria reconocer la publicacion.
    foreach (['', '   ', "\n\t "] as $vacio) {
        [$err, $post] = $this->callTool($this->key, 'update_post', ['post_id' => $created['id']] + mtArgs(mtTargets($vacio, $vacio)));
        $byAccount = collect($post['targets'])->keyBy('account_id');
        expect($err)->toBeFalse()
            ->and($byAccount[$this->ig->id])->toMatchArray(['text' => null, 'effective_text' => 'Plantilla'])
            ->and($byAccount[$this->fb->id])->toMatchArray(['text' => null, 'effective_text' => 'Plantilla']);
    }
});

test('McpTargetText: el maximo y los hashtags se validan por red con el texto efectivo y el error sale como en la web', function () {
    $tags = 'Hola '.implode(' ', array_map(fn ($i) => "#t$i", range(1, 31)));

    // Texto propio de Instagram con 31 hashtags: falla solo ese destino y no se guarda nada.
    [$err, $msg] = $this->callTool($this->key, 'create_post', mtArgs(mtTargets($tags, null)));
    expect($err)->toBeTrue()->and($msg)->toContain('targets.0:')->and($msg)->toContain('31 hashtags')->and($msg)->not->toContain('targets.1:');

    // Maximo de Instagram (2200) con texto propio; el mismo texto en Facebook (63206) es valido.
    [$err, $msg] = $this->callTool($this->key, 'create_post', mtArgs(mtTargets(str_repeat('a', 2201), null)));
    expect($err)->toBeTrue()->and($msg)->toContain('2201 caracteres');
    [$err] = $this->callTool($this->key, 'create_post', mtArgs(mtTargets(null, str_repeat('a', 2201))));
    expect($err)->toBeFalse();

    // Una plantilla larga solo afecta a los destinos vinculados.
    [$err, $msg] = $this->callTool($this->key, 'create_post', mtArgs(mtTargets('corto', null), ['text' => str_repeat('a', 2201)]));
    expect($err)->toBeFalse();
    [$err, $msg] = $this->callTool($this->key, 'create_post', mtArgs(mtTargets(null, null), ['text' => str_repeat('a', 2201)]));
    expect($err)->toBeTrue()->and($msg)->toContain('targets.0:');

    // La regla de PostRequest (tipo) tambien se hereda.
    [$err, $msg] = $this->callTool($this->key, 'create_post', mtArgs([['account_id' => $this->ig->id, 'content_type' => 'instagram_image', 'text' => ['no' => 'cadena']]]));
    expect($err)->toBeTrue()->and($msg)->toContain('Datos no validos');
});

test('McpTargetText: un post de otro usuario no filtra el texto por destino', function () {
    [, $created] = $this->callTool($this->key, 'create_post', mtArgs(mtTargets(null, 'Secreto FB')));
    $other = $this->mcpKey(User::factory()->create());

    [$err, $msg] = $this->callTool($other, 'get_post', ['post_id' => $created['id']]);

    expect($err)->toBeTrue()->and(json_encode($msg))->not->toContain('Secreto FB')
        ->and(Post::withoutUserScope()->count())->toBe(1);
});
