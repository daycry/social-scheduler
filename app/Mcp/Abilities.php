<?php

namespace App\Mcp;

use App\Services\InboxService;

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

    public const MEDIA_READ = 'media:read';

    public const MEDIA_WRITE = 'media:write';

    public const REPORTS_WRITE = 'reports:write';

    public const INBOX_READ = 'inbox:read';

    public const INBOX_WRITE = 'inbox:write';

    public const SITES_READ = 'sites:read';

    public const SITES_WRITE = 'sites:write';

    /** SmartLinks (T-29): el microsite publico de una marca y sus estadisticas; escribir = crearlo, editarlo y publicarlo. Solo en la API v1. */
    public const SMARTLINKS_READ = 'smartlinks:read';

    public const SMARTLINKS_WRITE = 'smartlinks:write';

    /** Alta y baja de competidores de una marca (T-30): la baja borra su historico. No basta posts:write; solo en el preset `all`. */
    public const COMPETITORS_WRITE = 'competitors:write';

    /** Canva (T-16): listar disenos de Canva de una marca conectada y consultar trabajos; escribir = exportar a la biblioteca o enviar un recurso a Canva. */
    public const CANVA_READ = 'canva:read';

    public const CANVA_WRITE = 'canva:write';

    /** IA creativa del estudio (T-14): generar y editar imagenes con un proveedor de pago configurado. No basta designs:write. */
    public const DESIGNS_AI = 'designs:ai';

    /** @return array<string, string> ability => descripcion para la UI */
    public static function all(): array
    {
        $inboxNetworks = app(InboxService::class)->networksLabel();

        return [
            self::BRANDS_READ => 'Leer marcas y cuentas conectadas',
            self::POSTS_READ => 'Leer publicaciones, su estado y el calendario',
            self::POSTS_WRITE => 'Crear, programar, editar, cancelar y borrar publicaciones',
            self::POSTS_APPROVE => 'Aprobar y rechazar publicaciones pendientes de aprobacion',
            self::STATS_READ => 'Leer estadisticas de marcas, redes y publicaciones',
            self::DESIGNS_READ => 'Leer diseños, recursos, kits y exportaciones de las marcas',
            self::DESIGNS_WRITE => 'Crear y editar diseños, kits y exportaciones de las marcas',
            self::MEDIA_READ => 'Leer archivos de la biblioteca y consultar su estado',
            self::MEDIA_WRITE => 'Subir, importar y borrar archivos de la biblioteca',
            self::REPORTS_WRITE => 'Generar informes PDF/CSV y consultar su estado',
            self::INBOX_READ => 'Leer los comentarios recibidos en '.$inboxNetworks,
            self::INBOX_WRITE => 'Responder a comentarios de '.$inboxNetworks.' y anadirles notas internas',
            self::SITES_READ => 'Leer los sitios publicados de las marcas y su URL',
            self::SITES_WRITE => 'Publicar y despublicar los sitios de las marcas',
            self::SMARTLINKS_READ => 'Leer el SmartLink de las marcas y sus estadisticas de visitas y clics',
            self::SMARTLINKS_WRITE => 'Crear, editar y publicar el SmartLink de las marcas',
            self::COMPETITORS_WRITE => 'Dar de alta y de baja competidores de las marcas (la baja borra su historico)',
            self::CANVA_READ => 'Ver el estado de Canva de una marca, listar sus disenos y plantillas de marca de Canva y consultar trabajos de importacion',
            self::CANVA_WRITE => 'Exportar disenos de Canva a la biblioteca, enviar recursos a Canva y rellenar plantillas de marca con Autofill (no compra ni publica nada)',
            self::DESIGNS_AI => 'Generar y editar imagenes con IA en el estudio (consume la cuota del proveedor configurado)',
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
            'publish' => ['label' => 'Publicar', 'abilities' => [...$read, self::POSTS_WRITE, self::MEDIA_WRITE, self::REPORTS_WRITE]],
            'all' => ['label' => 'Todo', 'abilities' => self::keys()],
        ];
    }

    /** @return list<string> */
    public static function keys(): array
    {
        return array_keys(self::all());
    }
}
