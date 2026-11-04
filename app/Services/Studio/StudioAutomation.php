<?php

namespace App\Services\Studio;

use App\Models\Brand;
use App\Models\Studio\Design;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/** Shared template and transform operations; no renderer-specific executable data. */
class StudioAutomation
{
    private const FIELD = '/\{\{([A-Za-z_][A-Za-z0-9_]{0,63})\}\}/u';

    public function __construct(private readonly StudioService $studio, private readonly StudioDocument $documents, private readonly StudioAssets $assets) {}

    public static function fields(array $document): array
    {
        $keys = [];
        self::visit($document, function (array $element) use (&$keys): array {
            if ($element['type'] === 'text') {
                preg_match_all(self::FIELD, $element['text'], $matches);
                foreach ($matches[1] as $key) {
                    $keys[$key] = true;
                }
            }

            return $element;
        });
        $keys = array_keys($keys);
        sort($keys, SORT_STRING);

        return $keys;
    }

    /** @return array{design?: array, designs?: array} */
    public function perform(User $user, Design $design, string $action, array $data): array
    {
        $source = $this->studio->owned($user, $design);
        $data = $this->input($action, $data);

        return DB::transaction(function () use ($user, $source, $action, $data): array {
            $source = Design::withoutUserScope()->where('user_id', $user->id)->lockForUpdate()->findOrFail($source->id);
            abort_if($source->revision !== (int) $data['revision'], 409, 'El diseño cambió en otra sesión. Guarda o recarga la versión actual antes de continuar.');
            $brand = Brand::withoutUserScope()->where('user_id', $user->id)->findOrFail($source->brand_id);
            $document = $this->documents->validate($user, $brand, $source->document);
            $reference = StudioService::reference($source->kit_reference);
            if ($action === 'instantiate') {
                if (! $source->is_template) {
                    throw ValidationException::withMessages(['design' => ['Selecciona una plantilla guardada.']]);
                }
            }
            if (in_array($action, ['instantiate', 'variants'], true)) {
                $document = $this->substitute($document, $data['fields'] ?? []);
            }
            if ($action === 'apply-kit' || ($data['apply_brand_kit'] ?? false)) {
                $kit = array_intersect_key($this->studio->kit($user, $brand), array_flip(['palette', 'fonts', 'logos']));
                if (($kit['palette'] ?? []) === [] && ($kit['fonts'] ?? []) === [] && ($kit['logos'] ?? []) === []) {
                    throw ValidationException::withMessages(['apply_brand_kit' => ['Añade colores, fuentes o logos al kit de esta marca.']]);
                }
                $document = $this->applyKit($user, $brand, $document, $reference, $kit);
                $reference = $kit;
            }
            if ($action === 'resize' || ($action === 'instantiate' && isset($data['width']))) {
                $document = $this->resized($document, $data);
            }
            if (in_array($action, ['resize', 'apply-kit'], true)) {
                $updated = $this->studio->update($user, $source, ['revision' => $source->revision, 'name' => $source->name, 'document' => $document], $reference);

                return ['design' => $this->studio->present($user, $updated)];
            }
            $variants = $action === 'variants' ? $data['variants'] : [null];
            $prepared = [];
            $bytes = 0;
            $elements = 0;
            foreach ($variants as $variant) {
                $copy = $variant === null ? $document : $this->resized($document, $variant);
                $copy = $this->independent($copy);
                $copy = $this->documents->validate($user, $brand, $copy);
                $bytes += strlen(json_encode($copy, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_LINE_TERMINATORS));
                self::visit($copy, function (array $element) use (&$elements): array {
                    $elements++;

                    return $element;
                });
                if ($bytes > 8 * 1024 * 1024 || $elements > 20000) {
                    throw ValidationException::withMessages(['variants' => ['El lote supera 8 MiB o 20000 elementos. Genera menos variantes.']]);
                }
                $name = $variant['name'] ?? $data['name'] ?? mb_substr($source->name, 0, 220).match ($action) {
                    'template' => ' (plantilla)', 'instantiate' => ' (diseño)', default => ' '.$copy['width'].'×'.$copy['height'],
                };
                $prepared[] = ['name' => $name, 'document' => $copy, 'is_template' => $action === 'template', 'folder_id' => $source->folder_id];
            }
            $created = array_map(fn (array $copy) => $this->studio->present($user, $this->studio->create($user, $brand, $copy, $reference)), $prepared);

            return $action === 'variants' ? ['designs' => $created] : ['design' => $created[0]];
        });
    }

