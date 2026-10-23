<?php

use App\Connectors\Support\MediaChunkReader;
use App\Enums\ContentType;
use App\Models\Brand;
use App\Models\MediaAsset;
use App\Models\Post;
use App\Models\User;
use App\Services\MediaProbe;
use App\Services\MediaStorage;
use App\Services\MediaUploadService;
use App\Services\MediaValidator;
use Aws\CommandInterface;
use Aws\Result;
use Aws\S3\S3Client;
use GuzzleHttp\Promise\FulfilledPromise;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Storage;

// Revision Fase 3 intento 1: #1 (SSRF ffprobe), #7 (rotacion), #9 (subidas), #15 (borrado).

beforeEach(function () {
    Storage::fake('local');
    config(['filesystems.media' => 'local']);
    $this->user = User::factory()->create();
});

function hasFfmpeg(): bool
{
    return str_contains((string) shell_exec('ffmpeg -version 2>&1'), 'ffmpeg version');
}

/** Servidor TCP local que registra si alguien conecta: el «recurso interno» que un manifiesto intentaria leer. */
function internalListener(): array
{
    $server = stream_socket_server('tcp://127.0.0.1:0', $errno, $error);
    stream_set_blocking($server, false);
    $port = (int) substr(strrchr((string) stream_socket_get_name($server, false), ':'), 1);

    return [$server, $port];
}

function listenerWasHit($server): bool
{
    $conn = @stream_socket_accept($server, 0);

    if ($conn) {
        fclose($conn);

        return true;
    }

    return false;
}

function pendingVideoAsset(User $user, string $content, int $declared = 50 * 1024 * 1024): MediaAsset
{
    $asset = new MediaAsset(['disk' => 'local', 'path' => 'media/'.$user->id.'/'.uniqid().'.mp4', 'original_name' => 'v.mp4', 'mime' => 'video/mp4',
        'kind' => 'video', 'declared_size' => $declared, 'status' => MediaAsset::STATUS_PENDING]);
    $asset->user_id = $user->id;
    $asset->save();
    Storage::disk('local')->put($asset->path, $content);

    return $asset;
}

function r2Storage(?S3Client $client = null): MediaStorage
{
    return new class($client) extends MediaStorage
    {
        public function __construct(private readonly ?S3Client $c = null) {}

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
            return 'b';
        }
    };
}

dataset('manifiestos', [
    'HLS' => fn (int $port) => "#EXTM3U\n#EXT-X-TARGETDURATION:10\n#EXTINF:10,\nhttp://127.0.0.1:$port/interno-secreto.ts\n#EXT-X-ENDLIST\n",
    'DASH' => fn (int $port) => '<?xml version="1.0"?><MPD xmlns="urn:mpeg:dash:schema:mpd:2011" type="static" mediaPresentationDuration="PT10S" minBufferTime="PT1S"><Period><AdaptationSet mimeType="video/mp4"><Representation id="1" bandwidth="1000" width="640" height="360" codecs="avc1.42c01e"><BaseURL>http://127.0.0.1:'.$port.'/interno-dash</BaseURL><SegmentBase indexRange="0-100"/></Representation></AdaptationSet></Period></MPD>',
    'concat' => fn (int $port) => "ffconcat version 1.0\nfile 'http://127.0.0.1:$port/interno-concat.mp4'\n",
]);

test('MediaProbe SSRF: un manifiesto subido como .mp4 no genera peticiones de red y queda invalido (disco local)', function (Closure $manifest) {
    [$server, $port] = internalListener();
    $asset = pendingVideoAsset($this->user, $manifest($port));

    $done = app(MediaUploadService::class)->finalize($asset);

    expect($done->status)->toBe(MediaAsset::STATUS_INVALID)->and(listenerWasHit($server))->toBeFalse();
    fclose($server);
})->with('manifiestos');

test('MediaProbe SSRF: en R2 el objeto se baja a un temporal, se sondea en local y no hay peticiones de red', function (Closure $manifest) {
    [$server, $port] = internalListener();
    app()->instance(MediaStorage::class, r2Storage());
    $asset = pendingVideoAsset($this->user, $manifest($port));
    $before = glob(sys_get_temp_dir().'/probe_*') ?: [];

    expect(fn () => app(MediaProbe::class)->inspect($asset))->toThrow(RuntimeException::class);
    expect(listenerWasHit($server))->toBeFalse()
        ->and(glob(sys_get_temp_dir().'/probe_*') ?: [])->toBe($before); // sin temporales huerfanos
    fclose($server);
})->with('manifiestos');

