<?php

use App\Models\Brand;
use App\Models\MediaAsset;
use App\Models\User;
use App\Services\Studio\StudioAssets;
use Illuminate\Support\Facades\Storage;

if (! function_exists('solidPng')) {
    /** PNG sólido de un color (r, g, b) para el logotipo y los renders falsos. */
    function solidPng(int $width, int $height, array $rgb): string
    {
        $image = imagecreatetruecolor($width, $height);
        imagefill($image, 0, 0, imagecolorallocate($image, ...$rgb));
        ob_start();
        imagepng($image);

        return (string) ob_get_clean();
    }

    /** Registra un logotipo rojo 10x10 en la biblioteca de la marca y lo devuelve. */
    function redLogo(User $user, Brand $brand): MediaAsset
    {
        $logo = MediaAsset::factory()->for($user)->create(['mime' => 'image/png', 'path' => 'media/'.$user->id.'/logo.png', 'original_name' => 'logo.png', 'width' => 10, 'height' => 10]);
        Storage::disk('local')->put($logo->path, solidPng(10, 10, [255, 0, 0]));
        app(StudioAssets::class)->attach($user, $brand, $logo->id);

        return $logo;
    }
}
