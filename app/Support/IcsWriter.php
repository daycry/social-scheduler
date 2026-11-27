<?php

namespace App\Support;

/**
 * Escritor mínimo de iCalendar (RFC 5545) para el feed del calendario (T-90): escapa los valores de texto (§3.3.11), pliega las
 * líneas a 75 octetos sin partir caracteres UTF-8 (§3.1) y termina cada línea en CRLF.
 */
final class IcsWriter
{
    /** @var list<string> */
    private array $lines = [];

    public function begin(string $component): self
    {
        return $this->raw('BEGIN:'.$component);
    }

    public function end(string $component): self
    {
        return $this->raw('END:'.$component);
    }

    /** Propiedad de texto: el valor se escapa. */
    public function text(string $name, string $value): self
    {
        return $this->raw($name.':'.self::escape($value));
    }

    /** Propiedad con un valor ya formateado y seguro (fechas, versión...). `$params` (p. ej. `;VALUE=DATE`) nunca lleva datos del usuario. */
    public function property(string $name, string $value, string $params = ''): self
    {
        return $this->raw($name.$params.':'.$value);
    }

    public function render(): string
    {
        return implode("\r\n", $this->lines)."\r\n";
    }

    /** `\`, `;`, `,` y los saltos de línea se escapan; el resto de caracteres de control se descarta. */
    public static function escape(string $value): string
    {
        $value = str_replace(["\r\n", "\r"], "\n", $value);
        $value = preg_replace('/[\x00-\x09\x0B-\x1F\x7F]/u', '', $value) ?? '';

        return str_replace(['\\', ';', ',', "\n"], ['\\\\', '\\;', '\\,', '\\n'], $value);
    }

    /** Pliega una línea lógica en líneas de, como mucho, 75 octetos; las de continuación empiezan por un espacio. */
    public static function fold(string $line): string
    {
        if (strlen($line) <= 75) {
            return $line;
        }

        $out = [];
        $current = '';
        $limit = 75;
        foreach (preg_split('//u', $line, -1, PREG_SPLIT_NO_EMPTY) ?: [] as $char) {
            if (strlen($current) + strlen($char) > $limit) {
                $out[] = $current;
                $current = '';
                $limit = 74; // el espacio inicial de la continuación también cuenta
            }
            $current .= $char;
        }
        $out[] = $current;

        return implode("\r\n ", $out);
    }

    private function raw(string $line): self
    {
        $this->lines[] = self::fold($line);

        return $this;
    }
}
