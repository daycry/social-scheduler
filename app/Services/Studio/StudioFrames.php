<?php

namespace App\Services\Studio;

use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/** Frame requests retain local document seconds; frame rounding belongs to audiovisual output. */
class StudioFrames
{
    /** Laravel's associative decoder makes JSON {} and [] indistinguishable. Check the wire type first. */
    public function assertJsonList(Request $request, string $path = 'frame_times'): void
    {
        if (! $request->isJson()) {
            return;
        }
        $wire = json_decode($request->getContent());
        if (json_last_error() !== JSON_ERROR_NONE) {
            $this->fail('La solicitud JSON no es válida.');
        }
        $missing = new \stdClass;
        $value = data_get($wire, $path, $missing);
        if ($value !== $missing && ! is_array($value)) {
            $this->fail('frame_times debe ser una lista JSON, no un objeto.');
        }
    }

    public function validate(array $document, array $pageIds, mixed $input = []): array
    {
        if (! is_array($input) || ! array_is_list($input) || count($input) > 100) {
            $this->fail('Indica una lista de hasta cien fotogramas.');
        }
        if (($document['schema_version'] ?? 1) !== 2 && $input !== []) {
            $this->fail('Los fotogramas temporales requieren un documento v2.');
        }
        $pages = array_column($document['pages'], null, 'id');
        $seen = [];
        foreach ($input as $item) {
            if (! is_array($item) || array_diff(array_keys($item), ['page_id', 'time']) !== []
                || ! is_string($item['page_id'] ?? null) || ! in_array($item['page_id'], $pageIds, true)
                || ! isset($pages[$item['page_id']]) || isset($seen[$item['page_id']])
                || (! is_int($item['time'] ?? null) && ! is_float($item['time'] ?? null))
                || ! is_finite((float) $item['time']) || $item['time'] < 0 || $item['time'] >= $pages[$item['page_id']]['duration']) {
                $this->fail('Indica una página seleccionada sin repetir y un segundo local dentro de su duración.');
            }
            $seen[$item['page_id']] = true;
        }

        return $input;
    }

    public function elements(array $elements, float $time, float $pageDuration): array
    {
        $result = [];
        foreach ($elements as $element) {
            $window = $element['timing'] ?? ['start' => 0, 'duration' => $pageDuration];
            if (($element['visible'] ?? true) === false || $time < $window['start'] || $time >= $window['start'] + $window['duration']) {
                continue;
            }
            if ($element['type'] === 'group') {
                $element['elements'] = $this->elements($element['elements'], $time, $pageDuration);
                if ($element['elements'] === []) {
                    continue;
                }
            }
            $result[] = $element;
        }

        return $result;
    }

    private function fail(string $message): never
    {
        throw ValidationException::withMessages(['frame_times' => [$message]]);
    }
}
