<?php

namespace App\Http\Controllers\Studio;

use App\Http\Controllers\Controller;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

class StudioThumbnailController extends Controller
{
    /** La firma temporal da acceso a la miniatura de un diseno; no se reenvia ninguna cookie. */
    public function __invoke(int $design, int $owner): StreamedResponse
    {
        $path = DB::table('studio_designs')->where('id', $design)->where('user_id', $owner)->value('thumbnail_path');
        abort_unless(is_string($path) && str_starts_with($path, 'studio-thumbnails/'.$owner.'/') && ! str_contains($path, '..') && Storage::disk('local')->exists($path), 404);

        return Storage::disk('local')->response($path, null, [
            'Content-Type' => 'image/jpeg', 'Cache-Control' => 'private, max-age=3600', 'X-Content-Type-Options' => 'nosniff', 'Content-Security-Policy' => "sandbox; default-src 'none'",
        ]);
    }
}
