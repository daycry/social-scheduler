<?php

namespace App\Services\Studio\Ai;

use GdImage;
use RuntimeException;

/** Preparacion de la imagen de entrada con GD: normaliza a PNG acotado, construye la mascara de edicion y el lienzo ampliado (outpaint). */
final class ImageCanvas
{
    /** @return array{0: string, 1: int, 2: int} PNG, ancho, alto */
    public static function normalize(string $bytes, int $maxSide): array
    {
        $source = @imagecreatefromstring($bytes);
        if (! $source instanceof GdImage) {
            throw new RuntimeException('Imagen no legible.');
        }
        try {
            [$w, $h] = [imagesx($source), imagesy($source)];
            $scale = min(1.0, $maxSide / max($w, $h));
            $tw = max(1, (int) round($w * $scale));
            $th = max(1, (int) round($h * $scale));
            $canvas = self::blank($tw, $th);
            imagecopyresampled($canvas, $source, 0, 0, 0, 0, $tw, $th, $w, $h);

            return [self::png($canvas), $tw, $th];
        } finally {
            unset($source);
        }
    }

    /**
     * Mascara con el formato de OpenAI: transparente (alfa 0) donde se debe editar, opaca donde se conserva.
     *
     * @param  array{x: float, y: float, width: float, height: float}  $rect  fracciones 0..1 del ancho y alto
     */
    public static function mask(int $w, int $h, array $rect): string
    {
        $mask = imagecreatetruecolor($w, $h);
        imagealphablending($mask, false);
        imagesavealpha($mask, true);
        imagefill($mask, 0, 0, imagecolorallocatealpha($mask, 0, 0, 0, 0));
        $x1 = (int) floor($rect['x'] * $w);
        $y1 = (int) floor($rect['y'] * $h);
        $x2 = min($w - 1, (int) ceil(($rect['x'] + $rect['width']) * $w) - 1);
        $y2 = min($h - 1, (int) ceil(($rect['y'] + $rect['height']) * $h) - 1);
        imagefilledrectangle($mask, $x1, $y1, $x2, $y2, imagecolorallocatealpha($mask, 0, 0, 0, 127));

        return self::png($mask);
    }

    /**
     * Amplia el lienzo hasta la proporcion `a:b` centrando la imagen; lo nuevo queda transparente (en la imagen y en la mascara).
     *
     * @return array{0: string, 1: string, 2: int, 3: int} imagen PNG, mascara PNG, ancho, alto
     */
    public static function outpaint(string $png, string $aspect, int $maxSide): array
    {
        $source = @imagecreatefromstring($png);
        if (! $source instanceof GdImage || preg_match('/^(\d{1,2}):(\d{1,2})$/D', $aspect, $m) !== 1 || (int) $m[1] < 1 || (int) $m[2] < 1) {
            throw new RuntimeException('Imagen o proporcion no validas.');
        }
        [$sw, $sh] = [imagesx($source), imagesy($source)];
        $ratio = (int) $m[1] / (int) $m[2];
        if (abs($sw / $sh - $ratio) < 0.02) {
            throw new RuntimeException('same-aspect');
        }
        [$cw, $ch] = $sw / $sh < $ratio ? [(int) ceil($sh * $ratio), $sh] : [$sw, (int) ceil($sw / $ratio)];
        $scale = min(1.0, $maxSide / max($cw, $ch));
        [$tw, $th] = [max(1, (int) round($cw * $scale)), max(1, (int) round($ch * $scale))];
        [$dw, $dh] = [max(1, (int) round($sw * $scale)), max(1, (int) round($sh * $scale))];
        $dx = intdiv($tw - $dw, 2);
        $dy = intdiv($th - $dh, 2);

        $canvas = self::blank($tw, $th);
        imagecopyresampled($canvas, $source, $dx, $dy, 0, 0, $dw, $dh, $sw, $sh);

        $mask = imagecreatetruecolor($tw, $th);
        imagealphablending($mask, false);
        imagesavealpha($mask, true);
        imagefill($mask, 0, 0, imagecolorallocatealpha($mask, 0, 0, 0, 127));
        imagefilledrectangle($mask, $dx, $dy, $dx + $dw - 1, $dy + $dh - 1, imagecolorallocatealpha($mask, 0, 0, 0, 0));

        return [self::png($canvas), self::png($mask), $tw, $th];
    }

    private static function blank(int $w, int $h): GdImage
    {
        $image = imagecreatetruecolor($w, $h);
        imagealphablending($image, false);
        imagesavealpha($image, true);
        imagefill($image, 0, 0, imagecolorallocatealpha($image, 0, 0, 0, 127));

        return $image;
    }

    private static function png(GdImage $image): string
    {
        ob_start();
        imagepng($image);

        return (string) ob_get_clean();
    }
}
