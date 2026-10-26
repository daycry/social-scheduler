<?php

use App\Jobs\ImportRemoteMedia;
use App\Models\MediaAsset;
use App\Models\User;
use App\Services\Media\UrlGuard;
use App\Services\MediaStorage;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;

// C-04a (T-36, CA-14/CA-15): endpoint de importacion por URL. Sin red: DNS inyectado, cola falsa y `Http::fake`.

beforeEach(function () {
    Storage::fake('local');
    config(['filesystems.media' => 'local']);
    Http::preventStrayRequests();
    Queue::fake();
    $this->user = User::factory()->create();
    app()->instance(UrlGuard::class, new UrlGuard(fn (string $host) => $host === 'cdn.example.com' ? ['93.184.216.34'] : []));
});

function importPost(string $url, ?User $as = null)
{
    return test()->actingAs($as ?? test()->user)->postJson(route('media.import-url'), ['url' => $url]);
}

test('ImportUrlEndpoint: una URL publica crea un asset pendiente y encola la descarga en la cola media', function () {
    $response = importPost('https://cdn.example.com/foto.jpg?token=SECRETO');

    $response->assertStatus(202)->assertJsonPath('asset.status', 'pendiente')->assertJsonPath('asset.source', 'url')
        ->assertJsonPath('asset.name', 'foto.jpg')->assertJsonPath('asset.bytes', 0);
    expect($response->getContent())->not->toContain('SECRETO');

    $asset = MediaAsset::withoutUserScope()->latest('id')->first();
    expect($asset->user_id)->toBe($this->user->id)->and($asset->source_ref)->toBe('https://cdn.example.com/foto.jpg');
    Queue::assertPushedOn('media', ImportRemoteMedia::class, fn ($job) => $job->assetId === $asset->id && $job->reference === 'https://cdn.example.com/foto.jpg?token=SECRETO');
    Http::assertNothingSent();
});

test('ImportUrlEndpoint: una URL interna se rechaza con 422 en espanol y no crea nada (CA-15)', function (string $url) {
    importPost($url)->assertStatus(422)->assertJsonPath('message', fn ($m) => is_string($m) && $m !== '');

    expect(MediaAsset::withoutUserScope()->count())->toBe(0);
    Queue::assertNothingPushed();
    Http::assertNothingSent();
})->with([
    'http://127.0.0.1/imagen.jpg', 'http://localhost/a.jpg', 'http://169.254.169.254/latest/meta-data/', 'http://[::1]/a.jpg',
    'http://[::ffff:10.0.0.1]/a.jpg', 'http://0.0.0.0/a.jpg', 'http://10.0.0.5/a.jpg', 'ftp://cdn.example.com/a.jpg',
    'http://user:pass@cdn.example.com/a.jpg', 'http://cdn.example.com:22/a.jpg', 'http://2130706433/a.jpg', 'http://no-resuelve.example.com/a.jpg',
]);

test('ImportUrlEndpoint: el mensaje de una URL interna esta en espanol', function () {
    importPost('http://127.0.0.1/imagen.jpg')->assertStatus(422)->assertJsonPath('message', 'La URL apunta a una direccion no permitida.');
});

test('ImportUrlEndpoint: la url es obligatoria y no puede ser enorme', function () {
    $this->actingAs($this->user)->postJson(route('media.import-url'), [])->assertStatus(422)->assertJsonValidationErrors('url');
    importPost('https://cdn.example.com/'.str_repeat('a', 2100))->assertStatus(422)->assertJsonValidationErrors('url');
});

test('ImportUrlEndpoint: la cuarta importacion viva se rechaza (maximo 3 por usuario)', function () {
    foreach (range(1, 3) as $i) {
        importPost("https://cdn.example.com/{$i}.jpg")->assertStatus(202);
    }

    importPost('https://cdn.example.com/4.jpg')->assertStatus(422)->assertJsonPath('message', fn ($m) => str_contains($m, '3 importaciones'));
    expect(MediaAsset::withoutUserScope()->count())->toBe(3);
});

test('ImportUrlEndpoint: el limite es por usuario y no cuenta las terminadas ni las antiguas', function () {
    foreach (range(1, 3) as $i) {
        importPost("https://cdn.example.com/{$i}.jpg")->assertStatus(202);
    }
    $other = User::factory()->create();
    importPost('https://cdn.example.com/otro.jpg', $other)->assertStatus(202); // otro usuario: su propio cupo

    // Una termina: libera cupo.
    MediaAsset::withoutUserScope()->where('user_id', $this->user->id)->orderBy('id')->first()->forceFill(['status' => 'invalido'])->save();
    importPost('https://cdn.example.com/5.jpg')->assertStatus(202);

    // Una pendiente de hace mas de 6 h esta abandonada: tampoco cuenta.
    MediaAsset::withoutUserScope()->where('user_id', $this->user->id)->where('status', 'pendiente')->orderBy('id')->first()->forceFill(['created_at' => now()->subHours(7)])->save();
    importPost('https://cdn.example.com/6.jpg')->assertStatus(202);
});

test('ImportUrlEndpoint: las subidas normales pendientes no cuentan para el limite', function () {
    MediaAsset::factory()->count(5)->create(['user_id' => $this->user->id, 'status' => 'pendiente']);

    importPost('https://cdn.example.com/a.jpg')->assertStatus(202);
});

