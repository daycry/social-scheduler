<?php

namespace App\Jobs\Studio;

use App\Models\Studio\DesignExport;
use App\Services\JobFailureNotifier;
use App\Services\Studio\StudioRenderer;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class RenderStudioDesign implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 110;

    public function __construct(public readonly int $exportId)
    {
        $this->onQueue('media');
    }

    public function handle(StudioRenderer $renderer): void
    {
        $renderer->render($this->exportId);
    }

    public function failed(?\Throwable $exception): void
    {
        $closed = DesignExport::withoutUserScope()->whereKey($this->exportId)->whereIn('status', ['pending', 'processing'])
            ->update(['status' => 'failed', 'error' => 'La exportación se interrumpió o superó el tiempo permitido. Puedes iniciar otra con menos páginas.']);

        if ($closed === 1 && $export = DesignExport::withoutUserScope()->find($this->exportId)) {
            app(JobFailureNotifier::class)->export($export);
        }
    }
}
