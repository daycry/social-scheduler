<?php

namespace App\Support;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Pagination\Cursor;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use UnexpectedValueException;

/**
 * Contrato de paginacion por cursor unico de la API v1, el MCP y la web: entrada `limit` (1-100) y `cursor` (opaco), salida
 * `has_more` y `next_cursor` (nulo en la ultima pagina). La paginación avanza en un orden total con columnas no nulas y el id de desempate.
 *
 * El scope comprueba que el cliente usa el cursor del mismo listado. Incluye la marca si es parte del filtro.
 * La autorización sigue en la consulta. El total, cuando se solicita, se consulta aparte de la página.
 */
final class CursorPage
{
    public const MAX_LIMIT = 100;

    /** Reglas de validacion de la entrada, comunes a todos los listados. */
    public const RULES = ['limit' => ['nullable', 'integer', 'min:1', 'max:100'], 'cursor' => ['nullable', 'string', 'max:1000']];

    /**
     * @param  Builder<Model>  $query  con su orden total ya aplicado
     * @param  string  $scope  identidad del listado, p. ej. `posts:u1:b2`
     * @param  array<string, list<string>>  $cursorRules  reglas de valores cuyo tipo no se deduce del modelo
     * @return array{0: Collection<int, Model>, 1: array{has_more: bool, next_cursor: ?string, total?: int}}
     */
    public static function fetch(Builder $query, mixed $limit, mixed $cursor, string $scope, int $default = 50, bool $withTotal = false, array $cursorRules = []): array
    {
        $limit = is_numeric($limit) ? min(self::MAX_LIMIT, max(1, (int) $limit)) : $default;
        $decoded = null;
        if (is_string($cursor) && $cursor !== '') {
            $outer = json_decode((string) base64_decode(strtr($cursor, '-_', '+/'), true), true);
            if (! is_array($outer) || ($outer['s'] ?? null) !== $scope || ! is_string($outer['c'] ?? null)) {
                throw self::invalid();
            }
            $decoded = self::decodeForwardCursor($outer['c'], $query->getModel(), $cursorRules);
        }

        try {
            $page = (clone $query)->cursorPaginate($limit, ['*'], 'cursor', $decoded);
        } catch (UnexpectedValueException) {
            // Un cursor de otro listado no trae las columnas del orden de este.
            throw self::invalid();
        }

        $pagination = ['has_more' => $page->hasMorePages(), 'next_cursor' => self::seal($scope, $page->nextCursor())];
        if ($withTotal) {
            $pagination['total'] = $query->toBase()->getCountForPagination();
        }

        return [$page->getCollection(), $pagination];
    }

    private static function decodeForwardCursor(string $encoded, Model $model, array $rules): Cursor
    {
        if (! preg_match('/^[A-Za-z0-9_-]+$/D', $encoded)) {
            throw self::invalid();
        }
        $cursor = Cursor::fromEncoded($encoded);
        if ($cursor === null || $cursor->pointsToNextItems() !== true) {
            throw self::invalid();
        }

        $values = $cursor->toArray();
        unset($values['_pointsToNextItems']);
        $validation = [];
        foreach ($values as $key => $value) {
            if (! is_string($key) || ! is_scalar($value)) {
                throw self::invalid();
            }

            $column = Str::afterLast($key, '.');
            $ownColumn = $key === $column || $key === $model->qualifyColumn($column);
            $rule = $rules[$key] ?? ($ownColumn ? ($rules[$column] ?? self::columnRules($model, $column)) : []);
            if ($rule !== []) {
                $validation[str_replace('.', '\.', $key)] = $rule;
            }
        }

        if (Validator::make($values, $validation)->fails()) {
            throw self::invalid();
        }

        return $cursor;
    }

    /** @return list<string> */
    private static function columnRules(Model $model, string $column): array
    {
        if ($column === $model->getKeyName()) {
            return $model->getKeyType() === 'int' ? ['required', 'integer', 'min:1'] : ['required', 'string'];
        }
        if (in_array($column, $model->getDates(), true) || $model->hasCast($column, [
            'date', 'datetime', 'custom_datetime', 'immutable_date', 'immutable_datetime', 'immutable_custom_datetime',
        ])) {
            return ['required', 'date_format:'.$model->getDateFormat()];
        }

        return [];
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