test('ImportUrlEndpoint: con almacenamiento S3/R2 responde 422 claro', function () {
    $r2 = new class extends MediaStorage
    {
        public function isS3(): bool
        {
            return true;
        }
    };
    app()->instance(MediaStorage::class, $r2);

    importPost('https://cdn.example.com/a.jpg')->assertStatus(422)->assertJsonPath('message', fn ($m) => str_contains($m, 'almacenamiento local'));
    expect(MediaAsset::withoutUserScope()->count())->toBe(0);
});

test('ImportUrlEndpoint: exige sesion', function () {
    $this->postJson(route('media.import-url'), ['url' => 'https://cdn.example.com/a.jpg'])->assertStatus(401);
});

test('ImportUrlEndpoint: tiene throttle (10 por minuto)', function () {
    foreach (range(1, 10) as $i) {
        importPost('http://127.0.0.1/a.jpg')->assertStatus(422);
    }

    importPost('http://127.0.0.1/a.jpg')->assertStatus(429);
});

test('ImportUrlEndpoint: el estado se sondea con GET y solo lo ve su dueno', function () {
    $asset = MediaAsset::factory()->create([
        'user_id' => $this->user->id, 'status' => 'pendiente', 'source' => 'url', 'source_ref' => 'https://cdn.example.com/a.jpg',
        'import_state' => ['bytes' => 40, 'total' => 100, 'validator' => '"e"'],
    ]);

    $this->actingAs($this->user)->getJson(route('media.show', $asset))->assertOk()
        ->assertJsonPath('asset.status', 'pendiente')->assertJsonPath('asset.bytes', 40)->assertJsonPath('asset.total', 100)
        ->assertJsonMissingPath('asset.source_ref')->assertJsonMissingPath('asset.import_state');

    expect($this->actingAs(User::factory()->create())->getJson(route('media.show', $asset))->status())->toBeIn([403, 404]);
});

test('ImportUrlEndpoint: app:media-prune borra los parciales abandonados y respeta los vivos', function () {
    $disk = Storage::disk('local');
    $live = MediaAsset::factory()->create(['user_id' => $this->user->id, 'status' => 'pendiente', 'path' => 'media/1/viva.part', 'source' => 'url']);
    $disk->put('media/1/viva.part', 'x');
    $disk->put('media/1/huerfana.part', 'x');
    $disk->put('media/1/reciente.part', 'x');
    $disk->put('media/1/foto.jpg', 'x'); // no es un parcial
    $old = now()->subHours(30)->getTimestamp();
    foreach (['viva.part', 'huerfana.part', 'foto.jpg'] as $f) {
        touch($disk->path("media/1/$f"), $old);
    }

    $this->artisan('app:media-prune')->expectsOutputToContain('Parciales de importacion eliminados: 1')->assertSuccessful();

    $disk->assertMissing('media/1/huerfana.part');
    $disk->assertExists('media/1/viva.part');
    $disk->assertExists('media/1/reciente.part');
    $disk->assertExists('media/1/foto.jpg');
    expect($live->fresh())->not->toBeNull();
});

// ---- T-41: revision de seguridad SSRF ----

test('ImportUrlEndpoint (revision): hosts ambiguos y esquemas peligrosos se rechazan en el endpoint', function (string $url) {
    importPost($url)->assertStatus(422);

    expect(MediaAsset::withoutUserScope()->count())->toBe(0);
    Queue::assertNothingPushed();
    Http::assertNothingSent();
})->with([
    'http://%31%32%37.0.0.1/a.jpg', 'http://[fe80::1%25eth0]/a.jpg', 'http://127.0.0.1./a.jpg', 'http://0x7f000001/a.jpg',
    'http://[::ffff:7f00:1]/a.jpg', 'http://[64:ff9b::7f00:1]/a.jpg', 'http://[2002:7f00:1::]/a.jpg', 'javascript:alert(1)', 'data:text/html,x',
    'gopher://cdn.example.com/', 'http://cdn.example.com:6379/',
]);

test('ImportUrlEndpoint (revision): ni el error ni el asset publico dejan la URL con su token', function () {
    $response = importPost('https://cdn.example.com/a.jpg?token=SECRETO');

    expect($response->getContent())->not->toContain('SECRETO');
    $asset = MediaAsset::withoutUserScope()->latest('id')->first();
    expect(json_encode($asset->toPublicArray()))->not->toContain('SECRETO')
        ->and(json_encode($asset->toArray()))->not->toContain('SECRETO')
        ->and(json_encode($asset->getAttributes()))->not->toContain('SECRETO');
});

test('ImportUrlEndpoint (revision): el recuento y el alta van dentro de una transaccion (con el usuario bloqueado)', function () {
    $levels = [];
    $base = DB::transactionLevel(); // RefreshDatabase ya abre una
    DB::listen(function ($q) use (&$levels) {
        $sql = strtolower($q->sql);
        if (str_contains($sql, 'count(*)') && str_contains($sql, 'media_assets')) {
            $levels['count'] = DB::transactionLevel();
        }
        if (str_starts_with($sql, 'insert into "media_assets"') || str_starts_with($sql, 'insert into `media_assets`')) {
            $levels['insert'] = DB::transactionLevel();
        }
    });

    importPost('https://cdn.example.com/a.jpg')->assertStatus(202);

    // SQLite (la BD de los tests) ignora FOR UPDATE; en MySQL la fila de `users` queda bloqueada hasta el commit.
    expect($levels['count'] ?? 0)->toBeGreaterThan($base)->and($levels['insert'] ?? 0)->toBeGreaterThan($base);
});
