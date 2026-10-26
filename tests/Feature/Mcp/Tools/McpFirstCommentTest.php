<?php

use App\Connectors\Network;
use App\Enums\FirstCommentStatus;
use App\Enums\PostStatus;
use App\Models\Brand;
use App\Models\MediaAsset;
use App\Models\PostTarget;
use App\Models\SocialAccount;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Tests\Support\McpClient;

uses(McpClient::class);

// C-01b (T-15, CA-07): paridad del MCP con la web para el primer comentario.

beforeEach(function () {
    Http::preventStrayRequests();
    Carbon::setTestNow('2026-11-01 08:00:00');
    $this->user = User::factory()->create();
    $this->brand = Brand::factory()->for($this->user)->create(['timezone' => 'Europe/Madrid']);
    $this->ig = SocialAccount::factory()->for($this->user)->create(['brand_id' => $this->brand->id, 'network' => Network::Instagram]);
    $this->tt = SocialAccount::factory()->for($this->user)->create(['brand_id' => $this->brand->id, 'network' => Network::TikTok]);
    $this->image = MediaAsset::factory()->for($this->user)->create();
    $this->key = $this->mcpKey($this->user);
});

function fcArgs(array $targets, array $over = []): array
{
    return array_merge([
        'brand_id' => test()->brand->id, 'text' => 'Plantilla', 'scheduled_at' => '2026-11-02 10:00', 'schedule' => true,
        'media_ids' => [test()->image->id], 'targets' => $targets,
    ], $over);
}

function fcIg(array $extra = []): array
{
    return [['account_id' => test()->ig->id, 'content_type' => 'instagram_image'] + $extra];
}

test('McpFirstComment: create_post acepta first_comment y get_post lo devuelve con su estado', function () {
    [$err, $created] = $this->callTool($this->key, 'create_post', fcArgs(fcIg(['first_comment' => 'Mas info en el enlace'])));
    expect($err)->toBeFalse()->and($created['targets'][0])->toMatchArray(['first_comment' => 'Mas info en el enlace', 'first_comment_status' => null, 'first_comment_error' => null, 'first_comment_may_exist' => false]);

    PostTarget::withoutUserScope()->where('post_id', $created['id'])->update(['first_comment_status' => FirstCommentStatus::Failed->value, 'first_comment_error' => 'Comentarios cerrados', 'first_comment_sent_at' => now()]);

    [$err, $post] = $this->callTool($this->key, 'get_post', ['post_id' => $created['id']]);
    expect($err)->toBeFalse()->and($post['targets'][0])->toMatchArray([
        'first_comment' => 'Mas info en el enlace', 'first_comment_status' => 'fallido', 'first_comment_error' => 'Comentarios cerrados', 'first_comment_may_exist' => true,
    ]);
});

test('McpFirstComment: las reglas son las de la web (maximo por red, TikTok y Stories sin comentario)', function () {
    [$err, $msg] = $this->callTool($this->key, 'create_post', fcArgs(fcIg(['first_comment' => str_repeat('a', 2201)])));
    expect($err)->toBeTrue()->and($msg)->toContain('targets.0:')->and($msg)->toContain('2200');

    [$err, $msg] = $this->callTool($this->key, 'create_post', fcArgs([['account_id' => $this->tt->id, 'content_type' => 'tiktok_video', 'first_comment' => 'Hola']]));
    expect($err)->toBeTrue()->and($msg)->toContain('primer comentario');

    [$err, $msg] = $this->callTool($this->key, 'create_post', fcArgs(fcIg(['first_comment' => ['no' => 'cadena']])));
    expect($err)->toBeTrue()->and($msg)->toContain('Datos no validos');
});

test('McpFirstComment: update_post cambia el comentario y un vacio lo quita', function () {
    [, $created] = $this->callTool($this->key, 'create_post', fcArgs(fcIg(['first_comment' => 'Uno'])));

    [$err, $post] = $this->callTool($this->key, 'update_post', ['post_id' => $created['id']] + fcArgs(fcIg(['first_comment' => 'Dos'])));
    expect($err)->toBeFalse()->and($post['targets'][0]['first_comment'])->toBe('Dos');

    [$err, $post] = $this->callTool($this->key, 'update_post', ['post_id' => $created['id']] + fcArgs(fcIg(['first_comment' => '  '])));
    expect($err)->toBeFalse()->and($post['targets'][0]['first_comment'])->toBeNull();
});

test('McpFirstComment: update_post sobre un post ya publicado rechaza first_comment y no lo cambia', function () {
    [, $created] = $this->callTool($this->key, 'create_post', fcArgs(fcIg(['first_comment' => 'Original'])));
    PostTarget::withoutUserScope()->where('post_id', $created['id'])->update(['status' => PostStatus::Published->value, 'first_comment_status' => FirstCommentStatus::Failed->value]);

    [$err] = $this->callTool($this->key, 'update_post', ['post_id' => $created['id']] + fcArgs(fcIg(['first_comment' => 'Cambiado'])));

    expect($err)->toBeTrue()->and(PostTarget::withoutUserScope()->where('post_id', $created['id'])->value('first_comment'))->toBe('Original');
});

test('McpFirstComment: un post de otro usuario no filtra el comentario', function () {
    [, $created] = $this->callTool($this->key, 'create_post', fcArgs(fcIg(['first_comment' => 'Secreto'])));
    $other = $this->mcpKey(User::factory()->create());

    [$err, $msg] = $this->callTool($other, 'get_post', ['post_id' => $created['id']]);

    expect($err)->toBeTrue()->and(json_encode($msg))->not->toContain('Secreto');
});
