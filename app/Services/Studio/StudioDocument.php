<?php

namespace App\Services\Studio;

use App\Models\Brand;
use App\Models\MediaAsset;
use App\Models\Studio\StudioAsset;
use App\Models\Studio\StudioFont;
use App\Models\User;
use Illuminate\Validation\ValidationException;

/** Validates our public scene format, never a renderer's executable class graph. */
class StudioDocument
{
    public const MAX_TABLE_CELLS = 2000;

    public const TYPES = ['text', 'rect', 'ellipse', 'triangle', 'line', 'path', 'image', 'video', 'group', 'chart', 'table', 'connector'];

    public static function blank(int $width = 1080, int $height = 1080): array
    {
        return ['schema_version' => 1, 'width' => $width, 'height' => $height, 'pages' => [
            ['id' => 'page_1', 'name' => 'Página 1', 'background' => '#ffffff', 'duration' => 5, 'elements' => []],
        ]];
    }

    /** @return array The exact validated document, so save/reopen does not invent or discard editor state. */
    public function validate(User $user, Brand $brand, array $document): array
    {
        abort_unless((int) $brand->user_id === (int) $user->id, 404);
        if (($document['schema_version'] ?? null) === 2) {
            return app(StudioTimeline::class)->validate($user, $brand, $document, $this);
        }
        $this->keys($document, ['schema_version', 'width', 'height', 'pages'], 'document');
        $encoded = json_encode($document, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_LINE_TERMINATORS);
        $this->ensure($encoded !== false && strlen($encoded) <= 2 * 1024 * 1024, 'document', 'El documento supera el límite de 2 MiB o contiene valores no finitos.');
        $this->ensure(($document['schema_version'] ?? null) === 1, 'document.schema_version', 'Versión de documento no compatible.');
        foreach (['width', 'height'] as $key) {
            $this->ensure(is_int($document[$key] ?? null) && $document[$key] >= 40 && $document[$key] <= 8000, 'document.'.$key, 'La dimensión debe ser un entero de 40 a 8000 píxeles.');
        }
        $pages = $document['pages'] ?? null;
        $this->ensure(is_array($pages) && array_is_list($pages) && count($pages) >= 1 && count($pages) <= 100, 'document.pages', 'Indica entre 1 y 100 páginas.');
        $seen = [];
        $needed = [];
        foreach ($pages as $i => $page) {
            $path = "document.pages.$i";
            $this->ensure(is_array($page), $path, 'La página debe ser un objeto.');
            $this->keys($page, ['id', 'name', 'background', 'duration', 'elements', 'notes'], $path);
            $this->identifier($page['id'] ?? null, $path.'.id', $seen);
            $this->optionalString($page, 'name', 255, $path);
            if (isset($page['name'])) {
                $document['pages'][$i]['name'] = self::cleanName($page['name']);
            }
            $this->optionalString($page, 'notes', 20000, $path);
            $this->ensure(array_key_exists('background', $page), $path.'.background', 'Indica el fondo de la página.');
            $this->paint($page['background'], $path.'.background');
            $this->optionalNumber($page, 'duration', 0.1, 3600, $path);
            $count = 0;
            $this->elements($page['elements'] ?? null, $path.'.elements', 1, $count, $seen, $needed);
            $this->ensure(self::tableCells($page['elements']) <= self::MAX_TABLE_CELLS, $path.'.elements', 'Las tablas de la página superan las '.self::MAX_TABLE_CELLS.' celdas. Reduce filas o columnas.');
            $document['pages'][$i]['elements'] = self::linkConnectors($page['elements']);
        }
        if ($needed !== []) {
            $links = StudioAsset::withoutUserScope()->where('user_id', $user->id)->where('brand_id', $brand->id)
                ->whereIn('media_asset_id', array_keys($needed))->with('media')->get()->keyBy('media_asset_id');
            foreach ($needed as $id => $uses) {
                $asset = $links->get($id)?->media;
                foreach ($uses as [$type, $path, $element]) {
                    $this->ensure($asset !== null && (int) $asset->user_id === (int) $user->id && $asset->isReady(), $path.'.asset_id', 'El recurso no está listo o no está registrado en esta marca.');
                    $expected = $type === 'video' ? MediaAsset::KIND_VIDEO : MediaAsset::KIND_IMAGE;
                    $this->ensure($asset->kind === $expected, $path.'.asset_id', 'El tipo de recurso no coincide con el elemento.');
                    foreach (['cropX' => 'width', 'cropY' => 'height'] as $offset => $dimension) {
                        if (($element[$offset] ?? 0) > 0) {
                            $this->ensure($asset->$dimension !== null && $element[$offset] < $asset->$dimension, $path.'.'.$offset, 'El origen del recorte queda fuera del recurso.');
                        }
                    }
                    if ($type === 'video' && isset($element['trimEnd'])) {
                        $this->ensure($element['trimEnd'] > ($element['trimStart'] ?? 0), $path.'.trimEnd', 'El final del vídeo debe ser posterior a su inicio.');
                        if ($asset->duration !== null) {
                            $this->ensure($element['trimEnd'] <= $asset->duration + 0.01, $path.'.trimEnd', 'El recorte supera la duración del vídeo.');
                        }
                    }
                }
            }
        }

        app(StudioCharts::class)->validate($user, $brand, $document);
        $fontIds = StudioFonts::idsFromDocument($document);
        if ($fontIds !== []) {
            $fonts = StudioFont::withoutUserScope()->where('user_id', $user->id)->where('brand_id', $brand->id)->where('status', 'ready')->whereIn('id', $fontIds)->get()->keyBy('id');
            $this->ensure($fonts->count() === count($fontIds), 'document.font_id', 'La fuente no está disponible en esta marca.');
        }

        return $document;
    }

