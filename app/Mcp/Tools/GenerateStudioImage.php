<?php

namespace App\Mcp\Tools;

use App\Mcp\Abilities;
use App\Models\User;
use App\Services\Studio\StudioAi;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;

#[Name('generate_studio_image')]
#[Description('IA creativa del estudio (T-14). Requiere la ability studio:ai y consume la cuota diaria del proveedor. action=status lista los proveedores configurados (OpenAI Images, Google), sus operaciones y la cuota restante; si ninguno esta configurado no hay funcion de IA. action=start encola un trabajo asincrono: kind generate (prompt, aspect, style, count 1-4), edit (media_id propio + prompt + mask {x,y,width,height} en fracciones 0-1; solo proveedores con mascara), outpaint (media_id + aspect de destino) o variation (media_id). Admite idempotency_key. action=get consulta el trabajo por job_id: queued, processing, ready o failed con error_code y mensaje en español; en ready, output_assets son recursos nuevos de la marca (con proveedor, modelo y prompt). El original no se modifica. Nunca recibe claves ni URLs.')]
class GenerateStudioImage extends SchedulerTool
{
    protected function ability(): string
    {
        return Abilities::STUDIO_AI;
    }

    protected function resource(): ?string
    {
        return 'brand';
    }

    public function schema(JsonSchema $schema): array
    {
        return $this->brandSchema($schema) + [
            'action' => $schema->string()->enum(['status', 'start', 'get'])->required(),
            'kind' => $schema->string()->enum(StudioAi::KINDS)->description('Obligatorio para start'),
            'provider' => $schema->string()->enum(['openai', 'google'])->description('Obligatorio para start; debe estar configurado'),
            'prompt' => $schema->string()->description('Descripcion (obligatoria en generate y edit)'),
            'aspect' => $schema->string()->enum(StudioAi::ASPECTS)->description('Proporcion; en outpaint, la de destino'),
            'style' => $schema->string()->enum(array_keys(StudioAi::STYLES)),
            'count' => $schema->integer()->min(1)->max(4)->description('Variantes (1-4)'),
            'media_id' => $schema->integer()->min(1)->description('Imagen de esta marca (edit, outpaint, variation)'),
            'mask' => $schema->object()->description('Zona a editar {x,y,width,height} en fracciones 0-1 de la imagen'),
            'idempotency_key' => $schema->string()->description('8-64 caracteres [A-Za-z0-9_-]; repetir la peticion devuelve el mismo trabajo'),
            'job_id' => $schema->integer()->min(1)->description('Trabajo de esta marca, obligatorio para get'),
        ];
    }

    protected function run(Request $request, User $user): array
    {
        $request->validate(['action' => ['required', 'in:status,start,get'], 'job_id' => ['required_if:action,get', 'nullable', 'integer', 'min:1']]);
        $brand = $this->brandFrom($request, $user);
        $ai = app(StudioAi::class);
        $action = (string) $request->get('action');
        if ($action === 'status') {
            return $ai->status($user);
        }
        if ($action === 'get') {
            return ['job' => $ai->present($user, $brand, $ai->find($user, $brand, (int) $request->get('job_id')), true)];
        }
        $data = array_filter($request->only(['kind', 'provider', 'prompt', 'aspect', 'style', 'count', 'media_id', 'mask', 'idempotency_key']), fn ($v) => $v !== null);

        return ['job' => $ai->present($user, $brand, $ai->create($user, $brand, $data), true)];
    }
}
