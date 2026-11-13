<?php

namespace App\Support;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Pagination\Cursor;
use Illuminate\Validation\ValidationException;
use UnexpectedValueException;

/**
 * Contrato de paginacion por cursor unico de la API v1, el MCP y la web: entrada `limit` (1-100) y `cursor` (opaco), salida
 * `has_more` y `next_cursor` (nulo en la ultima pagina). El orden de la consulta debe ser total (acabar en `id`).
 *
 * El cursor lleva la identidad del listado (`scope`: recurso, marca y usuario): uno de otro listado se rechaza con 422 en lugar de
 * paginar otra cosa o filtrar por casualidad.
 */
final class CursorPage
{
    public const MAX_LIMIT = 100;

    /** Reglas de validacion de la entrada, comunes a todos los listados. */
    public const RULES = ['limit' => ['nullable', 'integer', 'min:1', 'max:100'], 'cursor' => ['nullable', 'string', 'max:1000']];

    /**
     * @param  Builder<Model>  $query  con su orden total ya aplicado
     * @param  string  $scope  identidad del listado, p. ej. `posts:u1:b2`
     * @return array{0: Collection<int, Model>, 1: array{has_more: bool, next_cursor: ?string}}
     */
    public static function fetch(Builder $query, mixed $limit, mixed $cursor, string $scope, int $default = 50): array
    {
        $limit = is_numeric($limit) ? min(self::MAX_LIMIT, max(1, (int) $limit)) : $default;
        $decoded = null;
        if (is_string($cursor) && $cursor !== '') {
            $outer = json_decode((string) base64_decode(strtr($cursor, '-_', '+/'), true), true);
            if (! is_array($outer) || ($outer['s'] ?? null) !== $scope || ! is_string($outer['c'] ?? null)) {
                throw self::invalid();
            }
            $decoded = Cursor::fromEncoded($outer['c']);
            if ($decoded === null || $decoded->pointsToPreviousItems()) {
                throw self::invalid();
            }
        }

        try {
            $page = $query->cursorPaginate($limit, ['*'], 'cursor', $decoded);
        } catch (UnexpectedValueException) {
            // Un cursor de otro listado no trae las columnas del orden de este.
            throw self::invalid();
        }

        return [$page->getCollection(), ['has_more' => $page->hasMorePages(), 'next_cursor' => self::seal($scope, $page->nextCursor())]];
    }

    private static function seal(string $scope, ?Cursor $cursor): ?string
    {
        return $cursor === null ? null : rtrim(strtr(base64_encode((string) json_encode(['s' => $scope, 'c' => $cursor->encode()])), '+/', '-_'), '=');
    }

    private static function invalid(): ValidationException
    {
        return ValidationException::withMessages(['cursor' => ['El cursor no es valido: usa el next_cursor de la pagina anterior.']]);
    }
}
