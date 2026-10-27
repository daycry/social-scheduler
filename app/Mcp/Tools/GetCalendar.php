<?php

namespace App\Mcp\Tools;

use App\Connectors\Network;
use App\Mcp\Abilities;
use App\Models\CalendarView;
use App\Models\User;
use App\Services\CalendarService;
use App\Services\CalendarViewService;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use InvalidArgumentException;
use Laravel\Mcp\Request;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[Name('get_calendar')]
#[Description('Calendario de publicaciones (semana, mes o lista) filtrable por marca, red y estado. view acepta week|month|list o el id de una vista guardada (list_calendar_views): con el id se aplican sus filtros (marca, red, estado y vista) y mandan sobre los demas filtros; la fecha se conserva.')]
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
            'view' => $schema->string()->description('week (por defecto), month o list; o el id de una vista guardada (list_calendar_views)'),
            'date' => $schema->string()->description('Fecha ancla YYYY-MM-DD (por defecto hoy)'),
            ...$this->brandSchema($schema, 'Filtrar por marca'),
            'network' => $schema->string()->description(Network::listing()),
            'status' => $schema->string()->description('Filtrar por estado (borrador, programada, publicada, fallida...)'),
        ];
    }

    protected function run(Request $request, User $user): array
    {
        $filters = $request->all(['view', 'date', 'network', 'status']);
        $view = $filters['view'] ?? null;
        $saved = null;

        if (is_numeric($view)) {
            /** @var CalendarView $saved */
            $saved = $this->owned(CalendarView::class, $user, $view, 'Vista');
            $filters['view'] = null;
        } elseif ($view !== null && $view !== '' && ! in_array($view, CalendarService::VIEWS, true)) {
            throw new InvalidArgumentException('view debe ser week, month, list o el id de una vista guardada (list_calendar_views).');
        }

        // El servicio solo conoce las marcas del usuario: una ajena responde como inexistente.
        $filters['brand_id'] = $this->brandFrom($request, $user, required: false)?->id;

        if ($saved) {
            $filters = array_merge(array_diff_key($filters, array_flip(['network', 'status', 'view'])), ['brand_id' => null], app(CalendarViewService::class)->filtersFor($user, $saved));
        }

        return app(CalendarService::class)->build($user, $filters);
    }
}
