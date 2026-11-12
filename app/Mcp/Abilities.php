<?php

namespace App\Mcp;

/** Permisos (abilities de Sanctum) que se pueden dar a una API key del MCP. `*` = todos. */
final class Abilities
{
    public const BRANDS_READ = 'brands:read';

    public const POSTS_READ = 'posts:read';

    public const POSTS_WRITE = 'posts:write';

    /** Aprobar o rechazar publicaciones (T-19): no basta posts:write. */
    public const POSTS_APPROVE = 'posts:approve';

    public const STATS_READ = 'stats:read';

    public const DESIGNS_READ = 'designs:read';

    public const DESIGNS_WRITE = 'designs:write';

    public const REPORTS_WRITE = 'reports:write';

    public const INBOX_READ = 'inbox:read';

    public const INBOX_WRITE = 'inbox:write';

    public const SITES_READ = 'sites:read';

    public const SITES_WRITE = 'sites:write';

    /** Canva (T-16): listar disenos de Canva de una marca conectada y consultar trabajos; escribir = exportar a la biblioteca o enviar un recurso a Canva. */
    public const CANVA_READ = 'canva:read';

    public const CANVA_WRITE = 'canva:write';

    /** IA creativa del estudio (T-14): generar y editar imagenes con un proveedor de pago configurado. No basta designs:write. */
    public const STUDIO_AI = 'studio:ai';

    /** @return array<string, string> ability => descripcion para la UI */
    public static function all(): array
    {
        return [
            self::BRANDS_READ => 'Leer marcas, cuentas conectadas y media',
            self::POSTS_READ => 'Leer publicaciones, su estado y el calendario',
            self::POSTS_WRITE => 'Crear, programar, editar, cancelar y borrar publicaciones',
            self::POSTS_APPROVE => 'Aprobar y rechazar publicaciones pendientes de aprobacion',
            self::STATS_READ => 'Leer estadisticas de marcas, redes y publicaciones',
            self::DESIGNS_READ => 'Leer diseños, recursos, kits y exportaciones de las marcas',
            self::DESIGNS_WRITE => 'Crear y editar diseños, kits y exportaciones de las marcas',
            self::REPORTS_WRITE => 'Generar informes PDF/CSV y consultar su estado',
            self::INBOX_READ => 'Leer los comentarios recibidos en Instagram, Facebook, Threads, YouTube y Bluesky',
            self::INBOX_WRITE => 'Responder a comentarios de Instagram, Facebook, Threads, YouTube y Bluesky y anadirles notas internas',
            self::SITES_READ => 'Leer los sitios publicados de las marcas y su URL',
            self::SITES_WRITE => 'Publicar y despublicar los sitios de las marcas',
            self::CANVA_READ => 'Ver el estado de Canva de una marca, listar sus disenos y plantillas de marca de Canva y consultar trabajos de importacion',
            self::CANVA_WRITE => 'Exportar disenos de Canva a la biblioteca, enviar recursos a Canva y rellenar plantillas de marca con Autofill (no compra ni publica nada)',
            self::STUDIO_AI => 'Generar y editar imagenes con IA en el estudio (consume la cuota del proveedor configurado)',
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
