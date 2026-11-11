<?php

namespace Tests\Support;

use ArrayObject;
use Aws\CommandInterface;
use Aws\MockHandler;
use Aws\Result;
use Aws\S3\Exception\S3Exception;
use Aws\S3\S3Client;
use Closure;
use GuzzleHttp\Psr7\Response;
use GuzzleHttp\Psr7\Utils;
use Illuminate\Filesystem\AwsS3V3Adapter;
use Illuminate\Support\Facades\Storage;
use League\Flysystem\AwsS3V3\AwsS3V3Adapter as Adapter;
use League\Flysystem\Filesystem;
use RuntimeException;

/** Isolated in-memory protocol fixture. No network or real bucket/credentials. */
class StudioAvR2
{
    public ArrayObject $calls;

    public array $uploads = [];

    public array $objects = [];

    public ?Closure $after = null;

    public bool $ignoreRange = false;

    private S3Client $client;

    public function __construct(string $prefix = 'tenant')
    {
        $this->calls = new ArrayObject;
        $handler = function (CommandInterface $command): Result {
            $name = $command->getName();
            $args = $command->toArray();
            $this->calls[] = ['name' => $name, 'args' => $args];
            $key = $args['Key'] ?? '';
            $id = $args['UploadId'] ?? '';
            if ($name === 'HeadObject') {
                $object = $this->objects[$key] ?? null;
                if (! $object) {
                    throw new S3Exception('fixture missing', $command, ['response' => new Response(404)]);
                }
                $result = new Result(['ContentLength' => strlen($object['bytes']), 'Metadata' => $object['metadata'], 'ETag' => $object['etag']]);
            } elseif ($name === 'ListMultipartUploads') {
                $uploads = [];
                foreach ($this->uploads as $uploadId => $upload) {
                    if (str_starts_with($upload['key'], $args['Prefix'])) {
                        $uploads[] = ['Key' => $upload['key'], 'UploadId' => $uploadId];
                    }
                }
                usort($uploads, fn ($a, $b) => [$a['Key'], $a['UploadId']] <=> [$b['Key'], $b['UploadId']]);
                if (isset($args['KeyMarker'])) {
                    $uploads = array_values(array_filter($uploads, fn ($upload) => $upload['Key'] > $args['KeyMarker']
                        || ($upload['Key'] === $args['KeyMarker'] && isset($args['UploadIdMarker']) && $upload['UploadId'] > $args['UploadIdMarker'])));
                }
                $page = array_slice($uploads, 0, $args['MaxUploads'] ?? 1000);
                $truncated = count($uploads) > count($page);
                $last = $page === [] ? null : $page[count($page) - 1];
                $result = new Result(['Uploads' => $page, 'IsTruncated' => $truncated]
                    + ($truncated ? ['NextKeyMarker' => $last['Key'], 'NextUploadIdMarker' => $last['UploadId']] : []));
            } elseif ($name === 'CreateMultipartUpload') {
                $id = 'fixture-upload-'.(count($this->uploads) + 1);
                $this->uploads[$id] = ['key' => $key, 'metadata' => $args['Metadata'], 'parts' => []];
                $result = new Result(['UploadId' => $id]);
            } elseif ($name === 'UploadPart') {
                if (! isset($this->uploads[$id]) || $this->uploads[$id]['key'] !== $key) {
                    throw new S3Exception('fixture no upload', $command, ['response' => new Response(404), 'code' => 'NoSuchUpload']);
                }
                $bytes = (string) $args['Body'];
                if ($args['ContentMD5'] !== base64_encode(md5($bytes, true))) {
                    throw new RuntimeException('Wrong fixture Content-MD5');
                }
                $etag = '"'.md5($bytes).'"';
                $this->uploads[$id]['parts'][$args['PartNumber']] = ['bytes' => $bytes, 'etag' => $etag];
                $result = new Result(['ETag' => $etag]);
            } elseif ($name === 'CompleteMultipartUpload') {
                $upload = $this->uploads[$id] ?? null;
                if (! $upload) {
                    throw new S3Exception('fixture consumed upload', $command, ['response' => new Response(404), 'code' => 'NoSuchUpload']);
                }
                $bytes = '';
                foreach ($args['MultipartUpload']['Parts'] as $part) {
                    $stored = $upload['parts'][$part['PartNumber']];
                    if ($stored['etag'] !== $part['ETag']) {
                        throw new RuntimeException('Wrong fixture completion receipt');
                    }
                    $bytes .= $stored['bytes'];
                }
                $etag = '"'.md5($bytes).'-'.count($upload['parts']).'"';
                $this->objects[$key] = ['bytes' => $bytes, 'metadata' => $upload['metadata'], 'etag' => $etag];
                unset($this->uploads[$id]);
                $result = new Result(['ETag' => $etag]);
            } elseif ($name === 'GetObject') {
                $object = $this->objects[$key];
                if (($args['IfMatch'] ?? null) !== $object['etag']) {
                    throw new S3Exception('fixture object changed', $command, ['response' => new Response(412)]);
                }
                preg_match('/^bytes=(\d+)-(\d+)$/D', $args['Range'], $matches);
                $start = (int) $matches[1];
                $end = (int) $matches[2];
                $bytes = $this->ignoreRange ? $object['bytes'] : substr($object['bytes'], $start, $end - $start + 1);
                $result = new Result(['Body' => Utils::streamFor($bytes), 'ContentLength' => strlen($bytes),
                    'ContentRange' => $this->ignoreRange ? null : 'bytes '.$start.'-'.$end.'/'.strlen($object['bytes'])]);
            } elseif ($name === 'AbortMultipartUpload') {
                if (! isset($this->uploads[$id]) || $this->uploads[$id]['key'] !== $key) {
                    throw new S3Exception('fixture no upload', $command, ['response' => new Response(404), 'code' => 'NoSuchUpload']);
                }
                unset($this->uploads[$id]);
                $result = new Result;
            } elseif ($name === 'ListParts') {
                if (! isset($this->uploads[$id]) || $this->uploads[$id]['key'] !== $key) {
                    throw new S3Exception('fixture no upload', $command, ['response' => new Response(404), 'code' => 'NoSuchUpload']);
                }
                $parts = [];
                foreach ($this->uploads[$id]['parts'] as $number => $part) {
                    $parts[] = ['PartNumber' => $number, 'ETag' => $part['etag'], 'Size' => strlen($part['bytes'])];
                }
                $result = new Result(['Parts' => array_slice($parts, 0, $args['MaxParts'] ?? 1000)]);
            } elseif ($name === 'DeleteObject') {
                unset($this->objects[$key]);
                $result = new Result;
            } else {
                throw new RuntimeException('Unexpected fixture S3 command '.$name);
            }
            if ($this->after) {
                ($this->after)($name, $args);
            }

            return $result;
        };
        $client = new S3Client(['version' => 'latest', 'region' => 'auto', 'endpoint' => 'https://studio-transfer.invalid',
            'use_path_style_endpoint' => true, 'credentials' => ['key' => 'fixture-key', 'secret' => 'fixture-secret'],
            'handler' => new MockHandler(array_fill(0, 1000, $handler))]);
        $this->client = $client;
        $this->setPrefix($prefix);
    }

    public function setPrefix(string $prefix): void
    {
        $client = $this->client;
        $config = ['bucket' => 'fixture-bucket', 'root' => $prefix];
        $adapter = new Adapter($client, 'fixture-bucket', $prefix);
        Storage::set('studio-transfer-r2', new AwsS3V3Adapter(new Filesystem($adapter), $adapter, $config, $client));
        config(['filesystems.media' => 'studio-transfer-r2', 'filesystems.disks.studio-transfer-r2' => $config]);
    }
}
