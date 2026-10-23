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

beforeEach(function () {
    Http::preventStrayRequests(); // nada publica en real
    Carbon::setTestNow('2026-11-01 08:00:00');
    $this->user = User::factory()->create();
    $this->brand = Brand::factory()->for($this->user)->create(['name' => 'Proyecto A', 'timezone' => 'Europe/Madrid']);
    $this->ig = SocialAccount::factory()->for($this->user)->create(['brand_id' => $this->brand->id, 'network' => Network::Instagram, 'name' => 'IG A']);
    $this->image = MediaAsset::factory()->for($this->user)->create();
    $this->key = $this->mcpKey($this->user);
});

function mcpPostArgs(array $over = []): array
{
    return array_merge([
        'brand_id' => test()->brand->id,
        'text' => 'Hola desde un agente',
        'scheduled_at' => '2026-11-02 10:00',
        'schedule' => true,
        'media_ids' => [test()->image->id],
        'targets' => [['account_id' => test()->ig->id, 'content_type' => 'instagram_image']],
    ], $over);
}

test('McpTools: list_brands y list_accounts devuelven marcas y cuentas con sus tipos de contenido', function () {
    [$err, $brands] = $this->callTool($this->key, 'list_brands');
    expect($err)->toBeFalse()->and($brands['brands'])->toHaveCount(1)->and($brands['brands'][0])->toMatchArray(['id' => $this->brand->id, 'name' => 'Proyecto A', 'timezone' => 'Europe/Madrid']);

    [$err, $accounts] = $this->callTool($this->key, 'list_accounts', ['brand_id' => $this->brand->id]);
    expect($err)->toBeFalse()->and($accounts['accounts'])->toHaveCount(1)
        ->and($accounts['accounts'][0]['content_types'])->toContain('instagram_image', 'instagram_reel')
        ->and(json_encode($accounts))->not->toContain('access_token');
});

test('McpTools: list_media devuelve la media lista del usuario', function () {
    [$err, $media] = $this->callTool($this->key, 'list_media');

    expect($err)->toBeFalse()->and(collect($media['media'])->pluck('id')->all())->toBe([$this->image->id]);
});

test('McpTools: list_media pagina con limit y cursor (has_more / next_cursor)', function () {
    $second = MediaAsset::withoutUserScope()->find($this->image->id)->replicate();
    $second->path = 'media/test/otra.jpg';
    $second->save();

    [, $p1] = $this->callTool($this->key, 'list_media', ['limit' => 1]);
    expect($p1['media'])->toHaveCount(1)->and($p1['has_more'])->toBeTrue()->and($p1['next_cursor'])->toBe($second->id);

    [, $p2] = $this->callTool($this->key, 'list_media', ['limit' => 1, 'before_id' => $p1['next_cursor']]);
    expect(collect($p2['media'])->pluck('id')->all())->toBe([$this->image->id])->and($p2['has_more'])->toBeFalse()->and($p2['next_cursor'])->toBeNull();
});

test('McpTools: crea una publicacion programada y la consulta', function () {
    [$err, $post] = $this->callTool($this->key, 'create_post', mcpPostArgs());

    expect($err)->toBeFalse()->and($post)->toMatchArray(['status' => 'programada', 'scheduled_at_utc' => '2026-11-02T09:00:00+00:00', 'scheduled_at_local' => '2026-11-02T10:00'])
        ->and($post['targets'][0])->toMatchArray(['network' => 'instagram', 'content_type' => 'instagram_image', 'status' => 'programada'])
        ->and($post['media_ids'])->toBe([$this->image->id]);

    [, $same] = $this->callTool($this->key, 'get_post', ['post_id' => $post['id']]);
    expect($same['id'])->toBe($post['id'])->and(Post::withoutUserScope()->find($post['id'])->user_id)->toBe($this->user->id);
});

test('McpTools: crea un borrador sin fecha ni destinos', function () {
    [$err, $post] = $this->callTool($this->key, 'create_post', ['brand_id' => $this->brand->id, 'text' => 'idea', 'schedule' => false]);

    expect($err)->toBeFalse()->and($post['status'])->toBe('borrador')->and($post['targets'])->toBe([]);
});

