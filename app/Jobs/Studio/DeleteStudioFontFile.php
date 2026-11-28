<?php

namespace App\Jobs\Studio;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

class DeleteStudioFontFile implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public array $backoff = [30, 120];

    public function __construct(public readonly string $disk, public readonly string $path)
    {
        $this->onQueue((string) config('media.import_queue', 'media'));
    }

    public function handle(): void
    {
        if (! Storage::disk($this->disk)->delete($this->path)) {
            throw new RuntimeException('No se pudo borrar el fichero de la tipografía.');
        }
    }
}
