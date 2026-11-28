<?php

namespace App\Mcp\Tools;

use App\Mcp\Abilities;
use App\Models\User;
use App\Services\PostWorkflowActions;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;

#[Name('manage_post_series')]
#[Description('Series recurrentes: list, get(series_id), create(brand_id + frequency diaria|semanal|mensual, weekdays 1-7 si semanal, time_local HH:MM en la zona de la marca, starts_on, ends_on o max_occurrences, text, media, targets), update(series_id, mismos campos; cambia las ocurrencias futuras sin editar) y cancel(series_id; borra las futuras pendientes) y recover(series_id; opcional y explicito: rehace las fechas perdidas mientras la serie fallaba, solo las recientes). requires_approval=true hace que cada ocurrencia nazca como borrador pendiente de aprobacion. Las ocurrencias son publicaciones normales: editar una (update_post) la saca de la serie.')]
class ManagePostSeries extends SchedulerTool
{
    protected function ability(): string
    {
        return Abilities::POSTS_WRITE;
    }

    protected function abilityFor(Request $request): string
    {
        return in_array($request->get('action'), ['list', 'get'], true) ? Abilities::POSTS_READ : Abilities::POSTS_WRITE;
    }

    protected function resource(): ?string
    {
        return 'post';
    }

    public function schema(JsonSchema $schema): array
    {
        return ['action' => $schema->string()->enum(['list', 'get', 'create', 'update', 'cancel', 'recover'])->required(), 'series_id' => $schema->integer()->min(1), 'brand_id' => $schema->integer()->min(1), 'frequency' => $schema->string()->enum(['diaria', 'semanal', 'mensual']), 'weekdays' => $schema->array()->items($schema->integer()), 'time_local' => $schema->string()->description('HH:MM'), 'starts_on' => $schema->string()->description('YYYY-MM-DD'), 'ends_on' => $schema->string()->nullable(), 'max_occurrences' => $schema->integer()->nullable(), 'text' => $schema->string(), 'media' => $schema->array()->items($schema->integer()), 'targets' => $schema->array()->items($schema->object()), 'requires_approval' => $schema->boolean()];
    }

    protected function run(Request $request, User $user): array
    {
        $actions = app(PostWorkflowActions::class);

        $action = (string) $request->get('action');
        $input = $request->all();
        $id = (int) $request->get('series_id');

        return match ($action) {
            'list' => $actions->seriesIndex($user),
            'get' => $actions->seriesShow($user, $id),
            'create' => $actions->seriesStore($user, $input),
            'update' => $actions->seriesUpdate($user, $id, $input),
            'cancel' => $actions->seriesCancel($user, $id),
            'recover' => $actions->seriesRecover($user, $id),
            default => throw new \InvalidArgumentException('action debe ser list, get, create, update, cancel o recover.'),
        };
    }
}
