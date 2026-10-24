<?php

use App\Connectors\Meta\Instagram\InstagramGrid;
use App\Connectors\Network;
use App\Models\Brand;
use App\Models\SocialAccount;
use App\Models\User;
use Tests\Support\FakeGraph;

// C-02 (CA-08): lector de `/{ig}/media` para la cuadricula. Red simulada con FakeGraph; nunca sale a internet.

beforeEach(function () {
    config(['social.meta.graph_version' => 'v23.0']);
    $user = User::factory()->create();
    $brand = Brand::factory()->for($user)->create();
    $this->account = SocialAccount::factory()->for($user)->create(['brand_id' => $brand->id, 'network' => Network::Instagram, 'external_id' => 'IGU1', 'access_token' => 'tok']);
});

$media = fn (string $id, array $extra = []) => array_merge(['id' => $id, 'media_type' => 'IMAGE', 'media_url' => "https://cdn/$id.jpg", 'permalink' => "https://instagram.com/p/$id/", 'timestamp' => '2026-11-01T10:00:00+0000'], $extra);

it('lee y normaliza imagen, video y carrusel (primera pieza) con los campos pedidos', function () use ($media) {
    $graph = (new FakeGraph)->on('GET', '#^IGU1/media$#', ['data' => [
        $media('A'),
        $media('B', ['media_type' => 'VIDEO', 'media_url' => 'https://cdn/B.mp4', 'thumbnail_url' => 'https://cdn/B.jpg']),
        $media('C', ['media_type' => 'VIDEO', 'media_url' => 'https://cdn/C.mp4']),
        $media('D', ['media_type' => 'CAROUSEL_ALBUM', 'media_url' => 'https://cdn/D0.jpg', 'children' => ['data' => [
            ['id' => 'D1', 'media_type' => 'IMAGE', 'media_url' => 'https://cdn/D1.jpg'],
            ['id' => 'D2', 'media_type' => 'IMAGE', 'media_url' => 'https://cdn/D2.jpg'],
        ]]]),
        $media('E', ['media_type' => 'CAROUSEL_ALBUM', 'media_url' => null, 'children' => ['data' => [['id' => 'E1', 'media_type' => 'VIDEO', 'media_url' => 'https://cdn/E1.mp4', 'thumbnail_url' => 'https://cdn/E1.jpg']]]]),
    ]]);

    $r = (new InstagramGrid)->read($this->account);

    expect($r['error'])->toBeNull()->and($r['items'])->toHaveCount(5);
    [$a, $b, $c, $d, $e] = $r['items'];
    expect($a)->toMatchArray(['id' => 'A', 'type' => 'IMAGE', 'image_url' => 'https://cdn/A.jpg', 'video_url' => null, 'permalink' => 'https://instagram.com/p/A/', 'timestamp' => '2026-11-01T10:00:00+0000'])
        ->and($b)->toMatchArray(['type' => 'VIDEO', 'image_url' => 'https://cdn/B.jpg', 'video_url' => null])
        ->and($c)->toMatchArray(['type' => 'VIDEO', 'image_url' => null, 'video_url' => 'https://cdn/C.mp4'])
        ->and($d)->toMatchArray(['type' => 'CAROUSEL_ALBUM', 'image_url' => 'https://cdn/D1.jpg'])
        ->and($e)->toMatchArray(['type' => 'CAROUSEL_ALBUM', 'image_url' => 'https://cdn/E1.jpg']);

    $call = $graph->callsTo('GET', '#^IGU1/media$#')[0];
    expect($call['authorization'])->toBe('Bearer tok')->and($call['params']['fields'])->toContain('thumbnail_url')->toContain('permalink')->toContain('timestamp')->toContain('media_type');
});

it('pagina siguiendo el cursor y se detiene al llegar al tope', function () use ($media) {
    $graph = (new FakeGraph)->on('GET', '#^IGU1/media$#', FakeGraph::seq(
        ['data' => [$media('A'), $media('B')], 'paging' => ['cursors' => ['after' => 'CUR1'], 'next' => 'https://graph.facebook.com/next']],
        ['data' => [$media('C')], 'paging' => ['cursors' => ['after' => 'CUR2']]],
    ));

    $r = (new InstagramGrid)->read($this->account, 24, 5);

    expect(array_column($r['items'], 'id'))->toBe(['A', 'B', 'C']);
    $calls = $graph->callsTo('GET', '#^IGU1/media$#');
    expect($calls)->toHaveCount(2)->and($calls[1]['params']['after'])->toBe('CUR1');
});

it('no pide mas paginas que el tope', function () use ($media) {
    $graph2 = (new FakeGraph)->on('GET', '#^IGU1/media$#', ['data' => [$media('A')], 'paging' => ['cursors' => ['after' => 'X'], 'next' => 'https://graph.facebook.com/next']]);
    $r = (new InstagramGrid)->read($this->account, 24, 2);
    expect($graph2->callsTo('GET', '#^IGU1/media$#'))->toHaveCount(2)->and($r['items'])->toHaveCount(2);
});

it('traduce un error de Graph a un mensaje sin lanzar excepcion', function () {
    (new FakeGraph)->on('GET', '#^IGU1/media$#', FakeGraph::error(190, 'Token caducado', null, 401));

    $r = (new InstagramGrid)->read($this->account);

    expect($r['items'])->toBe([])->and($r['error'])->toContain('Reconecta');
});

it('si la segunda pagina falla conserva lo leido y avisa', function () use ($media) {
    (new FakeGraph)->on('GET', '#^IGU1/media$#', FakeGraph::seq(
        ['data' => [$media('A')], 'paging' => ['cursors' => ['after' => 'C1'], 'next' => 'https://graph.facebook.com/next']],
        FakeGraph::error(2, 'caido', null, 500),
    ));

    $r = (new InstagramGrid)->read($this->account, 24, 3);

    expect($r['items'])->toHaveCount(1)->and($r['error'])->not->toBeNull();
});

it('pide solo los campos de lectura basicos (sin permisos nuevos)', function () {
    $graph = (new FakeGraph)->on('GET', '#^IGU1/media$#', ['data' => []]);
    (new InstagramGrid)->read($this->account);
    expect($graph->callsTo('GET', '#^IGU1/media$#')[0]['params']['fields'])->not->toContain('insights')->and($graph->callsTo('POST', '#.#'))->toBeEmpty();
});
