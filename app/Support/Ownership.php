<?php

namespace App\Support;

use App\Exceptions\OwnershipException;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;

/**
 * Comprobación de propiedad compartida por servicios, controladores y Policies.
 * Los adaptadores web, API y MCP traducen OwnershipException al error de recurso inexistente.
 */
final class Ownership
{
    /** Lanza si el recurso no existe o su user_id no es el del usuario. */
    public static function assert(?Model $resource, User|int $user): void
    {
        if (! self::matches($resource, $user)) {
            throw new OwnershipException;
        }
    }

    /** Permite a la Policy o al servicio conservar su propia respuesta al denegar el acceso. */
    public static function matches(?Model $resource, User|int $user): bool
    {
        $userId = $user instanceof User ? (int) $user->getKey() : $user;

        return $userId > 0 && (string) $resource?->getAttribute('user_id') === (string) $userId;
    }

    /** Igual que assert() sobre varios recursos: basta uno ajeno para denegar todos. */
    public static function assertAll(iterable $resources, User|int $user): void
    {
        foreach ($resources as $resource) {
            self::assert($resource, $user);
        }
    }

    /** Condicion de pertenencia que no se reduce a user_id (existencia filtrada por ownedBy(), pertenencia a una marca...). */
    public static function ensure(bool $holds): void
    {
        if (! $holds) {
            throw new OwnershipException;
        }
    }
}
