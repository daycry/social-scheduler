<?php

namespace App\Services\Export;

/**
 * Inventario de la exportación completa (T-84): qué tablas del usuario van en el ZIP, con qué columnas y qué se deja fuera a propósito.
 * Se parte de los modelos con `BelongsToUser`/`ownedBy()` (filas con `user_id`) más las tablas hijas que cuelgan de ellas (sin `user_id`).
 *
 * Cada entidad: `table`; `only` (lista blanca de columnas, en lo sensible) o `except` (lista negra); `json` (columnas que se guardan
 * como JSON y se escriben como estructura legible); `where` (filtro extra); `by` (columna del propietario, `user_id` por defecto);
 * `via` ([columna, tabla padre]) para tablas hijas; `order` (columnas) para las que no tienen `id`. Además `SECRET_COLUMN` descarta SIEMPRE por nombre cualquier columna con pinta de
 * credencial, aunque una lista se olvide de ella.
 */
final class ExportInventory
{
    /** Cualquier columna que case se omite de todas las entidades. */
    public const SECRET_COLUMN = '/token|secret|password|passkey|credential|signature|endpoint|api_key|remember/i';

    /** @var array<string, array{table: string, only?: list<string>, except?: list<string>, json?: list<string>, where?: array<string, mixed>, by?: string, via?: array{0: string, 1: string}, order?: list<string>}> */
    public const ENTITIES = [
        'usuario' => ['table' => 'users', 'by' => 'id', 'only' => ['id', 'name', 'email', 'notify_by_mail', 'notify_weekly_digest', 'weekly_digest_ai', 'notify_manual_reminder', 'hide_marketing_dates', 'created_at']],
        'marcas' => ['table' => 'brands'],
        'cuentas-conectadas' => ['table' => 'social_accounts', 'only' => ['id', 'brand_id', 'network', 'external_id', 'name', 'username', 'status', 'scopes', 'connected_at', 'created_at'], 'json' => ['scopes']],
        'publicaciones' => ['table' => 'posts', 'except' => ['approval_comment']],
        'destinos-de-publicacion' => ['table' => 'post_targets', 'json' => ['options']],
        'medios-de-publicacion' => ['table' => 'post_media', 'via' => ['post_id', 'posts']],
        'medios-de-destino' => ['table' => 'post_target_media', 'via' => ['post_target_id', 'post_targets']],
        'series' => ['table' => 'post_series', 'json' => ['weekdays', 'payload']],
        'medios-de-serie' => ['table' => 'post_series_media', 'via' => ['post_series_id', 'post_series']],
        'plantillas-de-publicacion' => ['table' => 'post_templates', 'json' => ['targets']],
        'medios-de-plantilla' => ['table' => 'post_template_media', 'via' => ['post_template_id', 'post_templates']],
        'campanas' => ['table' => 'campaigns'],
        'autolistas' => ['table' => 'autolists', 'json' => ['targets', 'slots', 'cursor']],
        'elementos-de-autolista' => ['table' => 'autolist_items', 'json' => ['media']],
        'medios-de-autolista' => ['table' => 'autolist_item_media', 'via' => ['autolist_item_id', 'autolist_items']],
        'huecos-de-autolista' => ['table' => 'autolist_slots'],
        'objetivos' => ['table' => 'goals'],
        'textos-guardados' => ['table' => 'saved_texts'],
        'vistas-de-calendario' => ['table' => 'calendar_views', 'json' => ['filters']],
        'ajustes-del-compositor' => ['table' => 'composer_presets', 'json' => ['options']],
        'competidores' => ['table' => 'competitors'],
        'respuestas-guardadas-del-buzon' => ['table' => 'inbox_saved_replies'],
        'comentarios-propios-del-buzon' => ['table' => 'inbox_comments', 'where' => ['is_own' => 1]],
        'notas-del-buzon' => ['table' => 'inbox_comment_notes'],
        'etiquetas-del-buzon' => ['table' => 'inbox_comment_labels'],
        'informes' => ['table' => 'reports', 'except' => ['path'], 'json' => ['blocks']],
        'plantillas-de-informe' => ['table' => 'report_templates', 'json' => ['blocks']],
        'programaciones-de-informe' => ['table' => 'report_schedules', 'json' => ['recipients']],
        'biblioteca-de-media' => ['table' => 'media_assets', 'except' => ['disk', 'path', 'upload_id', 'source_ref', 'import_state', 'origin']],
        'disenos' => ['table' => 'studio_designs', 'except' => ['document', 'thumbnail_path', 'approval_comment']],
        'carpetas-de-disenos' => ['table' => 'studio_design_folders'],
        'exportaciones-del-estudio' => ['table' => 'studio_design_exports', 'except' => ['disk', 'path', 'render_state'], 'json' => ['pages', 'media_ids', 'options']],
        'kits-de-marca' => ['table' => 'studio_brand_kits', 'json' => ['palette', 'fonts', 'logos', 'guidelines', 'voice', 'font_ids']],
        'tipografias-de-kits' => ['table' => 'studio_brand_kit_fonts', 'via' => ['kit_id', 'studio_brand_kits'], 'order' => ['kit_id', 'font_id']],
        'tablas-de-datos' => ['table' => 'studio_datasets'],
        'tipografias' => ['table' => 'studio_fonts', 'except' => ['disk', 'path', 'source_sha256']],
        'recursos-del-estudio' => ['table' => 'studio_assets'],
        'sitios' => ['table' => 'sites', 'json' => ['draft', 'published']],
        'smart-links' => ['table' => 'smart_links'],
        'bloques-de-smart-link' => ['table' => 'smart_link_blocks', 'via' => ['smart_link_id', 'smart_links'], 'json' => ['data']],
        'visitas-de-smart-links' => ['table' => 'smart_link_views', 'via' => ['smart_link_id', 'smart_links']],
        'clics-de-smart-links' => ['table' => 'smart_link_clicks', 'via' => ['smart_link_id', 'smart_links']],
        'enlaces-cortos' => ['table' => 'short_links'],
        'clics-de-enlaces-cortos' => ['table' => 'short_link_clicks', 'via' => ['short_link_id', 'short_links']],
        'visitas-de-sitios' => ['table' => 'site_visits', 'via' => ['site_id', 'sites']],
        'webhooks' => ['table' => 'webhooks', 'only' => ['id', 'brand_id', 'name', 'events', 'enabled', 'created_at'], 'json' => ['events']],
        'metricas-de-cuentas' => ['table' => 'account_snapshots', 'json' => ['metrics']],
        'metricas-de-publicaciones' => ['table' => 'post_metric_snapshots', 'json' => ['metrics']],
    ];

