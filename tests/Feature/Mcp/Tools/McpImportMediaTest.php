<?php

use App\Jobs\ImportRemoteMedia;
use App\Models\MediaAsset;
use App\Models\User;
use App\Services\Media\UrlGuard;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\Support\McpClient;

uses(McpClient::class);

// C-04a (T-38, CA-14/CA-15): paridad MCP de la importacion por URL. Misma UrlGuard y mismo servicio que la web.

beforeEach(function () {
    Storage::fake('local');
    config(['filesystems.media' => 'local']);
    Http::preventStrayRequests();
    Queue::fake();
    $this->user = User::factory()->create();
    $this->key = $this->mcpKey($this->user);
    app()->instance(UrlGuard::class, new UrlGuard(fn (string $host) => $host === 'cdn.example.com' ? ['93.184.216.34'] : []));
});

test('McpImportMedia: acepta una URL publica y devuelve el asset pendiente', function () {
    [$err, $out] = $this->callTool($this->key, 'import_media_from_url', ['url' => 'https://cdn.example.com/foto.jpg?token=SECRETO']);

    expect($err)->toBeFalse()
        ->and($out['asset'])->toMatchArray(['status' => 'pendiente', 'source' => 'url', 'name' => 'foto.jpg', 'bytes' => 0])
        ->and(json_encode($out))->not->toContain('SECRETO');
    Queue::assertPushedOn('media', ImportRemoteMedia::class, fn ($job) => $job->assetId === $out['asset']['id']);
    Http::assertNothingSent();

    $asset = MediaAsset::withoutUserScope()->find($out['asset']['id']);
    expect($asset->user_id)->toBe($this->user->id);
});

test('McpImportMedia: rechaza URLs internas con la misma guardia, sin crear nada ni pedir nada (CA-15)', function (string $url) {
    [$err, $msg] = $this->callTool($this->key, 'import_media_from_url', ['url' => $url]);

    expect($err)->toBeTrue()->and($msg)->toBeString()->not->toBe('');
    expect(MediaAsset::withoutUserScope()->count())->toBe(0);
    Queue::assertNothingPushed();
    Http::assertNothingSent();
})->with([
    'http://127.0.0.1/a.jpg', 'http://169.254.169.254/latest/meta-data/', 'http://[::1]/a.jpg', 'http://[::ffff:7f00:1]/a.jpg',
    'http://localhost/a.jpg', 'http://0.0.0.0/a.jpg', 'http://user:pass@cdn.example.com/a.jpg', 'file:///etc/passwd', 'http://2130706433/a.jpg',
]);

test('McpImportMedia: respeta el limite de 3 importaciones vivas y es por usuario', function () {
    foreach (range(1, 3) as $i) {
        [$err] = $this->callTool($this->key, 'import_media_from_url', ['url' => "https://cdn.example.com/{$i}.jpg"]);
        expect($err)->toBeFalse();
    }

    [$err, $msg] = $this->callTool($this->key, 'import_media_from_url', ['url' => 'https://cdn.example.com/4.jpg']);
    expect($err)->toBeTrue()->and($msg)->toContain('3 importaciones');

    [$err] = $this->callTool($this->mcpKey(User::factory()->create()), 'import_media_from_url', ['url' => 'https://cdn.example.com/otro.jpg']);
    expect($err)->toBeFalse();
});

test('McpImportMedia: exige la url y el permiso posts:write', function () {
    [$err, $msg] = $this->callTool($this->key, 'import_media_from_url', []);
    expect($err)->toBeTrue()->and($msg)->toContain('url');

    $readOnly = $this->mcpKey($this->user, ['brands:read']);
    [$err, $msg] = $this->callTool($readOnly, 'import_media_from_url', ['url' => 'https://cdn.example.com/a.jpg']);
    expect($err)->toBeTrue()->and($msg)->toContain('posts:write');
    expect(MediaAsset::withoutUserScope()->count())->toBe(0);
});

test('McpImportMedia: el asset pendiente no sale en list_media hasta que esta listo', function () {
    $this->callTool($this->key, 'import_media_from_url', ['url' => 'https://cdn.example.com/a.jpg']);
    MediaAsset::factory()->create(['user_id' => $this->user->id, 'original_name' => 'lista.jpg']);

    [$err, $list] = $this->callTool($this->key, 'list_media');

    expect($err)->toBeFalse()->and(collect($list['media'])->pluck('name')->all())->toBe(['lista.jpg']);
});

test('McpImportMedia: tools/list incluye la herramienta y sigue cabiendo en una pagina', function () {
    $response = $this->rpc($this->key, 'tools/list');
    $names = collect($response->json('result.tools'))->pluck('name')->all();

    expect($names)->toContain('import_media_from_url')->toHaveCount(25)->and($response->json('result.nextCursor'))->toBeNull();
});

test('McpImportMedia (revision): list_media con status ve el progreso y el motivo del fallo; por defecto solo lista las «listo»', function () {
    $pending = MediaAsset::factory()->create(['user_id' => $this->user->id, 'status' => 'pendiente', 'source' => 'url', 'import_state' => ['bytes' => 500, 'total' => 2000, 'validator' => '"secreto"']]);
    $invalid = MediaAsset::factory()->create(['user_id' => $this->user->id, 'status' => 'invalido', 'source' => 'url', 'error' => 'El servidor remoto respondió 404.']);
    $ready = MediaAsset::factory()->create(['user_id' => $this->user->id, 'status' => 'listo']);
    $foreign = MediaAsset::factory()->create(['user_id' => User::factory()->create()->id, 'status' => 'pendiente', 'source' => 'url']);

    [, $default] = $this->callTool($this->key, 'list_media');
    expect(collect($default['media'])->pluck('id')->all())->toBe([$ready->id]);

    [$err, $p] = $this->callTool($this->key, 'list_media', ['status' => 'pendiente']);
    expect($err)->toBeFalse()->and($p['media'])->toHaveCount(1)
        ->and($p['media'][0])->toMatchArray(['id' => $pending->id, 'status' => 'pendiente', 'bytes' => 500, 'total' => 2000])
        ->and(json_encode($p))->not->toContain('secreto');

    [, $i] = $this->callTool($this->key, 'list_media', ['status' => 'invalido']);
    expect(collect($i['media'])->pluck('id')->all())->toBe([$invalid->id])->and($i['media'][0]['error'])->toBe('El servidor remoto respondió 404.');

    [, $all] = $this->callTool($this->key, 'list_media', ['status' => 'todos']);
    expect(collect($all['media'])->pluck('id')->sort()->values()->all())->toBe(collect([$pending->id, $invalid->id, $ready->id])->sort()->values()->all())
        ->and(collect($all['media'])->pluck('id')->all())->not->toContain($foreign->id);

    [$err2, $msg] = $this->callTool($this->key, 'list_media', ['status' => 'borrada']);
    expect($err2)->toBeTrue()->and($msg)->toContain('status');
});
