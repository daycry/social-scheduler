<?php

namespace App\Mcp\Tools;

use App\Mcp\Abilities;
use App\Models\Studio\Design;
use App\Models\User;
use App\Services\Studio\StudioService;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;

#[Name('update_design')]
#[Description('Guarda nueva revisión del diseño. revision debe coincidir con la versión actual de get_design; 409 exige recargar y resolver cambios antes de reintentar. Conserva propiedades del documento. No publica. Requiere designs:write.')]
class UpdateDesign extends SchedulerTool
{
    use StudioPayload;

    protected function ability(): string
    {
        return Abilities::DESIGNS_WRITE;
    }

    protected function resource(): ?string
    {
        return 'design';
    }

    public function schema(JsonSchema $schema): array
    {
        return ['design_id' => $schema->integer()->required(), 'revision' => $schema->integer()->min(1)->required(),
            'kit_revision' => $schema->integer()->min(1)->description('Opcional: restaurar la referencia del kit de una revisión existente del mismo diseño al deshacer, junto al documento')] + $this->designPayloadSchema($schema);
    }

    protected function run(Request $request, User $user): array
    {
        $data = $request->validate(['design_id' => ['required', 'integer', 'min:1']]);
        $studio = app(StudioService::class);

        return $studio->present($user, $studio->update($user, $this->owned(Design::class, $user, $data['design_id'], 'Diseño'), $request->all()));
    }
}
