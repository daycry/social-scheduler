<?php

use App\Models\MediaAsset;
use App\Models\User;
use App\Services\MediaProbe;
use App\Services\MediaStorage;
use App\Services\MediaUploadService;
use Aws\CommandInterface;
use Aws\MockHandler;
use Aws\Result;
use Aws\S3\S3Client;
use Illuminate\Support\Facades\Storage;
use Psr\Http\Message\RequestInterface;

beforeEach(function () {
    Storage::fake('local');
    config(['filesystems.media' => 'local']);
    $this->user = User::factory()->create();
});

/** Bytes de un PNG real de $w x $h. */
function pngBytes(int $w = 40, int $h = 30): string
{
    $img = imagecreatetruecolor($w, $h);
    ob_start();
    imagepng($img);

    return (string) ob_get_clean();
}

/** MediaStorage que simula R2: cliente S3 con credenciales falsas y respuestas simuladas (sin red). */
function fakeR2(array $queue = []): ArrayObject
{
    $calls = new ArrayObject;
    $handlers = array_map(fn ($result) => function (CommandInterface $cmd, RequestInterface $req) use ($result, $calls) {
        $calls[] = ['name' => $cmd->getName(), 'args' => $cmd->toArray()];

        return $result;
    }, $queue);

    $client = new S3Client([
        'region' => 'auto', 'version' => 'latest', 'endpoint' => 'https://acct.r2.cloudflarestorage.com',
        'use_path_style_endpoint' => true,
        'credentials' => ['key' => 'AKIATEST', 'secret' => 'secret'],
        'handler' => new MockHandler($handlers),
    ]);

    $storage = new class($client) extends MediaStorage
    {
        public function __construct(private readonly S3Client $c) {}

        public function isS3(): bool
        {
            return true;
        }

        public function client(): S3Client
        {
            return $this->c;
        }

        public function bucket(): string
        {
            return 'media-bucket';
        }
    };
    app()->instance(MediaStorage::class, $storage);

    return $calls;
}

test('MediaUpload: en dev el asset se sube a la app y se extraen las dimensiones', function () {
    $response = $this->actingAs($this->user)->postJson(route('media.store'), ['name' => 'a.png', 'mime' => 'image/png', 'size' => 500]);

    $response->assertCreated()->assertJsonPath('upload.mode', 'local')->assertJsonPath('asset.status', 'pendiente');
    $asset = MediaAsset::withoutUserScope()->first();
    expect($asset->user_id)->toBe($this->user->id)->and($response->json('upload.url'))->toBe(route('media.upload', $asset, absolute: false));

    $this->actingAs($this->user)->call('PUT', route('media.upload', $asset), [], [], [], [], pngBytes(40, 30))
        ->assertOk()->assertJsonPath('asset.status', 'listo')->assertJsonPath('asset.width', 40)->assertJsonPath('asset.height', 30);

    expect($asset->fresh()->size)->toBeGreaterThan(0);
    Storage::disk('local')->assertExists($asset->path);
});

test('MediaUpload: rechaza tipos no admitidos y tamanos excesivos', function () {
    $this->actingAs($this->user)->postJson(route('media.store'), ['name' => 'x.exe', 'mime' => 'application/x-msdownload', 'size' => 10])
        ->assertStatus(422)->assertJsonPath('message', 'Tipo de fichero no admitido. Usa JPG, PNG, WebP, MP4 o MOV.');

    config(['media.max_bytes.image' => 1000]);
    $this->actingAs($this->user)->postJson(route('media.store'), ['name' => 'x.jpg', 'mime' => 'image/jpeg', 'size' => 2000])
        ->assertStatus(422);

    expect(MediaAsset::withoutUserScope()->count())->toBe(0);
});

test('MediaUpload: un fichero cuyo contenido no es lo declarado queda invalido y se borra', function () {
    $this->actingAs($this->user)->postJson(route('media.store'), ['name' => 'a.png', 'mime' => 'image/png', 'size' => 20]);
    $asset = MediaAsset::withoutUserScope()->first();

    $this->actingAs($this->user)->call('PUT', route('media.upload', $asset), [], [], [], [], 'esto no es una imagen')
        ->assertOk()->assertJsonPath('asset.status', 'invalido');

    Storage::disk('local')->assertMissing($asset->path);
});

