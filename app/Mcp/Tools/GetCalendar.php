<?php

namespace App\Mcp\Tools;

use App\Mcp\Abilities;
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
            ...$this->brandSchema($schema, 'Filtrar por marca'),
            'network' => $schema->string()->description('instagram, facebook, youtube o tiktok'),
            'status' => $schema->string()->description('Filtrar por estado (borrador, programada, publicada, fallida...)'),
        ];
    }

    protected function run(Request $request, User $user): array
    {
        $filters = $request->all(['view', 'date', 'network', 'status']);

        // El servicio solo conoce las marcas del usuario: una ajena responde como inexistente.
        $filters['brand_id'] = $this->brandFrom($request, $user, required: false)?->id;

        return app(CalendarService::class)->build($user, $filters);
    }
}