    private function elements(mixed $elements, string $path, int $depth, int &$count, array &$seen, array &$needed): void
    {
        $this->ensure(is_array($elements) && array_is_list($elements), $path, 'Los elementos deben ser una lista.');
        $this->ensure($depth <= 8, $path, 'Los grupos no pueden superar ocho niveles.');
        foreach ($elements as $i => $element) {
            $p = $path.'.'.$i;
            $count++;
            $this->ensure($count <= 500, $path, 'La página supera los 500 elementos, incluidos grupos.');
            $this->ensure(is_array($element), $p, 'El elemento debe ser un objeto.');
            $type = $element['type'] ?? null;
            $this->ensure(is_string($type) && in_array($type, self::TYPES, true), $p.'.type', 'Tipo de elemento no compatible.');
            $common = ['id', 'type', 'name', 'x', 'y', 'width', 'height', 'scaleX', 'scaleY', 'rotation', 'skewX', 'skewY', 'opacity', 'locked', 'visible', 'fill', 'stroke', 'strokeWidth', 'flipX', 'flipY', 'shadow'];
            $extra = match ($type) {
                'text' => ['text', 'fontFamily', 'font_id', 'fontSize', 'fontWeight', 'fontStyle', 'textAlign', 'underline', 'lineHeight', 'charSpacing'],
                'rect' => ['radius'],
                'line' => ['x1', 'y1', 'x2', 'y2'],
                'path' => ['path'],
                'image' => ['asset_id', 'cropX', 'cropY', 'filters', 'mask'],
                'video' => ['asset_id', 'cropX', 'cropY', 'filters', 'trimStart', 'trimEnd', 'volume', 'muted'],
                'group' => ['elements'],
                'chart' => ['chart'],
                'table' => ['table'],
                'connector' => ['from', 'to', 'route', 'arrow', 'points'],
                default => [],
            };
            $this->keys($element, [...$common, ...$extra], $p);
            $this->identifier($element['id'] ?? null, $p.'.id', $seen);
            $this->optionalString($element, 'name', 255, $p);
            foreach (['x', 'y'] as $key) {
                $this->number($element[$key] ?? null, -32000, 32000, $p.'.'.$key);
            }
            foreach (['width', 'height'] as $key) {
                $this->number($element[$key] ?? null, $type === 'chart' ? 160 : 0, $type === 'chart' ? 8000 : 32000, $p.'.'.$key);
            }
            if ($type === 'chart') {
                StudioChartSpec::normalize($element['chart'] ?? null, $p.'.chart');
            }
            foreach (['scaleX', 'scaleY'] as $key) {
                $this->optionalNumber($element, $key, 0.01, 100, $p);
            }
            $this->optionalNumber($element, 'rotation', -360, 360, $p);
            foreach (['skewX', 'skewY'] as $key) {
                $this->optionalNumber($element, $key, -89, 89, $p);
            }
            $this->optionalNumber($element, 'opacity', 0, 1, $p);
            $this->optionalNumber($element, 'strokeWidth', 0, 1000, $p);
            foreach (['locked', 'visible', 'flipX', 'flipY'] as $key) {
                $this->optionalBool($element, $key, $p);
            }
            if (array_key_exists('fill', $element)) {
                $this->paint($element['fill'], $p.'.fill');
            }
            if (array_key_exists('stroke', $element)) {
                $this->ensure($element['stroke'] === '' || $this->isColor($element['stroke']), $p.'.stroke', 'El trazo debe ser un color hexadecimal.');
            }
            if (array_key_exists('shadow', $element)) {
                $shadow = $element['shadow'];
                $this->ensure(is_array($shadow), $p.'.shadow', 'La sombra debe ser un objeto.');
                $this->keys($shadow, ['color', 'blur', 'offsetX', 'offsetY'], $p.'.shadow');
                $this->ensure($this->isColor($shadow['color'] ?? null), $p.'.shadow.color', 'Color de sombra no válido.');
                $this->optionalNumber($shadow, 'blur', 0, 500, $p.'.shadow');
                $this->optionalNumber($shadow, 'offsetX', -32000, 32000, $p.'.shadow');
                $this->optionalNumber($shadow, 'offsetY', -32000, 32000, $p.'.shadow');
            }
            if ($type === 'text') {
                $this->ensure(is_string($element['text'] ?? null) && mb_strlen($element['text']) <= 10000, $p.'.text', 'El texto debe tener hasta 10000 caracteres.');
                if (array_key_exists('fontFamily', $element)) {
                    $this->ensure(self::validFont($element['fontFamily']), $p.'.fontFamily', 'Indica una familia tipográfica, sin CSS ni URLs.');
                }
                if (isset($element['font_id'])) {
                    $this->ensure(is_int($element['font_id']) && $element['font_id'] > 0, $p.'.font_id', 'La referencia tipográfica debe ser un entero positivo.');
                }
                $this->optionalNumber($element, 'fontSize', 1, 1000, $p);
                $this->optionalNumber($element, 'lineHeight', 0.5, 5, $p);
                $this->optionalNumber($element, 'charSpacing', -200, 2000, $p);
                $this->optionalEnum($element, 'fontWeight', ['normal', 'bold'], $p);
                $this->optionalEnum($element, 'fontStyle', ['normal', 'italic'], $p);
                $this->optionalEnum($element, 'textAlign', ['left', 'center', 'right', 'justify'], $p);
                $this->optionalBool($element, 'underline', $p);
            }
            if ($type === 'table') {
                $this->table($element['table'] ?? null, $p.'.table');
            }
            if ($type === 'connector') {
                $this->connector($element, $p);
            }
            if ($type === 'rect') {
                $this->optionalNumber($element, 'radius', 0, 16000, $p);
            }
            if ($type === 'line') {
                foreach (['x1', 'y1', 'x2', 'y2'] as $key) {
                    $this->optionalNumber($element, $key, -32000, 32000, $p);
                }
            }
            if ($type === 'path') {
                $commands = $element['path'] ?? null;
                $this->ensure(is_array($commands) && array_is_list($commands) && count($commands) >= 1 && count($commands) <= 10000, $p.'.path', 'Indica una lista de comandos vectoriales.');
                foreach ($commands as $j => $command) {
                    $this->ensure(is_array($command) && array_is_list($command) && isset($command[0]) && is_string($command[0]), $p.'.path.'.$j, 'Comando vectorial no válido.');
                    $arity = ['M' => 2, 'L' => 2, 'C' => 6, 'Q' => 4, 'Z' => 0][$command[0]] ?? null;
                    $this->ensure($arity !== null && count($command) === $arity + 1, $p.'.path.'.$j, 'Comando vectorial no compatible.');
                    foreach (array_slice($command, 1) as $number) {
                        $this->number($number, -32000, 32000, $p.'.path.'.$j);
                    }
                }
            }
            if (in_array($type, ['image', 'video'], true)) {
                $id = $element['asset_id'] ?? null;
                $this->ensure(is_int($id) && $id > 0, $p.'.asset_id', 'Indica un recurso registrado en esta marca.');
                $needed[$id][] = [$type, $p, $element];
                foreach (['cropX', 'cropY'] as $key) {
                    $this->optionalNumber($element, $key, 0, 32000, $p);
                }
                if (array_key_exists('filters', $element)) {
                    $filters = $element['filters'];
                    $this->ensure(is_array($filters), $p.'.filters', 'Los filtros deben ser un objeto.');
                    $this->keys($filters, ['brightness', 'contrast', 'saturation', 'blur', 'grayscale', 'sepia', 'invert'], $p.'.filters');
                    foreach (['brightness', 'contrast', 'saturation'] as $key) {
                        $this->optionalNumber($filters, $key, -1, 1, $p.'.filters');
                    }
                    $this->optionalNumber($filters, 'blur', 0, 1, $p.'.filters');
                    foreach (['grayscale', 'sepia', 'invert'] as $key) {
                        $this->optionalBool($filters, $key, $p.'.filters');
                    }
                }
            }
            if ($type === 'image' && isset($element['mask'])) {
                $mask = $element['mask'];
                $this->ensure(is_array($mask) && in_array($mask['type'] ?? null, ['ellipse', 'rounded_rect'], true), $p.'.mask', 'La máscara debe ser ellipse o rounded_rect.');
                $this->keys($mask, $mask['type'] === 'ellipse' ? ['type'] : ['type', 'radius'], $p.'.mask');
                if ($mask['type'] === 'rounded_rect') {
                    $this->optionalNumber($mask, 'radius', 0, 16000, $p.'.mask');
                }
            }
            if ($type === 'video') {
                $this->optionalNumber($element, 'trimStart', 0, 86400, $p);
                if (($element['trimEnd'] ?? null) !== null) {
                    $this->optionalNumber($element, 'trimEnd', 0.001, 86400, $p);
                }
                $this->optionalNumber($element, 'volume', 0, 1, $p);
                $this->optionalBool($element, 'muted', $p);
            }
            if ($type === 'group') {
                $this->ensure(! empty($element['elements']), $p.'.elements', 'El grupo debe contener elementos.');
                $this->elements($element['elements'] ?? null, $p.'.elements', $depth + 1, $count, $seen, $needed);
            }
        }
    }