test('MediaProbe SSRF: un MP4 real sigue sondeandose tanto en local como via descarga temporal (R2)', function () {
    if (! hasFfmpeg()) {
        $this->markTestSkipped('ffmpeg no disponible');
    }

    $asset = pendingVideoAsset($this->user, '');
    $full = Storage::disk('local')->path($asset->path);
    exec('ffmpeg -loglevel error -y -f lavfi -i testsrc=duration=2:size=320x240:rate=25 -pix_fmt yuv420p '.escapeshellarg($full).' 2>&1', $out, $code);
    expect($code)->toBe(0);

    expect(app(MediaProbe::class)->inspect($asset))->toMatchArray(['width' => 320, 'height' => 240]);

    app()->instance(MediaStorage::class, r2Storage());
    expect(app(MediaProbe::class)->inspect($asset))->toMatchArray(['width' => 320, 'height' => 240, 'codec' => 'h264']);
});

test('MediaProbe SSRF: un formato que no es MP4/MOV se rechaza aunque ffprobe lo entienda', function () {
    expect(fn () => app(MediaProbe::class)->parse(json_encode([
        'streams' => [['codec_type' => 'video', 'width' => 640, 'height' => 360]],
        'format' => ['format_name' => 'hls,applehttp'],
    ])))->toThrow(RuntimeException::class, 'MP4/MOV');
});

test('MediaProbe rotacion: un video vertical de movil con displaymatrix intercambia ancho y alto (ffmpeg real)', function () {
    if (! hasFfmpeg()) {
        $this->markTestSkipped('ffmpeg no disponible');
    }

    $base = sys_get_temp_dir().'/rot_'.uniqid().'.mp4';
    $asset = pendingVideoAsset($this->user, '');
    $full = Storage::disk('local')->path($asset->path);
    // Pista 1920x1080 con metadato de rotacion de 90 grados: se VE como 1080x1920 (9:16).
    exec('ffmpeg -loglevel error -y -f lavfi -i testsrc=duration=4:size=1920x1080:rate=30 -pix_fmt yuv420p '.escapeshellarg($base).' 2>&1', $o1, $c1);
    exec('ffmpeg -loglevel error -y -display_rotation 90 -i '.escapeshellarg($base).' -c copy '.escapeshellarg($full).' 2>&1', $o2, $c2);
    @unlink($base);
    expect($c1)->toBe(0)->and($c2)->toBe(0);

    $done = app(MediaUploadService::class)->finalize($asset);

    expect($done->status)->toBe('listo')->and($done->width)->toBe(1080)->and($done->height)->toBe(1920);
    $validator = app(MediaValidator::class);
    expect(implode(' ', $validator->validate(ContentType::FacebookReel, [$done])))->not->toContain('proporcion') // 0.5625 dentro de 0.5-0.6
        ->and(implode(' ', $validator->validate(ContentType::YouTubeShort, [$done])))->not->toContain('proporcion');
});

test('MediaProbe rotacion: displaymatrix +-90/270 y la tag rotate intercambian; 0 y 180 no', function (array $video, bool $swapped) {
    $meta = app(MediaProbe::class)->parse(json_encode(['streams' => [['codec_type' => 'video', 'width' => 1920, 'height' => 1080] + $video], 'format' => ['format_name' => 'mov,mp4,m4a,3gp,3g2,mj2']]));

    expect([$meta['width'], $meta['height']])->toBe($swapped ? [1080, 1920] : [1920, 1080]);
})->with([
    'displaymatrix -90' => [['side_data_list' => [['side_data_type' => 'Display Matrix', 'rotation' => -90]]], true],
    'displaymatrix 90' => [['side_data_list' => [['side_data_type' => 'Display Matrix', 'rotation' => 90]]], true],
    'displaymatrix 270' => [['side_data_list' => [['side_data_type' => 'Display Matrix', 'rotation' => 270]]], true],
    'tag rotate 90' => [['tags' => ['rotate' => '90']], true],
    'tag rotate 270' => [['tags' => ['rotate' => '270']], true],
    'displaymatrix 180' => [['side_data_list' => [['rotation' => 180]]], false],
    'sin rotacion' => [[], false],
]);

