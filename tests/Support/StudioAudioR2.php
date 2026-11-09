<?php

namespace Tests\Support;

use ArrayObject;
use Aws\CommandInterface;
use Aws\MockHandler;
use Aws\Result;
use Aws\S3\Exception\S3Exception;
use Aws\S3\S3Client;
use GuzzleHttp\Psr7\Utils;
use Illuminate\Filesystem\AwsS3V3Adapter;
use Illuminate\Support\Facades\Storage;
use League\Flysystem\AwsS3V3\AwsS3V3Adapter as Adapter;
use League\Flysystem\Filesystem;
use RuntimeException;

/** In-memory S3 protocol fixture. No network, real accounts, credentials or persisted bucket data. */
class StudioAudioR2
{
    public ArrayObject $objects;

    public ArrayObject $calls;

    public array $fail = [];

    public bool $multipartOpen = false;

    public function __construct()
    {
        $this->objects = new ArrayObject;
        $this->calls = new ArrayObject;
        $handler = function (CommandInterface $command): Result {
            $name = $command->getName();
            $args = $command->toArray();
            $this->calls[] = ['name' => $name, 'args' => $args];
            if (($this->fail[$name] ?? 0) > 0) {
                $this->fail[$name]--;
                throw new S3Exception('fake unavailable', $command, ['status_code' => 503, 'code' => 'ServiceUnavailable']);
            }
            $key = $args['Key'] ?? '';
            if (in_array($name, ['HeadObject', 'GetObject'], true) && ! isset($this->objects[$key])) {
                throw new S3Exception('fake missing object', $command, ['status_code' => 404]);
            }
            if ($name === 'HeadObject') {
                return new Result(['ContentLength' => strlen($this->objects[$key]), 'ETag' => 'fake-etag']);
            }
            if ($name === 'GetObject') {
                $bytes = $this->objects[$key];
                if (isset($args['Range']) && preg_match('/^bytes=(\d+)-(\d+)$/', $args['Range'], $matches)) {
                    $bytes = substr($bytes, (int) $matches[1], (int) $matches[2] - (int) $matches[1] + 1);
                }

                return new Result(['Body' => Utils::streamFor($bytes), 'ContentLength' => strlen($bytes)]);
            }
            if ($name === 'CopyObject') {
                $source = substr(rawurldecode(ltrim($args['CopySource'], '/')), strlen('audio-bucket/'));
                $this->objects[$key] = $this->objects[$source];

                return new Result(['CopyObjectResult' => ['ETag' => 'fake-copy-etag']]);
            }
            if ($name === 'DeleteObject') {
                unset($this->objects[$key]);

                return new Result;
            }
            if ($name === 'CreateMultipartUpload') {
                $this->multipartOpen = true;

                return new Result(['UploadId' => 'fake-upload']);
            }
            if ($name === 'CompleteMultipartUpload') {
                if (! $this->multipartOpen) {
                    throw new S3Exception('fake consumed upload', $command, ['status_code' => 404, 'code' => 'NoSuchUpload']);
                }
                $this->multipartOpen = false;

                return new Result(['ETag' => 'fake-complete-etag']);
            }
            if ($name === 'AbortMultipartUpload') {
                if (! $this->multipartOpen) {
                    throw new S3Exception('fake consumed upload', $command, ['status_code' => 404, 'code' => 'NoSuchUpload']);
                }
                $this->multipartOpen = false;

                return new Result(['ETag' => 'fake-complete-etag']);
            }
            throw new RuntimeException('Unexpected fake S3 command: '.$name);
        };
        $client = new S3Client(['version' => 'latest', 'region' => 'auto', 'endpoint' => 'https://audio-r2.invalid', 'use_path_style_endpoint' => true,
            'credentials' => ['key' => 'audio-test-key', 'secret' => 'audio-test-secret'], 'handler' => new MockHandler(array_fill(0, 100, $handler))]);
        $config = ['bucket' => 'audio-bucket', 'root' => ''];
        $adapter = new Adapter($client, 'audio-bucket');
        Storage::set('audio-test-r2', new AwsS3V3Adapter(new Filesystem($adapter), $adapter, $config, $client));
        config(['filesystems.media' => 'audio-test-r2', 'filesystems.disks.audio-test-r2' => $config]);
    }
}