    /** Table cells on a page, groups included. */
    public static function tableCells(array $elements): int
    {
        $cells = 0;
        foreach ($elements as $element) {
            if ($element['type'] === 'table') {
                $cells += count($element['table']['rows']) * count($element['table']['rows'][0]);
            } elseif ($element['type'] === 'group') {
                $cells += self::tableCells($element['elements']);
            }
        }

        return $cells;
    }

    /**
     * Keep every connector end pointing at another (non-connector) element of the same page, else null.
     * With `$renamed` (old id => new id) the ends are first translated, for copies with fresh ids.
     */
    public static function linkConnectors(array $elements, ?array $renamed = null): array
    {
        $valid = [];
        $collect = function (array $items) use (&$collect, &$valid): void {
            foreach ($items as $item) {
                if ($item['type'] !== 'connector') {
                    $valid[$item['id']] = true;
                }
                if ($item['type'] === 'group') {
                    $collect($item['elements']);
                }
            }
        };
        $collect($elements);
        $walk = function (array $items) use (&$walk, $valid, $renamed): array {
            foreach ($items as &$item) {
                if ($item['type'] === 'connector') {
                    foreach (['from', 'to'] as $key) {
                        if (! array_key_exists($key, $item)) {
                            continue;
                        }
                        $id = $item[$key];
                        if ($renamed !== null && $id !== null) {
                            $id = $renamed[$id] ?? null;
                        }
                        $item[$key] = $id !== null && $id !== $item['id'] && isset($valid[$id]) ? $id : null;
                    }
                } elseif ($item['type'] === 'group') {
                    $item['elements'] = $walk($item['elements']);
                }
            }

            return $items;
        };

        return $walk($elements);
    }

