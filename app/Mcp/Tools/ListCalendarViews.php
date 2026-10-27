<?php

namespace App\Mcp\Tools;

use App\Mcp\Abilities;
use App\Models\CalendarView;
use App\Models\User;
use App\Services\CalendarViewService;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[Name('list_calendar_views')]
#[Description('Lista las vistas guardadas del calendario del usuario (nombre y filtros: marca, red, estado y vista week|month|list; una esta marcada por defecto). Usa su id en get_calendar con view=<id> para obtener el calendario con esos filtros.')]
#[IsReadOnly]
class ListCalendarViews extends SchedulerTool
{
    protected function ability(): string
    {
        return Abilities::POSTS_READ;
    }

    protected function resource(): ?string
    {
        return 'calendar_view';
    }

    public function schema(JsonSchema $schema): array
    {
        return [];
    }

    protected function run(Request $request, User $user): array
    {
        $service = app(CalendarViewService::class);
        $names = $this->brandNames($user);

        return ['views' => $service->list($user)->map(function (CalendarView $v) use ($service, $names) {
            $row = $service->toArray($v);
            $row['brand_name'] = isset($row['filters']['brand_id']) ? ($names[$row['filters']['brand_id']] ?? null) : null;

            return $row;
        })->values()->all()];
    }
}