test('MediaUpload: un objeto mas grande que lo declarado al iniciar la subida se rechaza y se borra', function () {
    $asset = pendingVideoAsset($this->user, str_repeat('x', 2000), declared: 100);

    $done = app(MediaUploadService::class)->finalize($asset);

    expect($done->status)->toBe(MediaAsset::STATUS_INVALID)->and($done->error)->toContain('declarado');
    Storage::disk('local')->assertMissing($asset->path);
});

test('MediaUpload: app:media-prune elimina pendientes/invalidos antiguos sin publicaciones y conserva el resto', function () {
    $old = pendingVideoAsset($this->user, 'x');
    $old->forceFill(['created_at' => now()->subHours(30)])->save();
    $fresh = pendingVideoAsset($this->user, 'x');
    $used = pendingVideoAsset($this->user, 'x');
    $used->forceFill(['created_at' => now()->subHours(30)])->save();
    $post = Post::factory()->create(['user_id' => $this->user->id, 'brand_id' => Brand::factory()->for($this->user)->create()->id]);
    $post->media()->attach($used->id, ['position' => 0]);
    $ready = pendingVideoAsset($this->user, 'x');
    $ready->forceFill(['status' => MediaAsset::STATUS_READY, 'created_at' => now()->subDays(5)])->save();

    $this->artisan('app:media-prune')->expectsOutputToContain('eliminadas: 1')->assertSuccessful();

    expect(MediaAsset::withoutUserScope()->pluck('id')->all())->toEqualCanonicalizing([$fresh->id, $used->id, $ready->id]);
    Storage::disk('local')->assertMissing($old->path);
    Storage::disk('local')->assertExists($fresh->path);
});

test('MediaUpload: el prune aborta el multipart abierto en R2', function () {
    $calls = new ArrayObject;
    $client = new S3Client([
        'region' => 'auto', 'version' => 'latest', 'endpoint' => 'https://acct.r2.cloudflarestorage.com', 'use_path_style_endpoint' => true,
        'credentials' => ['key' => 'AKIATEST', 'secret' => 'secret'],
        'handler' => function (CommandInterface $cmd) use ($calls) {
            $calls[] = $cmd->getName();

            return new FulfilledPromise(new Result([]));
        },
    ]);
    app()->instance(MediaStorage::class, r2Storage($client));
    $asset = pendingVideoAsset($this->user, 'x');
    $asset->forceFill(['upload_id' => 'UP1', 'created_at' => now()->subDays(2)])->save();

    expect(app(MediaUploadService::class)->pruneAbandoned(24))->toBe(1);

    expect($calls->getArrayCopy())->toContain('AbortMultipartUpload');
    expect(MediaAsset::withoutUserScope()->count())->toBe(0);
});

test('MediaUpload: borrar una media usada por una publicacion falla sin tocar el fichero; sin uso borra fila y fichero', function () {
    $asset = pendingVideoAsset($this->user, 'x');
    $post = Post::factory()->create(['user_id' => $this->user->id, 'brand_id' => Brand::factory()->for($this->user)->create()->id]);
    $post->media()->attach($asset->id, ['position' => 0]);

    expect(fn () => app(MediaUploadService::class)->delete($asset))->toThrow(DomainException::class);
    Storage::disk('local')->assertExists($asset->path);
    expect(MediaAsset::withoutUserScope()->find($asset->id))->not->toBeNull();

    $this->actingAs($this->user)->deleteJson(route('media.destroy', $asset))->assertStatus(409);

    $post->media()->detach($asset->id);
    $this->actingAs($this->user)->deleteJson(route('media.destroy', $asset))->assertOk();
    Storage::disk('local')->assertMissing($asset->path);
    expect(MediaAsset::withoutUserScope()->find($asset->id))->toBeNull();
});

