<?php

namespace App\Services;

use Aws\S3\S3Client;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Filesystem\AwsS3V3Adapter;
use Illuminate\Support\Facades\Storage;

/**
 * Acceso al disco de media (`config('filesystems.media')`: r2 en produccion, local en dev/test).
 * Aislado en una clase para poder sustituir el cliente S3 en los tests.
 */
class MediaStorage
{
    public function diskName(): string
    {
        return (string) config('filesystems.media', 'local');
    }

    public function disk(): Filesystem
    {
        return Storage::disk($this->diskName());
    }

    /** ¿El disco es S3/R2 (subida prefirmada directa) o local (subida via la app, solo dev/test)? */
    public function isS3(): bool
    {
        return $this->disk() instanceof AwsS3V3Adapter;
    }

    public function client(): S3Client
    {
        /** @var AwsS3V3Adapter $disk */
        $disk = $this->disk();

        return $disk->getClient();
    }

    public function bucket(): string
    {
        return (string) config('filesystems.disks.'.$this->diskName().'.bucket');
    }
}