test('MediaUpload: en R2 un fichero pequeno usa una URL prefirmada de un solo PUT (sin pasar por la app)', function () {
    fakeR2();

    $response = $this->actingAs($this->user)->postJson(route('media.store'), ['name' => 'f.jpg', 'mime' => 'image/jpeg', 'size' => 1_000_000])->assertCreated();

    expect($response->json('upload.mode'))->toBe('single');
    $url = $response->json('upload.url');
    expect($url)->toStartWith('https://acct.r2.cloudflarestorage.com/media-bucket/media/'.$this->user->id.'/')
        ->and($url)->toContain('X-Amz-Signature=')->and($url)->toContain('X-Amz-Expires=');
    // La subida no pasa por la app: la ruta local rechaza el asset.
    $asset = MediaAsset::withoutUserScope()->first();
    $this->actingAs($this->user)->call('PUT', route('media.upload', $asset), [], [], [], [], 'x')->assertStatus(409);
});

test('MediaUpload: un video grande se sube por multipart con una URL prefirmada por parte y se finaliza', function () {
    $calls = fakeR2([new Result(['UploadId' => 'up-1']), new Result([])]);
    app()->instance(MediaProbe::class, new class(app(MediaStorage::class)) extends MediaProbe
    {
        public function inspect(MediaAsset $asset): array
        {
            return ['width' => 1080, 'height' => 1920, 'duration' => 42.5, 'fps' => 30.0, 'codec' => 'h264', 'audio_codec' => 'aac', 'mime' => null];
        }
    });
    config(['media.part_size' => 16 * 1024 * 1024, 'media.multipart_threshold' => 32 * 1024 * 1024]);

    $size = 100 * 1024 * 1024; // 7 partes de 16 MiB
    $response = $this->actingAs($this->user)->postJson(route('media.store'), ['name' => 'v.mp4', 'mime' => 'video/mp4', 'size' => $size])->assertCreated();

    expect($response->json('upload.mode'))->toBe('multipart')->and($response->json('upload.parts'))->toHaveCount(7);
    expect($response->json('upload.parts.0.url'))->toContain('partNumber=1')->toContain('uploadId=up-1')->toContain('X-Amz-Signature=');
    expect($response->json('asset'))->not->toHaveKey('upload_id');

    $asset = MediaAsset::withoutUserScope()->first();
    // Simula que el navegador ya subio el objeto a R2 (aqui, al disco falso).
    Storage::disk('local')->put($asset->path, str_repeat('x', 1024));

    $parts = collect(range(1, 7))->map(fn ($n) => ['number' => $n, 'etag' => "\"etag$n\""])->shuffle()->all();
    $this->actingAs($this->user)->postJson(route('media.complete', $asset), ['parts' => $parts])
        ->assertOk()->assertJsonPath('asset.status', 'listo')->assertJsonPath('asset.duration', 42.5)->assertJsonPath('asset.fps', 30);

    $complete = collect($calls->getArrayCopy())->firstWhere('name', 'CompleteMultipartUpload');
    expect($complete['args']['UploadId'])->toBe('up-1')
        ->and(array_column($complete['args']['MultipartUpload']['Parts'], 'PartNumber'))->toBe([1, 2, 3, 4, 5, 6, 7]);
    expect($asset->fresh()->upload_id)->toBeNull();
});

test('MediaUpload: finalizar un multipart sin partes se rechaza', function () {
    fakeR2([new Result(['UploadId' => 'up-9'])]);
    $this->actingAs($this->user)->postJson(route('media.store'), ['name' => 'v.mp4', 'mime' => 'video/mp4', 'size' => 64 * 1024 * 1024])->assertCreated();
    $asset = MediaAsset::withoutUserScope()->first();

    $this->actingAs($this->user)->postJson(route('media.complete', $asset), [])->assertStatus(422);
});

