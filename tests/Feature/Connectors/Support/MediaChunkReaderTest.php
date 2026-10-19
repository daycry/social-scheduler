<?php

use App\Connectors\Data\PublishMedia;
use App\Connectors\Support\MediaChunkReader;
use Aws\CommandInterface;
use Aws\Result;
use Aws\S3\S3Client;
use GuzzleHttp\Promise\Create;
use GuzzleHttp\Psr7\Utils;
use Illuminate\Filesystem\AwsS3V3Adapter;
use Illuminate\Support\Facades\Storage;
use League\Flysystem\AwsS3V3\AwsS3V3Adapter as S3Adapter;
use League\Flysystem\Filesystem;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\StreamInterface;

function readerMedia(string $disk, string $path): PublishMedia
{
    return new PublishMedia(1, 'video', 'video/mp4', 'https://x.test/v.mp4', 1080, 1920, 10.0, 30.0, 1000, $disk, $path);
}

test('MediaChunkReader: en disco local entrega el trozo como flujo con su longitud, sin tocar lo demas (revision F4-5 #18)', function () {
    Storage::fake('local');
    $bytes = implode('', array_map(fn ($i) => chr(65 + $i % 26), range(0, 999)));
    Storage::disk('local')->put('media/v.mp4', $bytes);
    $reader = MediaChunkReader::for(readerMedia('local', 'media/v.mp4'));

    $stream = $reader->stream(100, 50);

    expect($stream)->toBeInstanceOf(StreamInterface::class)->and($stream->getSize())->toBe(50)->and((string) $stream)->toBe(substr($bytes, 100, 50));
    expect($reader->chunk(990, 500))->toBe(substr($bytes, 990))->and($reader->chunk(1000, 10))->toBe('')->and($reader->chunk(0, 3))->toBe('ABC');
});

test('MediaChunkReader: en S3/R2 cada trozo es un GetObject con Range; no se descarga ni se descarta lo anterior (revision F4-5 #9)', function () {
    $bytes = str_repeat('0123456789', 100); // 1000 bytes
    $ranges = [];
    $client = new S3Client([
        'region' => 'auto', 'version' => 'latest', 'endpoint' => 'http://r2.test', 'use_path_style_endpoint' => true,
        'credentials' => ['key' => 'k', 'secret' => 's'],
        'handler' => function (CommandInterface $command, RequestInterface $request) use ($bytes, &$ranges) {
            if ($command->getName() === 'GetObject') {
                $ranges[] = [$command['Key'], $command['Range']];
                [$from, $to] = array_map('intval', explode('-', substr($command['Range'], 6)));

                return Create::promiseFor(new Result(['Body' => Utils::streamFor(substr($bytes, $from, $to - $from + 1)), 'ContentLength' => $to - $from + 1]));
            }

            // exists()/size() -> HeadObject
            return Create::promiseFor(new Result(['ContentLength' => strlen($bytes), 'ContentType' => 'video/mp4', 'LastModified' => new DateTime]));
        },
    ]);
    $config = ['driver' => 's3', 'bucket' => 'bucket', 'root' => 'media-root', 'key' => 'k', 'secret' => 's'];
    $adapter = new S3Adapter($client, 'bucket', 'media-root');
    Storage::set('r2test', new AwsS3V3Adapter(new Filesystem($adapter), $adapter, $config, $client));

    $reader = MediaChunkReader::for(readerMedia('r2test', 'media/v.mp4'));
    $chunk = $reader->chunk(900, 50);

    expect($reader->size)->toBe(1000)->and($chunk)->toBe(substr($bytes, 900, 50));
    expect($ranges)->toBe([['media-root/media/v.mp4', 'bytes=900-949']]); // UNA peticion, solo esos bytes

    $reader->chunk(950, 5000); // el ultimo trozo se acota al final del fichero
    expect($ranges[1][1])->toBe('bytes=950-999');
});