    private function input(string $action, array $data): array
    {
        $rules = ['revision' => ['required', 'integer', 'min:1']];
        $dimension = ['required', 'integer', 'min:40', 'max:8000'];
        $mode = ['sometimes', 'in:canvas,contain,cover'];
        $name = ['sometimes', 'string', 'min:1', 'max:255'];
        if (in_array($action, ['template', 'instantiate'], true)) {
            $rules['name'] = $name;
        }
        if (in_array($action, ['instantiate', 'variants'], true)) {
            $rules += ['fields' => ['sometimes', 'array', 'max:100'], 'fields.*' => ['present', 'string', 'max:10000'], 'apply_brand_kit' => ['sometimes', 'boolean']];
        }
        if ($action === 'instantiate') {
            $rules += ['width' => ['required_with:height', 'integer', 'min:40', 'max:8000'], 'height' => ['required_with:width', 'integer', 'min:40', 'max:8000'], 'mode' => $mode];
        } elseif ($action === 'resize') {
            $rules += ['width' => $dimension, 'height' => $dimension, 'mode' => $mode];
        } elseif ($action === 'variants') {
            $rules += ['variants' => ['required', 'array', 'list', 'min:1', 'max:12'], 'variants.*' => ['required', 'array:name,width,height,mode'],
                'variants.*.name' => $name, 'variants.*.width' => $dimension, 'variants.*.height' => $dimension, 'variants.*.mode' => $mode];
        } elseif (! in_array($action, ['template', 'apply-kit'], true)) {
            throw ValidationException::withMessages(['action' => ['Operación de diseño no compatible.']]);
        }
        $top = array_filter(array_keys($rules), fn (string $key) => ! str_contains($key, '.'));
        if (array_diff(array_keys($data), $top) !== []) {
            throw ValidationException::withMessages(['request' => ['La operación contiene propiedades no compatibles.']]);
        }
        $data = Validator::make($data, $rules)->validate();
        if (isset($data['name']) && trim($data['name']) === '') {
            throw ValidationException::withMessages(['name' => ['El nombre es obligatorio.']]);
        }
        foreach ($data['variants'] ?? [] as $variant) {
            if (isset($variant['name']) && trim($variant['name']) === '') {
                throw ValidationException::withMessages(['variants' => ['Los nombres no pueden estar vacíos.']]);
            }
        }

        return $data;
    }

    private function substitute(array $document, array $values): array
    {
        $required = self::fields($document);
        $provided = array_keys($values);
        if (array_diff($required, $provided) !== [] || array_diff($provided, $required) !== []) {
            throw ValidationException::withMessages(['fields' => ['Indica todos y solo los campos de la plantilla: '.implode(', ', $required).'.']]);
        }

        // Validate the expansion mathematically before allocating any expanded text. Inputs can be
        // small while repeated fields would produce hundreds of megabytes before document validation.
        $flags = JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_LINE_TERMINATORS;
        $bytes = strlen(json_encode($document, $flags));
        $sizes = [];
        foreach ($values as $key => $value) {
            $sizes[$key] = ['characters' => mb_strlen($value), 'bytes' => strlen(json_encode($value, $flags)) - 2];
        }
        self::visit($document, function (array $element) use ($sizes, &$bytes): array {
            if ($element['type'] === 'text') {
                preg_match_all(self::FIELD, $element['text'], $matches);
                $characters = mb_strlen($element['text']);
                foreach (array_count_values($matches[1]) as $key => $count) {
                    $tokenLength = strlen($key) + 4;
                    $characters += $count * ($sizes[$key]['characters'] - $tokenLength);
                    $bytes += $count * ($sizes[$key]['bytes'] - $tokenLength);
                }
                if ($characters > 10000) {
                    throw ValidationException::withMessages(['fields' => ['La sustitución supera 10000 caracteres en un elemento de texto.']]);
                }
            }

            return $element;
        });
        if ($bytes > 2 * 1024 * 1024) {
            throw ValidationException::withMessages(['fields' => ['La sustitución supera el límite de documento de 2 MiB.']]);
        }

        return self::visit($document, function (array $element) use ($values): array {
            if ($element['type'] === 'text') {
                $element['text'] = preg_replace_callback(self::FIELD, fn (array $match) => $values[$match[1]], $element['text']);
            }

            return $element;
        });
    }

    private function independent(array $document): array
    {
        foreach ($document['pages'] as &$page) {
            $page['id'] = 'page_'.Str::uuid();
        }
        unset($page);

        return self::visit($document, function (array $element): array {
            $element['id'] = $element['type'].'_'.Str::uuid();

            return $element;
        });
    }

    private function resized(array $document, array $data): array
    {
        $width = (int) $data['width'];
        $height = (int) $data['height'];
        $mode = $data['mode'] ?? 'contain';
        if ($mode !== 'canvas') {
            $ratios = [$width / $document['width'], $height / $document['height']];
            $scale = $mode === 'cover' ? max($ratios) : min($ratios);
            $dx = ($width - $document['width'] * $scale) / 2;
            $dy = ($height - $document['height'] * $scale) / 2;
            foreach ($document['pages'] as &$page) {
                foreach ($page['elements'] as &$element) {
                    $element['x'] = $element['x'] * $scale + $dx;
                    $element['y'] = $element['y'] * $scale + $dy;
                    $element['scaleX'] = ($element['scaleX'] ?? 1) * $scale;
                    $element['scaleY'] = ($element['scaleY'] ?? 1) * $scale;
                }
                unset($element);
            }
            unset($page);
        }
        $document['width'] = $width;
        $document['height'] = $height;

        return $document;
    }

