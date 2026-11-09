<?php

namespace App\Services\Reports;

use InvalidArgumentException;

/**
 * Bloques elegibles y ordenables del constructor de informes (T-21): KPIs por red, evolucion, publicaciones
 * destacadas, competidores, Ads y notas de texto. Se normalizan siempre aqui (orden = orden del array) antes de
 * guardarse en una plantilla o en un informe; el texto nunca se interpreta como HTML (el PDF lo escapa).
 */
final class ReportBlocks
{
    public const KPIS = 'kpis';

    public const EVOLUTION = 'evolution';

    public const TOP_POSTS = 'top_posts';

    public const COMPETITORS = 'competitors';

    public const ADS = 'ads';

    public const TEXT = 'text';

    public const TYPES = [self::KPIS, self::EVOLUTION, self::TOP_POSTS, self::COMPETITORS, self::ADS, self::TEXT];

    public const MAX_BLOCKS = 20;

    public const MAX_TITLE = 80;

    public const MAX_TEXT = 2000;

    /** Bloques por defecto cuando no se elige ninguno (equivalen al informe clasico). */
    public const DEFAULT = [self::KPIS, self::EVOLUTION, self::TOP_POSTS];

    /**
     * @param  mixed  $blocks  lista de {type, title?, text?}
     * @return list<array{type: string, title: ?string, text: ?string}>
     *
     * @throws InvalidArgumentException
     */
    public static function normalize(mixed $blocks): array
    {
        if (! is_array($blocks) || $blocks === [] || ! array_is_list($blocks)) {
            throw new InvalidArgumentException('Elige al menos un bloque para el informe.');
        }

        if (count($blocks) > self::MAX_BLOCKS) {
            throw new InvalidArgumentException('Un informe admite como maximo '.self::MAX_BLOCKS.' bloques.');
        }

        $out = [];

        foreach ($blocks as $block) {
            $type = is_array($block) ? ($block['type'] ?? null) : null;

            if (! is_string($type) || ! in_array($type, self::TYPES, true)) {
                throw new InvalidArgumentException('Tipo de bloque no valido.');
            }

            $title = isset($block['title']) && is_string($block['title']) ? trim($block['title']) : null;
            $text = isset($block['text']) && is_string($block['text']) ? trim($block['text']) : null;

            if ($title !== null && mb_strlen($title) > self::MAX_TITLE) {
                throw new InvalidArgumentException('El titulo de un bloque admite como maximo '.self::MAX_TITLE.' caracteres.');
            }

            if ($type === self::TEXT) {
                if ($text === null || $text === '') {
                    throw new InvalidArgumentException('La nota de texto no puede estar vacia.');
                }

                if (mb_strlen($text) > self::MAX_TEXT) {
                    throw new InvalidArgumentException('La nota de texto admite como maximo '.self::MAX_TEXT.' caracteres.');
                }
            } else {
                $text = null;
            }

            $out[] = ['type' => $type, 'title' => $title === '' ? null : $title, 'text' => $text];
        }

        return $out;
    }

    /** @return list<array{type: string, title: ?string, text: ?string}> */
    public static function defaults(): array
    {
        return array_map(fn (string $t) => ['type' => $t, 'title' => null, 'text' => null], self::DEFAULT);
    }
}
