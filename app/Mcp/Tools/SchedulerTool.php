<?php

namespace App\Mcp\Tools;

use App\Exceptions\InvalidPostTransition;
use App\Exceptions\PostValidationException;
use App\Models\Post;
use App\Models\PostTarget;
use App\Models\User;
use App\Services\AuditLog;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;
use Throwable;

/**
 * Base de las herramientas: identifica al usuario de la key, exige la ability, ejecuta sobre los servicios
 * con ese usuario explicito, traduce los errores de dominio a mensajes legibles y deja la auditoria.
 * Un recurso ajeno responde igual que uno inexistente (sin filtrar su existencia) y queda registrado.
 */
abstract class SchedulerTool extends Tool
{
    /** Ability de Sanctum que exige la herramienta. */
    abstract protected function ability(): string;

    /** Recurso sobre el que actua (para la auditoria). */
    protected function resource(): ?string
    {
        return null;
    }

    /**
     * @return array<string, mixed>
     */
    abstract protected function run(Request $request, User $user): array;

    public function handle(Request $request): Response
    {
        $audit = app(AuditLog::class);
        $user = $request->user();
        $action = $this->name();
        $resourceId = $request->get('post_id') ?? $request->get('brand_id');

        if (! $user instanceof User) {
            return Response::error('No autenticado.');
        }

        if (! $user->tokenCan($this->ability())) {
            $audit->record($user, $action, AuditLog::DENIED, $this->resource(), $resourceId, 'Falta el permiso '.$this->ability());

            return Response::error("Esta API key no tiene el permiso «{$this->ability()}».");
        }

        try {
            $result = $this->run($request, $user);
        } catch (NotOwned $e) {
            $audit->record($user, $action, AuditLog::DENIED, $e->resource, $e->id, $e->crossUser ? 'Recurso de otro usuario' : 'Recurso inexistente');

            return Response::error($e->getMessage());
        } catch (ValidationException $e) {
            $audit->record($user, $action, AuditLog::ERROR, $this->resource(), $resourceId, 'Validacion');

            return Response::error('Datos no validos: '.collect($e->errors())->map(fn ($m, $k) => "$k: ".implode(' ', $m))->implode(' | '));
        } catch (PostValidationException $e) {
            $audit->record($user, $action, AuditLog::ERROR, $this->resource(), $resourceId, 'Validacion');

            return Response::error('Datos no validos: '.collect($e->errors())->map(fn ($m, $k) => "$k: ".implode(' ', $m))->implode(' | '));
        } catch (InvalidPostTransition|InvalidArgumentException $e) {
            $audit->record($user, $action, AuditLog::ERROR, $this->resource(), $resourceId, $e->getMessage());

            return Response::error($e->getMessage());
        } catch (Throwable $e) {
            // Fallo inesperado (BD, lock...): se audita como error sin volcar datos y el cliente recibe un mensaje generico.
            $audit->record($user, $action, AuditLog::ERROR, $this->resource(), $resourceId, 'Error interno: '.class_basename($e));
            report($e);

            return Response::error('Error interno al ejecutar la herramienta. Intentalo de nuevo.');
        }

        $audit->record($user, $action, AuditLog::OK, $this->resource(), $result['id'] ?? $resourceId);

        return Response::json($result);
    }

    /**
     * Busca un recurso SOLO del usuario de la key; si no existe o es de otro, lanza el mismo error.
     *
     * @param  class-string<Model>  $model
     */
    protected function owned(string $model, User $user, mixed $id, string $label): Model
    {
        $id = is_numeric($id) ? (int) $id : null;
        $found = $id === null ? null : $model::withoutUserScope()->where('user_id', $user->id)->find($id);

        if ($found !== null) {
            return $found;
        }

        $crossUser = $id !== null && $model::withoutUserScope()->whereKey($id)->exists();

        throw new NotOwned("$label no encontrada.", strtolower(class_basename($model)), $id, $crossUser);
    }

    /** @return array<string, mixed> */
    protected function presentPost(Post $post): array
    {
        $post->loadMissing(['brand', 'targets', 'media']);
        $tz = $post->brand?->timezone ?: 'UTC';
        $targets = $post->targets;

        return [
            'id' => $post->id,
            'brand_id' => $post->brand_id,
            'text' => $post->text,
            'status' => $post->status->value,
            'scheduled_at_utc' => $post->scheduled_at?->copy()->utc()->toIso8601String(),
            'scheduled_at_local' => $post->scheduled_at?->copy()->setTimezone($tz)->format('Y-m-d\TH:i'),
            'timezone' => $tz,
            'editable' => ! $targets->contains(fn (PostTarget $t) => $t->status->isInFlight() || $t->status->isFinal()),
            'media_ids' => $post->media->pluck('id')->values()->all(),
            'targets' => $targets->map(fn (PostTarget $t) => [
                'account_id' => $t->social_account_id,
                'network' => $t->network->value,
                'content_type' => $t->content_type->value,
                'status' => $t->status->value,
                'error_message' => $t->error_message,
                'status_note' => $t->status_note,
                'external_url' => $t->external_url,
            ])->values()->all(),
        ];
    }
}
