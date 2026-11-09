<?php

namespace App\Mcp\Tools;

use App\Exceptions\InvalidPostTransition;
use App\Exceptions\PostValidationException;
use App\Models\Brand;
use App\Models\Post;
use App\Models\PostTarget;
use App\Models\User;
use App\Services\AuditLog;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
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

    /** Tools with read and write actions can require the exact scope for the requested operation. */
    protected function abilityFor(Request $request): string
    {
        return $this->ability();
    }

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
        $resourceId = $request->get('post_id') ?? $request->get('report_id') ?? $request->get('design_id') ?? $request->get('export_id') ?? $request->get('dataset_id') ?? $request->get('brand_id');

        if (! $user instanceof User) {
            return Response::error('No autenticado.');
        }

        $ability = $this->abilityFor($request);
        if (! $user->tokenCan($ability)) {
            $audit->record($user, $action, AuditLog::DENIED, $this->resource(), $resourceId, 'Falta el permiso '.$ability);

            return Response::error("Esta API key no tiene el permiso «{$ability}».");
        }

        try {
            $result = $this->run($request, $user);
        } catch (NotOwned $e) {
            $audit->record($user, $action, AuditLog::DENIED, $e->resource, $e->id, $e->crossUser ? 'Recurso de otro usuario' : 'Recurso inexistente');

            return Response::error($e->getMessage());
        } catch (ValidationException $e) {
            $audit->record($user, $action, AuditLog::ERROR, $this->resource(), $resourceId, 'Validacion');

            return Response::error('Datos no validos: '.collect($e->errors())->map(fn ($m, $k) => "$k: ".implode(' ', $m))->implode(' | '));
        } catch (ModelNotFoundException $e) {
            $audit->record($user, $action, AuditLog::DENIED, $this->resource(), $resourceId, 'Recurso inexistente o ajeno');

            return Response::error('404: Recurso no encontrado.');
        } catch (HttpExceptionInterface $e) {
            $status = $e->getStatusCode();
            $audit->record($user, $action, $status === 404 ? AuditLog::DENIED : AuditLog::ERROR, $this->resource(), $resourceId, 'HTTP '.$status);

            return Response::error(in_array($status, [403, 409], true) ? $status.': '.$e->getMessage() : ($status === 404 ? '404: Recurso no encontrado.' : ($status === 503 && $this->resource() === 'studio_dataset' ? '503: Dataset snapshot unavailable.' : 'No se pudo completar la operación.')));
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

        $audit->record($user, $action, AuditLog::OK, $this->resource(), $result['id'] ?? $result['dataset']['id'] ?? $resourceId);

        return $this->resultResponse($result);
    }

    protected function resultResponse(array $result): Response
    {
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

    /**
     * Marca del usuario por `brand_id` o por su nombre (`brand`, sin distinguir mayusculas ni espacios de los extremos):
     * quien usa el MCP no tiene por que conocer los ids. Un nombre repetido obliga a usar el id.
     */
    protected function brandFrom(Request $request, User $user, bool $required = true): ?Brand
    {
        if (filled($request->get('brand_id'))) {
            return $this->owned(Brand::class, $user, $request->get('brand_id'), 'Marca');
        }

        $name = trim((string) $request->get('brand'));
        if ($name === '') {
            if ($required) {
                throw new InvalidArgumentException('Indica la marca: brand (su nombre) o brand_id. Usa list_brands para verlas.');
            }

            return null;
        }

        $matches = Brand::withoutUserScope()->where('user_id', $user->id)->get()
            ->filter(fn (Brand $b) => mb_strtolower(trim($b->name)) === mb_strtolower($name))->values();

        if ($matches->count() > 1) {
            throw new InvalidArgumentException("Hay varias marcas llamadas «{$name}»: indica brand_id (".$matches->pluck('id')->implode(', ').').');
        }

        return $matches->first() ?? throw new NotOwned("Marca «{$name}» no encontrada. Usa list_brands para ver los nombres.", 'brand', null, false);
    }

    /**
     * Parametros de marca del esquema: el nombre o, si hay dos con el mismo, el id.
     *
     * @return array<string, mixed>
     */
    protected function brandSchema(JsonSchema $schema, string $purpose = 'Marca'): array
    {
        return [
            'brand' => $schema->string()->description("$purpose: su nombre, tal como sale en list_brands (alternativa a brand_id)"),
            'brand_id' => $schema->integer()->description("$purpose: su id (alternativa a brand; obligatorio si hay dos marcas con el mismo nombre)"),
        ];
    }

    /**
     * Nombre de cada marca del usuario por id, para acompanar los brand_id de las respuestas.
     *
     * @return array<int, string>
     */
    protected function brandNames(User $user): array
    {
        return Brand::withoutUserScope()->where('user_id', $user->id)->pluck('name', 'id')->all();
    }

    /** @return array<string, mixed> */
    protected function presentPost(Post $post): array
    {
        $post->loadMissing(['brand', 'campaign', 'targets', 'media']);
        $tz = $post->brand?->timezone ?: 'UTC';
        $targets = $post->targets;

        return [
            'id' => $post->id,
            'brand_id' => $post->brand_id,
            'brand_name' => $post->brand?->name,
            'text' => $post->text,
            'notes' => $post->notes, // internas: nunca se publican
            'campaign_id' => $post->campaign_id,
            'campaign' => $post->campaign?->name,
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
                // `text` null = vinculado a la plantilla; `effective_text` es lo que realmente se publica en este destino.
                'text' => $t->text,
                'effective_text' => $t->text ?? $post->text,
                // Primer comentario (C-01b): su estado es independiente del estado de publicacion del destino.
                'first_comment' => $t->first_comment,
                'first_comment_status' => $t->first_comment_status?->value,
                'first_comment_error' => $t->first_comment_error,
                'first_comment_may_exist' => $t->first_comment_sent_at !== null, // se intento enviar: puede existir en la red aunque el estado sea fallido
                'options' => $t->options ?? [],
                'status' => $t->status->value,
                'error_message' => $t->error_message,
                'status_note' => $t->status_note,
                'external_url' => $t->external_url,
            ])->values()->all(),
        ];
    }
}