    private function applyKit(User $user, Brand $brand, array $document, array $reference, array $kit): array
    {
        $colors = $reference['palette'] ?? [];
        $fonts = $reference['fonts'] ?? [];
        if ($colors === []) {
            $seenColors = [];
            foreach ($document['pages'] as $page) {
                $this->collectPaint($page['background'], $colors, $seenColors);
            }
            self::visit($document, function (array $element) use (&$colors, &$seenColors): array {
                foreach (['fill', 'stroke'] as $key) {
                    if (isset($element[$key])) {
                        $this->collectPaint($element[$key], $colors, $seenColors);
                    }
                }

                return $element;
            });
        }
        if ($fonts === []) {
            $seenFonts = [];
            self::visit($document, function (array $element) use (&$fonts, &$seenFonts): array {
                if ($element['type'] === 'text') {
                    $font = $element['fontFamily'] ?? 'Arial';
                    if (! isset($seenFonts[$font])) {
                        $fonts[] = $font;
                        $seenFonts[$font] = true;
                    }
                }

                return $element;
            });
        }
        $colorMap = $fontMap = $logoMap = [];
        foreach ($colors as $index => $color) {
            if (isset($kit['palette'][$index])) {
                $colorMap[$this->colorKey($color)] = $kit['palette'][$index];
            }
        }
        foreach ($fonts as $index => $font) {
            if (isset($kit['fonts'][$index])) {
                $fontMap[$font] = $kit['fonts'][$index];
            }
        }
        foreach ($reference['logos'] ?? [] as $index => $id) {
            if (isset($kit['logos'][$index]) && $id !== $kit['logos'][$index]) {
                $logoMap[$id] = $this->assets->owned($user, $brand, $kit['logos'][$index]);
            }
        }
        foreach ($document['pages'] as &$page) {
            $page['background'] = $this->mapPaint($page['background'], $colorMap);
        }
        unset($page);

        return self::visit($document, function (array $element) use ($colorMap, $fontMap, $logoMap): array {
            foreach (['fill', 'stroke'] as $key) {
                if (isset($element[$key])) {
                    $element[$key] = $this->mapPaint($element[$key], $colorMap);
                }
            }
            if (isset($element['shadow']['color'])) {
                $element['shadow']['color'] = $this->mapPaint($element['shadow']['color'], $colorMap);
            }
            if ($element['type'] === 'text' && isset($fontMap[$element['fontFamily'] ?? 'Arial'])) {
                $element['fontFamily'] = $fontMap[$element['fontFamily'] ?? 'Arial'];
            }
            if ($element['type'] === 'image' && isset($logoMap[$element['asset_id']])) {
                $asset = $logoMap[$element['asset_id']];
                if (! $asset->width || ! $asset->height) {
                    throw ValidationException::withMessages(['apply_brand_kit' => ['El logo no tiene dimensiones válidas.']]);
                }
                $element['asset_id'] = $asset->id;
                $element['scaleX'] = ($element['scaleX'] ?? 1) * $element['width'] / $asset->width;
                $element['scaleY'] = ($element['scaleY'] ?? 1) * $element['height'] / $asset->height;
                $element['width'] = $asset->width;
                $element['height'] = $asset->height;
                $element['cropX'] = $element['cropY'] = 0;
            }

            return $element;
        });
    }

    private function collectPaint(mixed $paint, array &$colors, array &$seen): void
    {
        foreach (is_array($paint) ? array_column($paint['stops'], 'color') : [$paint] as $color) {
            $key = $this->colorKey($color);
            if ($color !== '' && ! isset($seen[$key]) && ! str_ends_with($key, '00')) {
                $colors[] = $color;
                $seen[$key] = true;
            }
        }
    }

    private function colorKey(string $color): string
    {
        $color = strtolower($color);
        if (strlen($color) === 4 || strlen($color) === 5) {
            $color = '#'.implode('', array_map(fn (string $char) => $char.$char, str_split(substr($color, 1))));
        }

        return strlen($color) === 7 ? $color.'ff' : $color;
    }

    private function mapPaint(mixed $paint, array $map): mixed
    {
        if (is_string($paint)) {
            return $map[$this->colorKey($paint)] ?? $paint;
        }
        foreach ($paint['stops'] as &$stop) {
            $stop['color'] = $map[$this->colorKey($stop['color'])] ?? $stop['color'];
        }
        unset($stop);

        return $paint;
    }

    private static function visit(array $document, callable $callback): array
    {
        $walk = function (array $elements) use (&$walk, $callback): array {
            return array_map(function (array $element) use (&$walk, $callback): array {
                $element = $callback($element);
                if ($element['type'] === 'group') {
                    $element['elements'] = $walk($element['elements']);
                }

                return $element;
            }, $elements);
        };
        foreach ($document['pages'] as &$page) {
            $page['elements'] = $walk($page['elements']);
        }
        unset($page);

        return $document;
    }
}
