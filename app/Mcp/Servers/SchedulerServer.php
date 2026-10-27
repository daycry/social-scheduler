<?php

namespace App\Mcp\Servers;

use App\Mcp\Tools\AddCommentNote;
use App\Mcp\Tools\CancelPost;
use App\Mcp\Tools\CreatePost;
use App\Mcp\Tools\DeletePost;
use App\Mcp\Tools\GenerateReport;
use App\Mcp\Tools\GetBestTimes;
use App\Mcp\Tools\GetCalendar;
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
use App\Mcp\Tools\ListMedia;
use App\Mcp\Tools\PublishPostNow;
use App\Mcp\Tools\ReplyComment;
use App\Mcp\Tools\ReschedulePost;
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
#[Instructions('Programa y consulta publicaciones en Instagram, Facebook, YouTube y TikTok. Flujo: list_brands -> list_accounts (cuentas y tipos de contenido validos) -> list_media (media ya subida; se referencia por id; import_media_from_url importa desde una URL publica y queda pendiente: list_media con status=pendiente da su progreso y con status=invalido el motivo del fallo; solo la media «listo» se usa en un post) -> list_campaigns (opcional: campaign_id del post) -> create_post; update_post_notes edita las notas internas (nunca se publican) y update_post_campaign asigna o quita la campana, ambas aunque el post ya este publicado; publish_post_now publica ya un borrador o una programada. Estadisticas: get_stats (por marca o red y periodo, con comparacion; null = no disponible, nunca 0) y get_post_stats; list_calendar_views lista las vistas guardadas del calendario (usa su id en get_calendar view=<id>); get_best_times da el engagement medio por dia y hora para elegir cuando publicar. Informes: generate_report (encola y devuelve el id) -> get_report (estado y enlace firmado de descarga que caduca). Buzon: list_comments (comentarios de IG/FB, filtros network/unread) reply_comment y add_comment_note (nota interna que nunca se publica) (abilities inbox:read / inbox:write). Las fechas sin zona se interpretan en la zona horaria de la marca. Solo ves y modificas los datos del usuario dueno de la API key.')]
class SchedulerServer extends Server
{
    /** Todas las herramientas en la primera pagina de `tools/list` (el valor por defecto, 15, dejaba fuera a las ultimas). */
    public int $defaultPaginationLength = 25;

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
    ];
}