    private function table(mixed $table, string $path): void
    {
        $this->ensure(is_array($table), $path, 'La tabla debe ser un objeto.');
        $this->keys($table, ['rows', 'header', 'fontSize', 'textColor', 'headerFill', 'headerColor', 'cellFill', 'borderColor', 'align'], $path);
        $rows = $table['rows'] ?? null;
        $this->ensure(is_array($rows) && array_is_list($rows) && count($rows) >= 1 && count($rows) <= 30, $path.'.rows', 'La tabla debe tener entre 1 y 30 filas.');
        $columns = is_array($rows[0] ?? null) && array_is_list($rows[0]) ? count($rows[0]) : 0;
        $this->ensure($columns >= 1 && $columns <= 12, $path.'.rows', 'La tabla debe tener entre 1 y 12 columnas.');
        foreach ($rows as $i => $row) {
            $this->ensure(is_array($row) && array_is_list($row) && count($row) === $columns, $path.'.rows.'.$i, 'Todas las filas deben tener el mismo número de columnas.');
            foreach ($row as $j => $cell) {
                $this->ensure(is_string($cell) && mb_strlen($cell) <= 500, $path.'.rows.'.$i.'.'.$j, 'Cada celda admite hasta 500 caracteres.');
            }
        }
        $this->optionalBool($table, 'header', $path);
        $this->optionalNumber($table, 'fontSize', 8, 200, $path);
        $this->optionalEnum($table, 'align', ['left', 'center', 'right'], $path);
        foreach (['textColor', 'headerFill', 'headerColor', 'cellFill', 'borderColor'] as $key) {
            if (array_key_exists($key, $table)) {
                $this->ensure($this->isColor($table[$key]), $path.'.'.$key, 'El color de la tabla debe ser hexadecimal.');
            }
        }
    }

