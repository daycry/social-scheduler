<?php

namespace App\Services\Studio;

use Illuminate\Validation\ValidationException;

final class StudioDatasetInput
{
    public const JSON_FLAGS = JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR;

    public static function fail(string $field, string $message): never
    {
        throw ValidationException::withMessages([$field => [$message]]);
    }

    public static function keys(array $input, array $allowed): void
    {
        foreach (array_diff(array_keys($input), $allowed) as $key) {
            self::fail((string) $key, 'Unknown field.');
        }
    }

    public static function integer(array $input, string $key, int $min = 1, int $max = PHP_INT_MAX, ?int $default = null): int
    {
        $value = array_key_exists($key, $input) ? $input[$key] : $default;
        if ((! is_int($value) && (! is_string($value) || ! preg_match('/^(0|[1-9][0-9]*)$/D', $value)))
            || filter_var($value, FILTER_VALIDATE_INT) === false || $value < $min || $value > $max) {
            self::fail($key, 'Expected an integer between '.$min.' and '.$max.'.');
        }

        return (int) $value;
    }

    public static function text(array $input, string $key, int $max, ?string $default = null): string
    {
        $value = array_key_exists($key, $input) ? $input[$key] : $default;
        if (! is_string($value) || ! mb_check_encoding($value, 'UTF-8') || mb_strlen($value) > $max) {
            self::fail($key, 'Expected UTF-8 text of at most '.$max.' characters.');
        }

        return $value;
    }

    public static function boolean(array $input, string $key, bool $default = true): bool
    {
        if (! array_key_exists($key, $input)) {
            return $default;
        }

        return match ($input[$key]) {
            true, 'true', 1, '1' => true,
            false, 'false', 0, '0' => false,
            default => self::fail($key, 'Expected a boolean.'),
        };
    }

    public static function objectJson(string $json, string $field): array
    {
        try {
            $value = json_decode($json, false, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            self::fail($field, 'Invalid JSON.');
        }
        if (! $value instanceof \stdClass) {
            self::fail($field, 'Expected a JSON object.');
        }

        return get_object_vars($value);
    }

    public static function table(mixed $value): array
    {
        if ($value instanceof \stdClass) {
            $value = get_object_vars($value);
        }
        if (! is_array($value) || array_is_list($value)) {
            self::fail('table', 'Expected a typed table object.');
        }
        foreach (['columns', 'rows'] as $key) {
            if (! isset($value[$key]) || ! is_array($value[$key]) || ! array_is_list($value[$key])) {
                self::fail('table', $key.' must be a JSON array.');
            }
            foreach ($value[$key] as &$entry) {
                if ($entry instanceof \stdClass) {
                    $entry = get_object_vars($entry);
                }
            }
            unset($entry);
        }

        return $value;
    }
}
