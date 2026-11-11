<?php

namespace App\Mcp\Servers;

use App\Mcp\Tools\AddCommentNote;
use App\Mcp\Tools\AttachDesignAsset;
use App\Mcp\Tools\CancelPost;
use App\Mcp\Tools\CreateDesign;
use App\Mcp\Tools\CreatePost;
use App\Mcp\Tools\DeletePost;
use App\Mcp\Tools\ExportDesign;
use App\Mcp\Tools\GenerateReport;
use App\Mcp\Tools\GetBestTimes;
use App\Mcp\Tools\GetCalendar;
use App\Mcp\Tools\GetDesign;
use App\Mcp\Tools\GetInstagramAudio;
use App\Mcp\Tools\GetPost;
use App\Mcp\Tools\GetPostStats;
use App\Mcp\Tools\GetReport;
use App\Mcp\Tools\GetStats;
use App\Mcp\Tools\ImportMediaFromUrl;
use App\Mcp\Tools\ListAccounts;
use App\Mcp\Tools\ListBrands;
use App\Mcp\Tools\ListCalendarViews;
use App\Mcp\Tools\ListCampaigns;
use App\Mcp\Tools\ListComments;
use App\Mcp\Tools\ListDesigns;
use App\Mcp\Tools\ListMedia;
use App\Mcp\Tools\ManageBrandKit;
use App\Mcp\Tools\ManageDesign;
use App\Mcp\Tools\ManageDesignCaptions;
use App\Mcp\Tools\ManageDesignExport;
use App\Mcp\Tools\ManageDesignFolder;
use App\Mcp\Tools\ManageStudioAudio;
use App\Mcp\Tools\ManageStudioFont;
use App\Mcp\Tools\ProcessStudioImage;
use App\Mcp\Tools\PublishPostNow;
use App\Mcp\Tools\ReplyComment;
use App\Mcp\Tools\ReschedulePost;
use App\Mcp\Tools\SearchInstagramAudio;
use App\Mcp\Tools\SuggestHashtags;
use App\Mcp\Tools\UpdateDesign;
use App\Mcp\Tools\UpdatePost;
use App\Mcp\Tools\UpdatePostCampaign;
use App\Mcp\Tools\UpdatePostNotes;
use Laravel\Mcp\Server;
use Laravel\Mcp\Server\Attributes\Icon;
use Laravel\Mcp\Server\Attributes\Instructions;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Attributes\Version;