    private function connector(array $element, string $path): void
    {
        foreach (['from', 'to'] as $key) {
            if (array_key_exists($key, $element) && $element[$key] !== null) {
                $this->ensure(is_string($element[$key]) && preg_match('/^[a-zA-Z0-9_-]{1,64}$/D', $element[$key]) === 1, $path.'.'.$key, 'El extremo del conector debe ser un identificador de elemento.');
            }
        }
        $this->optionalEnum($element, 'route', ['straight', 'elbow'], $path);
        $this->optionalEnum($element, 'arrow', ['none', 'end', 'both'], $path);
        $points = $element['points'] ?? null;
        $this->ensure(is_array($points) && array_is_list($points) && count($points) >= 2 && count($points) <= 4, $path.'.points', 'El conector necesita entre 2 y 4 puntos.');
        foreach ($points as $i => $point) {
            $this->ensure(is_array($point) && array_is_list($point) && count($point) === 2, $path.'.points.'.$i, 'Cada punto debe ser [x, y].');
            foreach ($point as $number) {
                $this->number($number, -32000, 32000, $path.'.points.'.$i);
            }
        }
    }

    public static function validFont(mixed $value): bool
    {
        return is_string($value) && preg_match('/(?:^|,)\s*StudioFont_/i', $value) !== 1 && preg_match('/^[\p{L}\p{N}][\p{L}\p{N} .,_-]{0,99}$/uD', $value) === 1;
    }

