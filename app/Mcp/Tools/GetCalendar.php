<?php

namespace App\Mcp\Tools;

use App\Mcp\Abilities;
use App\Models\Brand;
use App\Models\User;
use App\Services\CalendarService;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[Name('get_calendar')]
#[Description('Calendario de publicaciones (semana, mes o lista) filtrable por marca, red y estado.')]
#[IsReadOnly]
class GetCalendar extends SchedulerTool
{
    protected function ability(): string
    {
        return Abilities::POSTS_READ;
    }

    protected function resource(): ?string
    {
        return 'post';
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'view' => $schema->string()->enum(CalendarService::VIEWS)->description('week (por defecto), month o list'),
            'date' => $schema->string()->description('Fecha ancla YYYY-MM-DD (por defecto hoy)'),
            'brand_id' => $schema->integer()->description('Filtrar por marca'),
            'network' => $schema->string()->description('instagram, facebook, youtube o tiktok'),
            'status' => $schema->string()->description('Filtrar por estado (borrador, programada, publicada, fallida...)'),
        ];
    }

    protected function run(Request $request, User $user): array
    {
        $filters = $request->all(['view', 'date', 'brand_id', 'network', 'status']);

        // El servicio solo conoce las marcas del usuario: una ajena responde como inexistente.
        if (! empty($filters['brand_id'])) {
            $this->owned(Brand::class, $user, $filters['brand_id'], 'Marca');
        }

        return app(CalendarService::class)->build($user, $filters);
    }
}