test('MediaUpload: la fila se borra ANTES que el fichero (si la fila no se puede borrar el fichero queda)', function () {
    $asset = pendingVideoAsset($this->user, 'x');
    // Simula la FK `restrict` de una publicacion que adjunta la media en el ultimo instante.
    MediaAsset::deleting(fn () => throw new QueryException('sqlite', 'delete from media_assets', [], new Exception('FOREIGN KEY constraint failed')));

    try {
        expect(fn () => app(MediaUploadService::class)->delete($asset))->toThrow(DomainException::class);
        Storage::disk('local')->assertExists($asset->path);
    } finally {
        MediaAsset::flushEventListeners();
    }
});

// Revision Fase 3 intento 2: N3 (no bajar el video entero en `complete`), N4 (temporal vacio), limpieza de `probe_*`.

/** MediaProbe que registra los rangos pedidos al almacenamiento. */
function recordingProbe(array &$ranges): MediaProbe
{
    return new class(app(MediaStorage::class), $ranges) extends MediaProbe
    {
        public function __construct(MediaStorage $storage, private array &$log)
        {
            parent::__construct($storage);
        }

        protected function fetchRange(MediaChunkReader $reader, int $offset, int $length): string
        {
            $this->log[] = [$offset, $length];

            return parent::fetchRange($reader, $offset, $length);
        }
    };
}

test('MediaProbe N3: en R2 un video grande solo baja el principio y el final por rango, no entero', function () {
    if (! hasFfmpeg()) {
        $this->markTestSkipped('ffmpeg no disponible');
    }

    $asset = pendingVideoAsset($this->user, '');
    $full = Storage::disk('local')->path($asset->path);
    exec('ffmpeg -loglevel error -y -f lavfi -i testsrc=duration=20:size=640x480:rate=30 -pix_fmt yuv420p -b:v 2M '.escapeshellarg($full).' 2>&1', $out, $code);
    expect($code)->toBe(0);
    $size = filesize($full);
    expect($size)->toBeGreaterThan(400 * 1024); // el moov (al final, sin faststart) y el centro de mdat caben en extremos de 64 KiB

    app()->instance(MediaStorage::class, r2Storage());
    config(['media.probe_edge_bytes' => 64 * 1024]);
    $ranges = [];
    $before = glob(sys_get_temp_dir().'/probe_*') ?: [];

    $meta = recordingProbe($ranges)->inspect($asset);

    $bytes = array_sum(array_column($ranges, 1));
    expect($meta)->toMatchArray(['width' => 640, 'height' => 480, 'codec' => 'h264'])
        ->and($bytes)->toBe(2 * 64 * 1024)->and($bytes)->toBeLessThan($size)
        ->and($ranges[0][0])->toBe(0)
        ->and(end($ranges)[0] + end($ranges)[1])->toBe($size) // el ultimo rango termina en el ultimo byte
        ->and(glob(sys_get_temp_dir().'/probe_*') ?: [])->toBe($before);
});

test('MediaProbe N3: el moov al principio (faststart) tambien se sondea con solo los extremos', function () {
    if (! hasFfmpeg()) {
        $this->markTestSkipped('ffmpeg no disponible');
    }

    $asset = pendingVideoAsset($this->user, '');
    $full = Storage::disk('local')->path($asset->path);
    exec('ffmpeg -loglevel error -y -f lavfi -i testsrc=duration=20:size=640x480:rate=30 -pix_fmt yuv420p -b:v 2M -movflags +faststart '.escapeshellarg($full).' 2>&1', $out, $code);
    expect($code)->toBe(0);

    app()->instance(MediaStorage::class, r2Storage());
    config(['media.probe_edge_bytes' => 64 * 1024]);
    $ranges = [];

    expect(recordingProbe($ranges)->inspect($asset))->toMatchArray(['width' => 640, 'height' => 480])
        ->and(array_sum(array_column($ranges, 1)))->toBe(2 * 64 * 1024);
});

test('MediaProbe N4: si el objeto no se puede leer no queda un temporal vacio', function () {
    app()->instance(MediaStorage::class, r2Storage());
    $asset = pendingVideoAsset($this->user, 'x');
    Storage::disk('local')->delete($asset->path); // el objeto ya no existe
    $before = glob(sys_get_temp_dir().'/probe_*') ?: [];

    expect(fn () => app(MediaProbe::class)->inspect($asset))->toThrow(RuntimeException::class, 'No se pudo leer')
        ->and(glob(sys_get_temp_dir().'/probe_*') ?: [])->toBe($before);
});