    public static function color(mixed $value): bool
    {
        return is_string($value) && preg_match('/^#(?:[a-f0-9]{3}|[a-f0-9]{4}|[a-f0-9]{6}|[a-f0-9]{8})$/iD', $value) === 1;
    }

    private function isColor(mixed $value): bool
    {
        return self::color($value);
    }

    private function paint(mixed $paint, string $path): void
    {
        if (self::color($paint)) {
            return;
        }
        $this->ensure(is_array($paint), $path, 'El relleno debe ser hexadecimal o un gradiente lineal.');
        $this->keys($paint, ['type', 'angle', 'stops'], $path);
        $this->ensure(($paint['type'] ?? null) === 'linear', $path.'.type', 'Solo se admite gradiente lineal.');
        $this->optionalNumber($paint, 'angle', -360, 360, $path);
        $stops = $paint['stops'] ?? null;
        $this->ensure(is_array($stops) && array_is_list($stops) && count($stops) >= 2 && count($stops) <= 12, $path.'.stops', 'Indica entre 2 y 12 colores ordenados.');
        $last = -1;
        foreach ($stops as $i => $stop) {
            $this->ensure(is_array($stop), $path.'.stops.'.$i, 'Parada de gradiente no válida.');
            $this->keys($stop, ['offset', 'color'], $path.'.stops.'.$i);
            $this->number($stop['offset'] ?? null, 0, 1, $path.'.stops.'.$i.'.offset');
            $this->ensure($stop['offset'] >= $last && self::color($stop['color'] ?? null), $path.'.stops.'.$i, 'Las paradas deben estar ordenadas y contener un color hexadecimal.');
            $last = $stop['offset'];
        }
    }

    private function identifier(mixed $id, string $path, array &$seen): void
    {
        $this->ensure(is_string($id) && preg_match('/^[a-zA-Z0-9_-]{1,64}$/D', $id) === 1, $path, 'Identificador de página o elemento no válido.');
        $this->ensure(! isset($seen[$id]), $path, 'Los identificadores deben ser únicos en todo el documento.');
        $seen[$id] = true;
    }

    private function keys(array $data, array $allowed, string $path): void
    {
        $this->ensure(array_diff(array_keys($data), $allowed) === [], $path, 'El objeto contiene propiedades no compatibles.');
    }

    private function number(mixed $value, float $min, float $max, string $path): void
    {
        $this->ensure((is_int($value) || is_float($value)) && is_finite((float) $value) && $value >= $min && $value <= $max, $path, "Indica un número entre $min y $max.");
    }

    private function optionalNumber(array $data, string $key, float $min, float $max, string $path): void
    {
        if (array_key_exists($key, $data)) {
            $this->number($data[$key], $min, $max, $path.'.'.$key);
        }
    }

    /** Nombre de una sola linea sin caracteres de control: acaban en XML (PPTX), cabeceras y nombres de fichero. */
    public static function cleanName(string $name): string
    {
        return preg_replace('/[\x00-\x1F\x7F-\x9F]/u', '', mb_scrub($name)) ?? '';
    }

    private function optionalString(array $data, string $key, int $max, string $path): void
    {
        if (array_key_exists($key, $data)) {
            $this->ensure(is_string($data[$key]) && mb_strlen($data[$key]) <= $max, $path.'.'.$key, "El texto debe tener hasta $max caracteres.");
        }
    }

    private function optionalBool(array $data, string $key, string $path): void
    {
        if (array_key_exists($key, $data)) {
            $this->ensure(is_bool($data[$key]), $path.'.'.$key, 'El valor debe ser booleano.');
        }
    }

    private function optionalEnum(array $data, string $key, array $values, string $path): void
    {
        if (array_key_exists($key, $data)) {
            $this->ensure(in_array($data[$key], $values, true), $path.'.'.$key, 'El valor no es compatible.');
        }
    }

    private function ensure(bool $condition, string $path, string $message): void
    {
        if (! $condition) {
            throw ValidationException::withMessages([$path => [$message]]);
        }
    }
}