    /** Lo que NO va en el ZIP y por qué (se copia al LEEME.txt y a docs/usuario/exportar.md). */
    public const EXCLUDED = [
        'Credenciales y secretos: tokens de acceso y de renovación de las cuentas sociales, contraseñas de aplicación, claves de API y tokens Sanctum, el enlace secreto del feed .ics del calendario, hash de la contraseña, secreto y códigos de recuperación del doble factor, passkeys, endpoints y claves de las notificaciones push, secretos y URL de los webhooks, acceso con contraseña de los sitios y la clave de la aplicación. Se vuelven a crear al conectar de nuevo.',
        'Datos de otras personas: las marcas de las que solo eres colaborador, los diseños que te han compartido, los colaboradores de tus marcas, los suscriptores de tus smart links, los mensajes recibidos en tus sitios y los enlaces de acceso a tus diseños.',
        'Los comentarios que terceros han escrito en tus cuentas (buzón): son datos de esas personas. Sí van tus respuestas, tus notas y tus etiquetas.',
        'Los comentarios de aprobación de tus publicaciones y diseños (los escribe un colaborador); sí va el estado de aprobación.',
        'Los datos públicos de los competidores que sigues (publicaciones y seguidores): solo va la lista que has configurado.',
        'Registros técnicos: intentos de publicación, entregas de webhooks, actividad y auditoría, sesiones, claves de idempotencia, trabajos de IA, imágenes y audio del estudio, y el historial de revisiones de los diseños (va la versión actual).',
        'Las miniaturas, que se vuelven a generar.',
    ];
}
