<?php

namespace App\Mcp\Tools;

use App\Mcp\Abilities;
use App\Models\Studio\StudioImageJob;
use App\Models\User;
use App\Services\Studio\StudioImages;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;

#[Name('process_studio_image')]
#[Description('Elimina el fondo de una imagen privada de una marca conservando el original. start requiere designs:write, media_id y operation=remove_background; devuelve un trabajo asíncrono. get requiere designs:read y job_id; cuando está ready, output_asset contiene un nuevo PNG transparente. Nunca acepta rutas ni modelos del cliente.')]
class ProcessStudioImage extends SchedulerTool
{
    protected function ability(): string
    {
        return Abilities::DESIGNS_WRITE;
    }

    protected function abilityFor(Request $request): string
    {
        return $request->get('action') === 'get' ? Abilities::DESIGNS_READ : Abilities::DESIGNS_WRITE;
    }

    protected function resource(): ?string
    {
        return 'brand';
    }

    public function schema(JsonSchema $schema): array
    {
        return $this->brandSchema($schema) + [
            'action' => $schema->string()->enum(['start', 'get'])->required(),
            'media_id' => $schema->integer()->min(1)->description('Imagen de esta marca, obligatoria para start'),
            'operation' => $schema->string()->enum(['remove_background'])->description('Obligatoria para start'),
            'job_id' => $schema->integer()->min(1)->description('Trabajo de esta marca, obligatorio para get'),
        ];
    }

    protected function run(Request $request, User $user): array
    {
        $data = $request->validate([
            'action' => ['required', 'in:start,get'],
            'media_id' => ['required_if:action,start', 'nullable', 'integer', 'min:1'],
            'operation' => ['required_if:action,start', 'nullable', 'in:remove_background'],
            'job_id' => ['required_if:action,get', 'nullable', 'integer', 'min:1'],
        ]);
        $brand = $this->brandFrom($request, $user);
        $images = app(StudioImages::class);
        $job = $data['action'] === 'start' ? $images->create($user, $brand, $data)
            : StudioImageJob::withoutUserScope()->where('user_id', $user->id)->where('brand_id', $brand->id)->findOrFail($data['job_id']);

        return ['job' => $images->present($user, $brand, $job, true)];
    }
}