test('MediaUpload: aislamiento, otro usuario no puede finalizar, subir, borrar ni listar el asset', function () {
    $other = User::factory()->create();
    $asset = MediaAsset::factory()->for($this->user)->create(['status' => MediaAsset::STATUS_PENDING]);
    $ready = MediaAsset::factory()->for($this->user)->create();

    foreach ([
        ['postJson', route('media.complete', $asset)],
        ['putJson', route('media.upload', $asset)],
        ['deleteJson', route('media.destroy', $asset)],
    ] as [$method, $url]) {
        $this->actingAs($other)->{$method}($url)->assertNotFound();
    }

    $this->actingAs($other)->getJson(route('media.index'))->assertOk()->assertJsonCount(0, 'media');
    $this->actingAs($this->user)->getJson(route('media.index'))->assertOk()->assertJsonCount(1, 'media')->assertJsonPath('media.0.id', $ready->id);
    expect(MediaAsset::withoutUserScope()->count())->toBe(2);
});

test('MediaUpload: el propietario puede borrar y se elimina el fichero', function () {
    $asset = MediaAsset::factory()->for($this->user)->create();
    Storage::disk('local')->put($asset->path, pngBytes());

    $this->actingAs($this->user)->deleteJson(route('media.destroy', $asset))->assertOk();

    expect(MediaAsset::withoutUserScope()->count())->toBe(0);
    Storage::disk('local')->assertMissing($asset->path);
});

test('MediaUpload: la URL para las redes es una URL firmada con caducidad', function () {
    $asset = MediaAsset::factory()->for($this->user)->create();
    Storage::disk('local')->put($asset->path, pngBytes());

    expect($asset->publicUrl())->toContain('expiration=');
});

test('MediaUpload: el probe interpreta la salida de ffprobe (fps fraccionarios, audio, sin pista de video)', function () {
    $probe = app(MediaProbe::class);
    $meta = $probe->parse(json_encode([
        'streams' => [
            ['codec_type' => 'video', 'codec_name' => 'h264', 'width' => 1920, 'height' => 1080, 'avg_frame_rate' => '30000/1001', 'duration' => '12.345'],
            ['codec_type' => 'audio', 'codec_name' => 'aac'],
        ],
        'format' => ['duration' => '12.5'],
    ]));

    expect($meta)->toMatchArray(['width' => 1920, 'height' => 1080, 'duration' => 12.345, 'fps' => 29.97, 'codec' => 'h264', 'audio_codec' => 'aac']);
    expect(fn () => $probe->parse('{"streams":[{"codec_type":"audio"}]}'))->toThrow(RuntimeException::class);
});

test('MediaUpload: ffprobe real extrae duracion, dimensiones y fps de un video generado', function () {
    $ffmpeg = trim((string) shell_exec('ffmpeg -version 2>&1')) !== '' && str_contains((string) shell_exec('ffmpeg -version 2>&1'), 'ffmpeg version');
    if (! $ffmpeg) {
        $this->markTestSkipped('ffmpeg no disponible');
    }

    $this->actingAs($this->user)->postJson(route('media.store'), ['name' => 'v.mp4', 'mime' => 'video/mp4', 'size' => 1000]);
    $asset = MediaAsset::withoutUserScope()->first();
    $full = Storage::disk('local')->path($asset->path);
    @mkdir(dirname($full), 0777, true);
    exec('ffmpeg -loglevel error -y -f lavfi -i testsrc=duration=2:size=320x240:rate=25 -pix_fmt yuv420p '.escapeshellarg($full).' 2>&1', $out, $code);
    expect($code)->toBe(0);

    $done = app(MediaUploadService::class)->finalize($asset);

    expect($done->status)->toBe('listo')->and($done->width)->toBe(320)->and($done->height)->toBe(240)
        ->and($done->fps)->toBe(25.0)->and($done->duration)->toBeGreaterThan(1.9)->and($done->codec)->toBe('h264');
});
