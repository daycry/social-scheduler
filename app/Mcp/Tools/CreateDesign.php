<?php

namespace App\Mcp\Tools;

use App\Mcp\Abilities;
use App\Models\User;
use App\Services\Studio\StudioService;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;

#[Name('create_design')]
#[Description('Crea diseño nativo en una marca y snapshot revisión 1. Mismas validaciones del editor; no publica. Para recursos usa IDs propios list_media, registrados en la marca mediante API design-assets. Requiere designs:write.')]
class CreateDesign extends SchedulerTool
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
        return $this->brandSchema($schema) + $this->designPayloadSchema($schema);
    }

    protected function run(Request $request, User $user): array
    {
        $studio = app(StudioService::class);

        return $studio->present($user, $studio->create($user, $this->brandFrom($request, $user), $request->all()), true);
    }
}
