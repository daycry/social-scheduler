<?php

namespace App\Jobs\Studio;

use App\Services\Studio\StudioThumbnails;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/** Miniatura de un diseno (T-35): primera pagina reducida, por la cola `studio` (baja prioridad). Unico por diseno: varias visitas al listado no la duplican. */
class RenderStudioThumbnail implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 110;

    /** Segundos que el candado de unicidad sigue vivo aunque el job no llegue a ejecutarse. */
    public int $uniqueFor = 150;

    public function __construct(public readonly int $designId)
    {
        // Cola `studio`: su propio worker (con Chromium), asi las miniaturas no compiten con las importaciones de `media`.
        $this->onQueue('studio');
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