test('MediaProbe: la limpieza programada borra los probe_* antiguos y respeta los recientes', function () {
    $old = sys_get_temp_dir().'/probe_old_'.uniqid(); // tempnam() en Windows recorta el prefijo a 3 letras
    $new = sys_get_temp_dir().'/probe_new_'.uniqid();
    file_put_contents($old, 'x');
    file_put_contents($new, 'x');
    touch($old, time() - 3 * 3600);

    expect(MediaProbe::pruneTemporaries(60))->toBeGreaterThanOrEqual(1)
        ->and(file_exists($old))->toBeFalse()->and(file_exists($new))->toBeTrue();
    @unlink($new);

    $this->artisan('app:media-prune')->expectsOutputToContain('Temporales de sondeo eliminados')->assertSuccessful();
});

// Revision Fase 3 intento 3: R2 (MP4 fragmentado sin sidx en R2).

function fragmentedVideo($user, int $seconds = 20): MediaAsset
{
    $asset = pendingVideoAsset($user, '');
    $full = Storage::disk('local')->path($asset->path);
    exec('ffmpeg -loglevel error -y -f lavfi -i testsrc=duration='.$seconds.':size=640x480:rate=30 -pix_fmt yuv420p -b:v 2M -g 30 -movflags frag_keyframe+empty_moov '.escapeshellarg($full).' 2>&1', $out, $code);
    expect($code)->toBe(0);

    return $asset;
}

test('MediaProbe R2: un MP4 fragmentado se detecta y se baja entero para leer la duracion real', function () {
    if (! hasFfmpeg()) {
        $this->markTestSkipped('ffmpeg no disponible');
    }

    $asset = fragmentedVideo($this->user);
    $size = filesize(Storage::disk('local')->path($asset->path));
    expect($size)->toBeGreaterThan(4 * 64 * 1024)
        ->and(MediaProbe::isFragmented((string) file_get_contents(Storage::disk('local')->path($asset->path), false, null, 0, 64 * 1024)))->toBeTrue();

    app()->instance(MediaStorage::class, r2Storage());
    config(['media.probe_edge_bytes' => 64 * 1024, 'media.probe_fragmented_max_bytes' => 64 * 1024 * 1024]);
    $ranges = [];

    $meta = recordingProbe($ranges)->inspect($asset);

    expect($meta['duration'])->toBeGreaterThan(19.5)->toBeLessThan(20.5)
        ->and(array_sum(array_column($ranges, 1)))->toBe($size); // se leyo el fichero completo, no solo los extremos
});

test('MediaProbe R2: un MP4 fragmentado mas grande que el tope se rechaza con un mensaje claro', function () {
    if (! hasFfmpeg()) {
        $this->markTestSkipped('ffmpeg no disponible');
    }

    $asset = fragmentedVideo($this->user);
    app()->instance(MediaStorage::class, r2Storage());
    config(['media.probe_edge_bytes' => 64 * 1024, 'media.probe_fragmented_max_bytes' => 128 * 1024]);
    $before = glob(sys_get_temp_dir().'/probe_*') ?: [];

    expect(fn () => app(MediaProbe::class)->inspect($asset))->toThrow(RuntimeException::class, 'fragmentado')
        ->and(glob(sys_get_temp_dir().'/probe_*') ?: [])->toBe($before);
});

test('MediaProbe R2: un MP4 normal (con o sin faststart) no se considera fragmentado', function () {
    $ftyp = pack('N', 16).'ftyp'.'isom'.pack('N', 0);
    $moov = pack('N', 24).'moov'.str_repeat('x', 16);

    expect(MediaProbe::isFragmented($ftyp.$moov.pack('N', 40).'mdat'.str_repeat('moof', 8)))->toBeFalse()
        ->and(MediaProbe::isFragmented($ftyp.pack('N', 8).'moov'))->toBeFalse()
        ->and(MediaProbe::isFragmented($ftyp.pack('N', 24).'moov'.str_repeat('x', 12).'mvex'))->toBeTrue()
        ->and(MediaProbe::isFragmented($ftyp.pack('N', 8).'moof'))->toBeTrue();
});
