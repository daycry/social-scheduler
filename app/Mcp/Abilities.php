<?php

namespace App\Mcp;

/** Permisos (abilities de Sanctum) que se pueden dar a una API key del MCP. `*` = todos. */
final class Abilities
{
    public const BRANDS_READ = 'brands:read';

    public const POSTS_READ = 'posts:read';

    public const POSTS_WRITE = 'posts:write';

    /** @return array<string, string> ability => descripcion para la UI */
    public static function all(): array
    {
        return [
            self::BRANDS_READ => 'Leer marcas, cuentas conectadas y media',
            self::POSTS_READ => 'Leer publicaciones, su estado y el calendario',
            self::POSTS_WRITE => 'Crear, programar, editar, cancelar y borrar publicaciones',
        ];
    }

    /** @return list<string> */
    public static function keys(): array
    {
        return array_keys(self::all());
    }
}
