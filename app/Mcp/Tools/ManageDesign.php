<?php

namespace App\Mcp\Tools;

use App\Mcp\Abilities;
use App\Models\Studio\Design;
use App\Models\User;
use App\Services\Studio\StudioAutomation;
use App\Services\Studio\StudioService;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;

#[Name('manage_design')]
#[Description('Gestiona diseño propio. duplicate/archive/restore conservan el contrato previo. template guarda copia plantilla; instantiate crea diseño independiente de plantilla con fields literales {{key}}; resize cambia tamaño; apply-kit aplica identidad de marca; variants crea hasta 12 copias transaccionales. Estas cinco acciones exigen revision actual de get_design y devuelven {design} o {designs}; copia independiente con IDs nuevos y revisión 1. modes canvas/contain/cover (contain por defecto), dimensiones 40–8000. Campos: todas y solo las claves template_fields, valores texto hasta 10000 caracteres, sin interpolación recursiva. apply_brand_kit opcional para instantiate/variants; usa kit de la misma marca. Lotes máximo 8 MiB/20000 elementos. No publica. Requiere designs:write.')]
class ManageDesign extends SchedulerTool
{
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
        return ['action' => $schema->string()->enum(['duplicate', 'archive', 'restore', 'template', 'instantiate', 'resize', 'variants', 'apply-kit'])->required(),
            'design_id' => $schema->integer()->min(1)->required(), 'name' => $schema->string()->description('Nombre opcional para duplicate/template/instantiate'),
            'revision' => $schema->integer()->min(1)->description('Obligatoria salvo duplicate/archive/restore'),
            'fields' => $schema->object()->description('Valores literales por cada clave de template_fields; valores vacíos/espacios preservados'),
            'apply_brand_kit' => $schema->boolean(), 'width' => $schema->integer()->min(40)->max(8000), 'height' => $schema->integer()->min(40)->max(8000),
            'mode' => $schema->string()->enum(['canvas', 'contain', 'cover']),
            'variants' => $schema->array()->items($schema->object(['name' => $schema->string(), 'width' => $schema->integer()->min(40)->max(8000)->required(),
                'height' => $schema->integer()->min(40)->max(8000)->required(), 'mode' => $schema->string()->enum(['canvas', 'contain', 'cover'])]))];
    }

    protected function run(Request $request, User $user): array
    {
        $data = $request->validate(['action' => ['required', 'in:duplicate,archive,restore,template,instantiate,resize,variants,apply-kit'], 'design_id' => ['required', 'integer', 'min:1'], 'name' => ['nullable', 'string', 'max:255']]);
        $design = $data['action'] === 'restore'
            ? Design::withoutUserScope()->withTrashed()->where('user_id', $user->id)->findOrFail($data['design_id'])
            : $this->owned(Design::class, $user, $data['design_id'], 'Diseño');
        $studio = app(StudioService::class);
        if (in_array($data['action'], ['template', 'instantiate', 'resize', 'variants', 'apply-kit'], true)) {
            $payload = $request->all();
            unset($payload['action'], $payload['design_id']);

            return app(StudioAutomation::class)->perform($user, $design, $data['action'], $payload, true);
        }
        if ($data['action'] === 'archive') {
            $studio->archive($user, $design);

            return ['id' => $design->id, 'archived' => true];
        }

        return $studio->present($user, $data['action'] === 'restore' ? $studio->restore($user, $design)
            : $studio->duplicate($user, $design, $data['name'] ?? null), true);
    }
}
