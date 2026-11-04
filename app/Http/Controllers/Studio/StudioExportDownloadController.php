<?php

namespace App\Http\Controllers\Studio;

use App\Http\Controllers\Controller;
use App\Models\Studio\DesignExport;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

class StudioExportDownloadController extends Controller
{
    /** The temporary signature grants access to one export; no cookie or API token is forwarded. */
    public function __invoke(int $export, int $owner): StreamedResponse
    {
        $record = DesignExport::withoutUserScope()->where('user_id', $owner)->where('status', 'ready')->findOrFail($export);
        abort_unless($record->disk === 'local' && is_string($record->path)
            && str_starts_with($record->path, 'studio-exports/'.$owner.'/'.$record->id.'/')
            && ! str_contains($record->path, '..'), 404);
        $disk = Storage::disk('local');
        abort_unless($disk->exists($record->path), 404);

        return $disk->download($record->path, 'design-'.$record->design_id.'-r'.$record->revision.'.'.pathinfo($record->path, PATHINFO_EXTENSION), [
            'Cache-Control' => 'private, no-store', 'X-Content-Type-Options' => 'nosniff', 'Content-Security-Policy' => "sandbox; default-src 'none'",
        ]);
    }
}
