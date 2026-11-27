<?php

namespace App\Services\Studio;

use App\Models\Brand;
use App\Models\User;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Marca de agua de una marca: su configuración dentro del kit y su aplicación sobre las imágenes ya renderizadas.
 *
 * El render sigue siendo el de Chromium (`StudioRenderer`); aquí solo se superpone el logotipo del kit sobre el fichero
 * PNG, JPEG o WebP que éste deja en disco, antes de guardarlo en la biblioteca.
 */
final class StudioWatermark
{
    public const POSITIONS = ['top-left', 'top-right', 'bottom-left', 'bottom-right', 'center'];

    /** Formatos de exportación que son una imagen de píxeles; el resto (SVG, PDF, PPTX, MP4, GIF) no admite la superposición. */
    public const FORMATS = ['png', 'jpeg', 'webp'];

    private const LOGO_MIMES = ['image/png', 'image/jpeg', 'image/webp'];

    private const SETTINGS = ['logo_id', 'position', 'opacity', 'size', 'margin'];

    public function __construct(private readonly StudioAssets $assets) {}

    /** Tope de píxeles del logotipo (decodificado ocupa 4 bytes por píxel en GD). */
    private static function logoMaxPixels(): int
    {
        return (int) config('studio.watermark.logo_max_pixels', 16_000_000);
    }

    /** @return array{enabled: bool, logo_id: ?int, position: string, opacity: int, size: int, margin: int} */
    public static function defaults(): array
    {
        return ['enabled' => false, 'logo_id' => null, 'position' => 'bottom-right', 'opacity' => 70, 'size' => 20, 'margin' => 3];
    }

    /** La configuración guardada con los valores por defecto donde falte algo. */
    public static function resolve(?array $stored): array
    {
        return array_replace(self::defaults(), array_intersect_key($stored ?? [], self::defaults()));
    }

    /**
     * Valida el cambio de la marca de agua contra el kit resultante y devuelve la configuración completa a guardar.
     *
     * @param  array<string, mixed>  $input  campos que llegan en la petición
     * @param  array<string, mixed>  $current  configuración vigente
     * @param  list<int>  $logos  logotipos del kit resultante
     */
    public function normalize(User $user, Brand $brand, array $input, array $current, array $logos): array
    {
        $merged = array_replace($current, $input);
        $rules = ['enabled' => ['required', 'boolean'], 'logo_id' => ['nullable', 'integer', 'min:1'], 'position' => ['required', Rule::in(self::POSITIONS)],
            'opacity' => ['required', 'integer', 'min:5', 'max:100'], 'size' => ['required', 'integer', 'min:5', 'max:50'], 'margin' => ['required', 'integer', 'min:0', 'max:20']];
        $watermark = Validator::make(['watermark' => $merged], ['watermark' => ['required', 'array:enabled,'.implode(',', self::SETTINGS)]] + array_combine(
            array_map(fn (string $key) => 'watermark.'.$key, array_keys($rules)), array_values($rules)))->validate()['watermark'];
        $watermark = ['enabled' => (bool) $watermark['enabled'], 'logo_id' => isset($watermark['logo_id']) ? (int) $watermark['logo_id'] : null,
            'position' => $watermark['position'], 'opacity' => (int) $watermark['opacity'], 'size' => (int) $watermark['size'], 'margin' => (int) $watermark['margin']];

        if ($watermark['logo_id'] !== null && ! in_array($watermark['logo_id'], $logos, true)) {
            // Un logotipo retirado del kit deja la marca de agua sin logo; solo molesta si estaba activa o si lo pidió esta petición.
            if (array_key_exists('logo_id', $input) || $watermark['enabled']) {
                throw ValidationException::withMessages(['watermark.logo_id' => ['El logotipo de la marca de agua debe ser uno de los logotipos del kit de esta marca.']]);
            }
            $watermark['logo_id'] = null;
        }
        if ($watermark['logo_id'] === null && $watermark['enabled']) {
            throw ValidationException::withMessages(['watermark.logo_id' => ['Elige un logotipo del kit para activar la marca de agua.']]);
        }
        // Con la marca desactivada y sin tocar el logotipo, un logotipo ya borrado no debe bloquear otros cambios del kit.
        if ($watermark['logo_id'] !== null && ($watermark['enabled'] || array_key_exists('logo_id', $input))) {
            $this->logo($user, $brand, $watermark['logo_id'], 'watermark.logo_id');
        }

        return $watermark;
    }

    /**
     * Ajustes que se fijan en una exportación (sin `enabled`), o null si no lleva marca de agua.
     *
     * Con `$requested` nulo manda la marca: se aplica si está activa y el formato es una imagen. Con `true` se exige que haya logotipo
     * y formato de imagen; con `false` nunca se aplica.
     *
     * @param  array<string, mixed>  $kitWatermark  `StudioService::kit()['watermark']`
     * @return array{logo_id: int, position: string, opacity: int, size: int, margin: int}|null
     */
    public function snapshot(array $kitWatermark, string $format, ?bool $requested): ?array
    {
        if ($requested === false || ($requested === null && ! $kitWatermark['enabled'])) {
            return null;
        }
        $imageFormat = in_array($format, self::FORMATS, true);
        if ($requested === null && ! $imageFormat) {
            return null;
        }
        if (! $imageFormat) {
            throw ValidationException::withMessages(['watermark' => ['La marca de agua solo se aplica a las exportaciones PNG, JPEG y WebP.']]);
        }
        if ($kitWatermark['logo_id'] === null) {
            throw ValidationException::withMessages(['watermark' => ['Configura un logotipo para la marca de agua en el kit de la marca.']]);
        }

        return array_intersect_key($kitWatermark, array_flip(self::SETTINGS));
    }

