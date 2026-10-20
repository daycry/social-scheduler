<?php

namespace App\Mcp\Servers;

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
use App\Mcp\Tools\ListAccounts;
use App\Mcp\Tools\ListBrands;
use App\Mcp\Tools\ListMedia;
use App\Mcp\Tools\ReschedulePost;
use App\Mcp\Tools\UpdatePost;
use Laravel\Mcp\Server;
use Laravel\Mcp\Server\Attributes\Instructions;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Attributes\Version;

#[Name('Social Scheduler')]
#[Version('1.0.0')]
#[Instructions('Programa y consulta publicaciones en Instagram, Facebook, YouTube y TikTok. Flujo: list_brands -> list_accounts (cuentas y tipos de contenido validos) -> list_media (media ya subida; se referencia por id) -> create_post. Estadisticas: get_stats (por marca o red y periodo, con comparacion; null = no disponible, nunca 0) y get_post_stats; get_best_times da el engagement medio por dia y hora para elegir cuando publicar. Informes: generate_report (encola y devuelve el id) -> get_report (estado y enlace firmado de descarga que caduca). Las fechas sin zona se interpretan en la zona horaria de la marca. Solo ves y modificas los datos del usuario dueno de la API key.')]
class SchedulerServer extends Server
{
    protected array $tools = [
        ListBrands::class,
        ListAccounts::class,
        ListMedia::class,
        GetCalendar::class,
        GetPost::class,
        CreatePost::class,
        UpdatePost::class,
        ReschedulePost::class,
        CancelPost::class,
        DeletePost::class,
        GetStats::class,
        GetPostStats::class,
        GetBestTimes::class,
        GenerateReport::class,
        GetReport::class,
    ];
}
