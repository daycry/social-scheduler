<?php

namespace App\Mcp\Tools;

use App\Mcp\Abilities;
use App\Models\User;
use App\Services\PostWorkflowActions;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;

#[Name('batch_posts')]
#[Description('Accion masiva sobre hasta 100 publicaciones: schedule, reschedule (shift_days/shift_hours, admite negativos), cancel, delete, approve o reject (comment obligatorio). Cada elemento se autoriza y se ejecuta aparte y devuelve ok o error sin parar el lote. idempotency_key repite la misma respuesta sin volver a ejecutar.')]
class BatchPosts extends SchedulerTool
{
    protected function ability(): string
    {
        return Abilities::POSTS_WRITE;
    }

    protected function abilityFor(Request $request): string
    {
        return in_array($request->get('action'), ['approve', 'reject'], true) ? Abilities::POSTS_APPROVE : Abilities::POSTS_WRITE;
    }

    protected function resource(): ?string
    {
        return 'post';
    }

    public function schema(JsonSchema $schema): array
    {
        return ['action' => $schema->string()->enum(['schedule', 'reschedule', 'cancel', 'delete', 'approve', 'reject'])->required(), 'post_ids' => $schema->array()->items($schema->integer())->description('IDs de publicaciones (maximo 100)')->required(), 'shift_days' => $schema->integer()->description('reschedule: dias a desplazar'), 'shift_hours' => $schema->integer()->description('reschedule: horas a desplazar'), 'comment' => $schema->string()->description('reject: motivo'), 'signatures' => $schema->object()->description('approve/reject: mapa id => content_signature revisada de cada publicacion'), 'idempotency_key' => $schema->string()->description('Clave de idempotencia (hasta 120 caracteres: letras, numeros . _ : -)')];
    }

    protected function run(Request $request, User $user): array
    {
        $actions = app(PostWorkflowActions::class);

        $key = $request->get('idempotency_key');
        $run = $actions->batch($user, $request->all(), is_string($key) ? trim($key) : null);

        return $run['result'] + ['replayed' => $run['replayed']];
    }
}