test('McpTools: edita, reprograma, cancela y borra una publicacion', function () {
    [, $post] = $this->callTool($this->key, 'create_post', mcpPostArgs());

    [$err, $edited] = $this->callTool($this->key, 'update_post', mcpPostArgs(['post_id' => $post['id'], 'text' => 'Texto nuevo']) + []);
    expect($err)->toBeFalse()->and($edited['text'])->toBe('Texto nuevo');

    [$err, $moved] = $this->callTool($this->key, 'reschedule_post', ['post_id' => $post['id'], 'scheduled_at' => '2026-11-05 18:30']);
    expect($err)->toBeFalse()->and($moved['scheduled_at_local'])->toBe('2026-11-05T18:30');

    [$err, $cancelled] = $this->callTool($this->key, 'cancel_post', ['post_id' => $post['id']]);
    expect($err)->toBeFalse()->and($cancelled['status'])->toBe('borrador');

    [$err, $deleted] = $this->callTool($this->key, 'delete_post', ['post_id' => $post['id']]);
    expect($err)->toBeFalse()->and($deleted)->toBe(['id' => $post['id'], 'deleted' => true])
        ->and(Post::withoutUserScope()->find($post['id']))->toBeNull();
});

test('McpTools: el calendario devuelve las publicaciones del periodo y filtra por marca', function () {
    $this->callTool($this->key, 'create_post', mcpPostArgs());

    [$err, $cal] = $this->callTool($this->key, 'get_calendar', ['view' => 'week', 'date' => '2026-11-02', 'brand_id' => $this->brand->id]);

    expect($err)->toBeFalse()->and($cal['items'])->toHaveCount(1)->and($cal['items'][0]['local_datetime'])->toBe('2026-11-02T10:00');
});

test('McpTools: los errores de validacion llegan como mensaje legible y no guardan nada', function () {
    [$err, $msg] = $this->callTool($this->key, 'create_post', mcpPostArgs(['scheduled_at' => '2026-10-01 10:00']));
    expect($err)->toBeTrue()->and($msg)->toBeString()->toContain('Datos no validos');

    [$err, $msg] = $this->callTool($this->key, 'create_post', mcpPostArgs(['targets' => [['account_id' => $this->ig->id, 'content_type' => 'tipo_raro']]]));
    expect($err)->toBeTrue()->and($msg)->toContain('Datos no validos');

    [$err, $msg] = $this->callTool($this->key, 'create_post', ['brand_id' => $this->brand->id, 'text' => 'x']); // falta schedule
    expect($err)->toBeTrue()->and($msg)->toContain('schedule');

    expect(Post::withoutUserScope()->count())->toBe(0);
});

test('McpTools: una publicacion ya publicada no se edita ni se borra (mensaje legible)', function () {
    [, $post] = $this->callTool($this->key, 'create_post', mcpPostArgs());
    Post::withoutUserScope()->find($post['id'])->targets()->update(['status' => 'publicada']);

    [$err, $msg] = $this->callTool($this->key, 'delete_post', ['post_id' => $post['id']]);

    expect($err)->toBeTrue()->and($msg)->toBeString()->not->toBe('')->and(Post::withoutUserScope()->find($post['id']))->not->toBeNull();
});

test('McpTools: las abilities limitan lo que puede hacer cada key', function () {
    $reader = $this->mcpKey($this->user, ['posts:read'], 'lector');

    [$err, $msg] = $this->callTool($reader, 'create_post', mcpPostArgs());
    expect($err)->toBeTrue()->and($msg)->toContain('posts:write')->and(Post::withoutUserScope()->count())->toBe(0);

    [$err] = $this->callTool($reader, 'get_calendar', ['date' => '2026-11-02']);
    expect($err)->toBeFalse();

    [$err, $msg] = $this->callTool($reader, 'list_brands');
    expect($err)->toBeTrue()->and($msg)->toContain('brands:read');
});
