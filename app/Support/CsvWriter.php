<?php

namespace App\Support;

/**
 * Escritor CSV compartido (informes y exportaciones, T-37): UTF-8 con BOM para Excel, separador y decimal segun
 * `reports.csv_locale` (es: `;` y coma; en: `,` y punto) y neutralizacion contra inyeccion de formulas (CWE-1236).
 *
 * Reglas de celda: enteros y decimales van sin comillas; `null` es un campo vacio sin comillas («no disponible» lo decide quien
 * llama, con una cadena); el resto es texto SIEMPRE entre comillas, con `"` duplicadas, sin saltos de linea y con un `'` delante
 * si empieza por `=`, `+`, `-`, `@`, TAB o CR (o si lo hace cualquier tramo tras `;`, `,`, TAB o salto de linea, por si el programa
 * abre el CSV con otro separador).
 *
 * Se escribe linea a linea (`line()`): para streaming, quien llama emite `BOM` y despues una `line()` por fila sin acumular el fichero.
 */
final class CsvWriter
{
    public const BOM = "\xEF\xBB\xBF";

    public function __construct(private readonly string $delimiter, private readonly string $decimal) {}

    /** Separador y decimal del locale configurado (`reports.csv_locale`). */
    public static function forConfiguredLocale(): self
    {
        return config('reports.csv_locale', 'es') === 'en' ? new self(',', '.') : new self(';', ',');
    }

    public function delimiter(): string
    {
        return $this->delimiter;
    }

    /** Una fila completa, terminada en salto de linea. */
    public function line(array $row): string
    {
        return implode($this->delimiter, array_map(fn ($v) => $this->field($v), $row))."\n";
    }

    /** Texto neutralizado (sin comillas ni escapes de CSV); los numeros no se tocan. */
    public static function cell(mixed $value): int|float|string
    {
        if (is_int($value) || is_float($value)) {
            return $value;
        }

        return (string) preg_replace('/(^|[;,\t\r\n])( *)(?=[=+\-@\t\r])/', "$1$2'", (string) $value);
    }

    public function field(mixed $value): string
    {
        if ($value === null) {
            return '';
        }

        if (is_int($value)) {
            return (string) $value;
        }

        if (is_float($value)) {
            $text = rtrim(rtrim(number_format($value, 4, $this->decimal, ''), '0'), $this->decimal);

            return $text === '' || $text === '-' ? '0' : $text;
        }

        $text = (string) preg_replace('/\R/u', ' ', (string) self::cell($value));

        return '"'.str_replace('"', '""', $text).'"';
    }
}
