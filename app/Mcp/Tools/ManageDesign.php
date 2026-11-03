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

#[Name('manage_design')]
#[Description('Gestiona diseño propio: duplicate crea copia independiente con revisión 1 (name opcional); archive lo archiva sin borrar snapshots/exportaciones; restore restaura un archivado. No guarda documentos ni publica. Requiere designs:write.')]
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
        return ['action' => $schema->string()->enum(['duplicate', 'archive', 'restore'])->required(),
            'design_id' => $schema->integer()->min(1)->required(), 'name' => $schema->string()->description('Nombre de la copia, solo para duplicate')];
    }

    protected function run(Request $request, User $user): array
    {
        $data = $request->validate(['action' => ['required', 'in:duplicate,archive,restore'], 'design_id' => ['required', 'integer', 'min:1'], 'name' => ['nullable', 'string', 'max:255']]);
        $design = $data['action'] === 'restore'
            ? Design::withoutUserScope()->withTrashed()->where('user_id', $user->id)->findOrFail($data['design_id'])
            : $this->owned(Design::class, $user, $data['design_id'], 'Diseño');
        $studio = app(StudioService::class);
        if ($data['action'] === 'archive') {
            $studio->archive($user, $design);

            return ['id' => $design->id, 'archived' => true];
        }

        return $studio->present($user, $data['action'] === 'restore' ? $studio->restore($user, $design)
            : $studio->duplicate($user, $design, $data['name'] ?? null));
    }
}
