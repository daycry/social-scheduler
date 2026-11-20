<?php

namespace App\Jobs\Studio;

use App\Services\Studio\StudioThumbnails;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/** Miniatura única por diseño. Su cola está separada de IA y exportaciones estáticas. */
class RenderStudioThumbnail implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 110;

    /** Segundos que el candado de unicidad sigue vivo aunque el job no llegue a ejecutarse. */
    public int $uniqueFor = 150;

    public function __construct(public readonly int $designId)
    {
        $this->onQueue((string) config('studio.thumbnail_queue', 'studio'));
    }

    public function uniqueId(): string
    {
        return 'studio-thumbnail:'.$this->designId;
    }

    public function handle(StudioThumbnails $thumbnails): void
    {
        $thumbnails->generate($this->designId);
    }

    /** Un timeout o un kill del worker no pasa por el `catch` de `generate()`: aplaza esa revision igual. */
    public function failed(?\Throwable $exception): void
    {
        app(StudioThumbnails::class)->markFailed($this->designId);
    }
}