    /** Superpone el logotipo sobre `$source` (ruta absoluta) y escribe el resultado en `$target` en `$format`, codificándolo una sola vez. */
    public function apply(User $user, Brand $brand, string $source, string $target, string $format, array $settings, float $quality): void
    {
        try {
            $content = $this->logo($user, $brand, (int) $settings['logo_id'], 'watermark');
        } catch (ValidationException) {
            throw new StudioRenderFailure('El logotipo de la marca de agua ya no está disponible. Revisa el kit de la marca.');
        }
        // Se mira la cabecera antes de decodificar: un PNG diminuto puede declarar miles de millones de píxeles.
        $logoSize = @getimagesizefromstring($content);
        if (! $logoSize || self::logoMaxPixels() < $logoSize[0] * $logoSize[1]) {
            throw new StudioRenderFailure('El logotipo de la marca de agua no es una imagen válida o es demasiado grande. Revisa el kit de la marca.');
        }
        $logo = @imagecreatefromstring($content);
        $size = @getimagesize($source);
        if (! $logo || ! $size || (int) config('studio.watermark.max_pixels', 25_000_000) < $size[0] * $size[1]) {
            throw new StudioRenderFailure('La imagen es demasiado grande o no válida para aplicar la marca de agua.');
        }
        $image = imagecreatefromstring((string) file_get_contents($source));
        if (! $image) {
            throw new StudioRenderFailure('No se pudo leer la imagen renderizada para aplicar la marca de agua.');
        }
        imagepalettetotruecolor($image);
        $width = imagesx($image);
        $height = imagesy($image);
        $margin = (int) round(min($width, $height) * $settings['margin'] / 100);
        // El logotipo ocupa `size` % del ancho sin deformarse ni salirse del lienzo (descontado el margen).
        $scale = min($width * $settings['size'] / 100 / imagesx($logo), max(1, $height - 2 * $margin) / imagesy($logo), max(1, $width - 2 * $margin) / imagesx($logo));
        $logoWidth = max(1, (int) round(imagesx($logo) * $scale));
        $logoHeight = max(1, (int) round(imagesy($logo) * $scale));
        imagealphablending($logo, false);
        imagesavealpha($logo, true);
        $scaled = imagescale($logo, $logoWidth, $logoHeight, IMG_BICUBIC);
        if (! $scaled) {
            throw new StudioRenderFailure('No se pudo ajustar el logotipo de la marca de agua.');
        }
        imagealphablending($scaled, false);
        imagesavealpha($scaled, true);
        // La opacidad multiplica la cobertura de cada píxel (alfa de GD: 0 opaco, 127 transparente), así que los semitransparentes del logotipo se conservan.
        for ($row = 0; $row < $logoHeight; $row++) {
            for ($column = 0; $column < $logoWidth; $column++) {
                $color = imagecolorat($scaled, $column, $row);
                $alpha = ($color >> 24) & 127;
                if ($alpha < 127) {
                    imagesetpixel($scaled, $column, $row, ((127 - (int) round((127 - $alpha) * $settings['opacity'] / 100)) << 24) | ($color & 0xFFFFFF));
                }
            }
        }
        [$x, $y] = match ($settings['position']) {
            'top-left' => [$margin, $margin],
            'top-right' => [$width - $logoWidth - $margin, $margin],
            'bottom-left' => [$margin, $height - $logoHeight - $margin],
            'center' => [intdiv($width - $logoWidth, 2), intdiv($height - $logoHeight, 2)],
            default => [$width - $logoWidth - $margin, $height - $logoHeight - $margin],
        };
        imagealphablending($image, true);
        imagecopy($image, $scaled, max(0, $x), max(0, $y), 0, 0, $logoWidth, $logoHeight);

        if ($format === 'jpeg') {
            // JPEG no tiene alfa: se aplana sobre blanco, igual que el render sin marca de agua (`exports.js`).
            $flat = imagecreatetruecolor($width, $height);
            imagefill($flat, 0, 0, imagecolorallocate($flat, 255, 255, 255));
            imagecopy($flat, $image, 0, 0, 0, 0, $width, $height);
            $image = $flat;
        }
        $temporary = $target.'.watermark';
        imagesavealpha($image, true);
        $written = match ($format) {
            'png' => imagepng($image, $temporary),
            'jpeg' => imagejpeg($image, $temporary, (int) round($quality * 100)),
            'webp' => imagewebp($image, $temporary, (int) round($quality * 100)),
            default => false,
        };
        if (! $written || ! rename($temporary, $target)) {
            @unlink($temporary);

            throw new StudioRenderFailure('No se pudo guardar la imagen con la marca de agua.');
        }
    }

    /**
     * Contenido del logotipo si es una imagen propia de la marca que GD sabe leer.
     *
     * @throws ValidationException
     */
    private function logo(User $user, Brand $brand, int $mediaId, string $field): string
    {
        try {
            $media = $this->assets->owned($user, $brand, $mediaId);
        } catch (ModelNotFoundException) {
            throw ValidationException::withMessages([$field => ['El logotipo debe estar registrado en esta marca.']]);
        }
        if ($media->kind !== 'image' || ! in_array($media->mime, self::LOGO_MIMES, true) || (int) $media->size > 8 * 1024 * 1024
            || (int) $media->width * (int) $media->height > self::logoMaxPixels()) {
            throw ValidationException::withMessages([$field => ['El logotipo de la marca de agua debe ser un PNG, JPEG o WebP de hasta 8 MB y '.(int) (self::logoMaxPixels() / 1_000_000).' megapíxeles.']]);
        }
        $content = Storage::disk($media->disk)->get($media->path);
        if (! is_string($content) || $content === '') {
            throw ValidationException::withMessages([$field => ['No se pudo leer el logotipo de la marca de agua.']]);
        }

        return $content;
    }
}
