<?php

namespace App\Connectors\Bluesky;

/**
 * TID de atproto: 13 caracteres en base32-sortable que codifican 64 bits (bit alto 0 + 53 bits de microsegundos desde la epoca
 * + 10 bits de `clock id`). Sirve de `rkey` de un post: al generarla y guardarla ANTES de `createRecord`, un reintento tras un
 * timeout puede comprobar con `getRecord` si el registro ya existe. Estrictamente creciente dentro del proceso.
 */
final class Tid
{
    private const ALPHABET = '234567abcdefghijklmnopqrstuvwxyz';

    private static int $last = 0;

    private static ?int $clock = null;

    public static function generate(): string
    {
        $micros = (int) (microtime(true) * 1_000_000);
        // Monotona aunque dos llamadas caigan en el mismo microsegundo o el reloj retroceda.
        $micros = $micros > self::$last ? $micros : self::$last + 1;
        self::$last = $micros;
        self::$clock ??= random_int(0, 1023);

        return self::encode(($micros << 10) | self::$clock);
    }

    /** Base32-sortable de 64 bits (el primer caracter solo usa 4 bits: el bit alto es 0). */
    public static function encode(int $value): string
    {
        $out = '';
        for ($i = 0; $i < 13; $i++) {
            $out = self::ALPHABET[$value & 31].$out;
            $value >>= 5;
        }

        return $out;
    }

    public static function isValid(string $tid): bool
    {
        return (bool) preg_match('/^[234567abcdefghij][234567abcdefghijklmnopqrstuvwxyz]{12}$/', $tid);
    }
}
