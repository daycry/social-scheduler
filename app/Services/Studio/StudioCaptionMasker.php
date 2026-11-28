<?php

namespace App\Services\Studio;

/**
 * Enmascara los datos sensibles de un subtitulo antes de enviarlo a la IA (correos, enlaces y telefonos) con marcadores numerados `⟦1⟧` y los
 * restaura en la traduccion. El mapa marcador -> original vive solo en memoria durante la peticion: a la IA llega el marcador, nunca el dato, y
 * al usuario le vuelve su dato original (no un «[telefono]»). Si la traduccion pierde un marcador o inventa otro, se rechaza.
 */
final class StudioCaptionMasker
{
    private const EMAIL = '/[A-Za-z0-9._%+\-]+@[A-Za-z0-9.\-]+\.[A-Za-z]{2,}/u';

    private const LINK = '~https?://\S+|www\.\S+~iu';

    private const PHONE = '/\+?\d[\d\s().\-]{7,}\d/u';

    /** @var array<string, string> */
    private array $map = [];

    private int $next = 1;

    /** Devuelve el texto con marcadores; el mapa se acumula en la instancia (un mapa por peticion). */
    public function mask(string $text): string
    {
        $swap = function (array $m): string {
            $marker = '⟦'.$this->next++.'⟧';
            $this->map[$marker] = $m[0];

            return $marker;
        };
        $text = preg_replace_callback(self::EMAIL, $swap, $text) ?? $text;
        $text = preg_replace_callback(self::LINK, $swap, $text) ?? $text;

        return preg_replace_callback(self::PHONE, fn (array $m) => self::isPhone($m[0]) ? $swap($m) : $m[0], $text) ?? $text;
    }

    /** Marcadores que contiene un texto, en orden de aparicion. @return list<string> */
    public static function markers(string $text): array
    {
        preg_match_all('/⟦\d+⟧/u', $text, $found);

        return $found[0];
    }

    /**
     * Restaura los originales en la traduccion de UN subtitulo. `$sent` es el texto enmascarado que se envio: la traduccion debe llevar exactamente
     * los mismos marcadores (ni uno menos ni uno mas, cada uno una vez). Devuelve null si no coinciden.
     */
    public function restore(string $sent, string $translated): ?string
    {
        $expected = self::markers($sent);
        $got = self::markers($translated);
        sort($expected);
        sort($got);
        if ($expected !== $got) {
            return null;
        }

        return strtr($translated, $this->map);
    }

    /** Un numero largo es un telefono; cifras con separador de millares, anos y rangos no. */
    private static function isPhone(string $candidate): bool
    {
        $digits = strlen(preg_replace('/\D/', '', $candidate) ?? '');
        if ($digits < 9 || $digits > 15) {
            return false;
        }

        return preg_match('/^\d{1,3}(?:[.\s]\d{3}){2,}$/', trim($candidate)) !== 1;
    }
}
