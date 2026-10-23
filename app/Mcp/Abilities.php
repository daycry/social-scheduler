<?php

namespace App\Mcp;

/** Permisos (abilities de Sanctum) que se pueden dar a una API key del MCP. `*` = todos. */
final class Abilities
{
    public const BRANDS_READ = 'brands:read';

    public const POSTS_READ = 'posts:read';

    public const POSTS_WRITE = 'posts:write';

    public const STATS_READ = 'stats:read';

    public const REPORTS_WRITE = 'reports:write';

    public const INBOX_READ = 'inbox:read';

    public const INBOX_WRITE = 'inbox:write';

    /** @return array<string, string> ability => descripcion para la UI */
    public static function all(): array
    {
        return [
            self::BRANDS_READ => 'Leer marcas, cuentas conectadas y media',
            self::POSTS_READ => 'Leer publicaciones, su estado y el calendario',
            self::POSTS_WRITE => 'Crear, programar, editar, cancelar y borrar publicaciones',
            self::STATS_READ => 'Leer estadisticas de marcas, redes y publicaciones',
            self::REPORTS_WRITE => 'Generar informes PDF/CSV y consultar su estado',
            self::INBOX_READ => 'Leer los comentarios recibidos en Instagram y Facebook',
            self::INBOX_WRITE => 'Responder a comentarios de Instagram y Facebook',
        ];
    }

    /**
     * Conjuntos predefinidos para la UI (las casillas siguen editables). `read` ⊂ `publish` ⊂ `all`.
     *
     * @return array<string, array{label: string, abilities: list<string>}>
     */
    public static function presets(): array
    {
        $read = array_values(array_filter(self::keys(), fn (string $a) => str_ends_with($a, ':read')));

        return [
            'read' => ['label' => 'Solo lectura', 'abilities' => $read],
            'publish' => ['label' => 'Publicar', 'abilities' => [...$read, self::POSTS_WRITE, self::REPORTS_WRITE]],
            'all' => ['label' => 'Todo', 'abilities' => self::keys()],
        ];
    }

    /** @return list<string> */
    public static function keys(): array
    {
        return array_keys(self::all());
    }
}