// Icono que muestran los clientes MCP que leen `serverInfo.icons` (rutas relativas: laravel/mcp las hace absolutas con APP_URL).
#[Icon('/icon-512.png', 'image/png', ['512x512'])]
#[Icon('/icon-192.png', 'image/png', ['192x192'])]
#[Icon('/favicon.svg', 'image/svg+xml', ['any'])]
#[Name('Social Scheduler')]
#[Version('1.0.0')]
#[Instructions('Programa y consulta publicaciones en Instagram, Facebook, YouTube, TikTok, Bluesky y Threads (list_accounts dice cuales estan disponibles en esta instalacion y sus tipos de contenido; cada red cuenta el texto a su manera: Bluesky 300 grafemas, Threads 500 con el conteo conservador de emojis y 1 etiqueta de tema). Audio de Instagram Reels: search_instagram_audio busca pistas autorizadas de Meta para account_id; get_instagram_audio consulta la pista y renueva su preescucha temporal. create_post/update_post aceptan targets[].options.instagram_audio={audio_id, audio_volume, video_volume}; solo Reels elegibles mediante Facebook, volumenes 1-100 y null quita la pista. list_accounts anuncia instagram_audio.available. Nunca inventes un audio_id ni lo obtengas de una URL de Reel; la preescucha no es la mezcla final. Flujo: list_brands -> list_accounts (cuentas y tipos de contenido validos) -> list_media (media ya subida; se referencia por id; import_media_from_url importa desde una URL publica y queda pendiente: list_media con status=pendiente da su progreso y con status=invalido el motivo del fallo; solo la media «listo» se usa en un post) -> list_campaigns (opcional: campaign_id del post) -> create_post; update_post_notes edita las notas internas (nunca se publican) y update_post_campaign asigna o quita la campana, ambas aunque el post ya este publicado; publish_post_now publica ya un borrador o una programada. Estadisticas: get_stats (por marca o red y periodo, con comparacion; null = no disponible, nunca 0) y get_post_stats; list_calendar_views lista las vistas guardadas del calendario (usa su id en get_calendar view=<id>); get_best_times da el engagement medio por dia y hora para elegir cuando publicar; suggest_hashtags propone hashtags del historico propio de la marca (sin IA). Informes: generate_report (encola y devuelve el id) -> get_report (estado y enlace firmado de descarga que caduca). Buzon: list_comments (comentarios de IG/FB; Bluesky y Threads no tienen buzon, filtros network/unread) reply_comment y add_comment_note (nota interna que nunca se publica) (abilities inbox:read / inbox:write). Las fechas sin zona se interpretan en la zona horaria de la marca. Disenos nativos por marca: list_designs -> get_design (documento tipado v1/v2, revision, kit, assets y exports) -> create_design/update_design (revision esperada; 409 exige recargar). Media: import_media_from_url -> list_media listo -> attach_design_asset -> asset_id en el documento; nunca URLs arbitrarias en elementos. manage_brand_kit edita colores, fuentes, logos y voz; font_ids alinea las fuentes privadas con fonts. manage_studio_font lista, sube, archiva, restaura y elimina tipografias privadas por marca; usa font_id en texto y la familia devuelta, nunca inventes IDs ni URLs de fuentes. manage_design_captions get/export consulta subtítulos propios paginados o texto SRT/VTT por revision (designs:read); import/replace/upsert/delete/offset/style/clear modifican con revision optimista (designs:write). v1 requiere activate_temporal:true explícito; no genera voz ni traduce. manage_studio_audio start devuelve ticket binario de audio propio por marca; transfiere por HTTP sin enviar Bearer a URLs de almacenamiento y complete valida contenido/duracion/codec; list/get consultan recursos privados. process_studio_image start encola remove_background para media_id propio registrado en la marca; get consulta queued/processing/ready/failed hasta obtener output_asset nuevo, conservando el original. Estas herramientas requieren designs:read para consultas y designs:write para cambios. manage_design duplica, archiva y restaura sin perder revisiones/exportaciones. manage_design_folder lista, crea o elimina carpetas de una marca; list requiere designs:read, mutaciones designs:write. get_design pagina exports de 50 en 50 con exports_page y exports_pagination. export_design renderiza PNG/JPEG/WebP/SVG/PDF y MP4/GIF por cola studio. manage_design_export get consulta estado/progress/download_url; cancel/retry gestionan el trabajo nativo conservando revision/opciones y post crea solo un borrador en su marca (requiere designs:write y posts:write). MP4/GIF no admiten frame_times no vacio ni transparencia; escala/calidad y paginas se conservan. Consulta get_design o manage_design_export para estado real; no inventes archivos. El documento v2 conserva timeline.fps/audio_tracks y timeline.captions opcional, timing y metadatos de clip; nunca lo rebajes a v1 ni borres sus campos al actualizar. Los formatos disponibles son los anunciados por export_design; no inventes MP4/GIF antes de que el contrato los incluya. Estas herramientas no publican. Permisos designs:read/designs:write. tools/list esta paginado: sigue nextCursor hasta que desaparezca, conservando todas las herramientas. Solo ves y modificas los datos del usuario dueno de la API key.')]
class SchedulerServer extends Server
{
    /** Clients follow nextCursor after each page; studio tools bring the catalog above 30 entries. */
    public int $defaultPaginationLength = 30;

    protected array $tools = [
        ListBrands::class,
        ListAccounts::class,
        ListMedia::class,
        ImportMediaFromUrl::class,
        ListCampaigns::class,
        GetCalendar::class,
        ListCalendarViews::class,
        GetPost::class,
        CreatePost::class,
        UpdatePost::class,
        UpdatePostNotes::class,
        UpdatePostCampaign::class,
        ReschedulePost::class,
        PublishPostNow::class,
        CancelPost::class,
        DeletePost::class,
        GetStats::class,
        GetPostStats::class,
        GetBestTimes::class,
        GenerateReport::class,
        GetReport::class,
        ListComments::class,
        ReplyComment::class,
        AddCommentNote::class,
        SuggestHashtags::class,
        SearchInstagramAudio::class,
        GetInstagramAudio::class,
        ListDesigns::class,
        GetDesign::class,
        CreateDesign::class,
        UpdateDesign::class,
        ExportDesign::class,
        ManageBrandKit::class,
        AttachDesignAsset::class,
        ManageDesign::class,
        ManageDesignExport::class,
        ManageDesignFolder::class,
        ManageStudioFont::class,
        ProcessStudioImage::class,
        ManageStudioAudio::class,
        ManageDesignCaptions::class,
    ];
}
