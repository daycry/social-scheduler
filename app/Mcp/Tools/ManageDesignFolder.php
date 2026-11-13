<?php

namespace App\Mcp\Tools;

use App\Mcp\Abilities;
use App\Models\Studio\Design;
use App\Models\Studio\DesignFolder;
use App\Models\User;
use App\Services\Studio\StudioService;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;

#[Name('manage_design_folder')]
#[Description('Carpetas de una marca: list requiere designs:read; create(name), rename(folder_id, name), move(design_id, folder_id; sin folder_id pasa a «Sin carpeta») y delete(folder_id) requieren designs:write. Eliminar carpeta conserva los diseños activos/archivados y sus revisiones: pasan a «Sin carpeta». Usa su id como folder_id en create_design/update_design o list_designs.')]
class ManageDesignFolder extends SchedulerTool
{
    protected function ability(): string
    {
        return Abilities::DESIGNS_WRITE;
    }

    protected function abilityFor(Request $request): string
    {
        return $request->get('action') === 'list' ? Abilities::DESIGNS_READ : Abilities::DESIGNS_WRITE;
    }

    protected function resource(): ?string
    {
        return 'brand';
    }

    public function schema(JsonSchema $schema): array
    {
        return $this->brandSchema($schema) + ['action' => $schema->string()->enum(['list', 'create', 'rename', 'move', 'delete'])->required(),
            'name' => $schema->string()->description('Obligatorio para create, hasta 255 caracteres'),
            'folder_id' => $schema->integer()->min(1)->description('Obligatorio para rename y delete; destino de move (vacio = Sin carpeta); debe pertenecer a esta marca'),
            'design_id' => $schema->integer()->min(1)->description('Obligatorio para move; debe ser un diseño propio de esta marca')];
    }

    protected function run(Request $request, User $user): array
    {
        $data = $request->validate(['action' => ['required', 'in:list,create,rename,move,delete'],
            'name' => ['required_if:action,create,rename', 'nullable', 'string', 'max:255'],
            'folder_id' => ['required_if:action,rename,delete', 'nullable', 'integer', 'min:1'],
            'design_id' => ['required_if:action,move', 'nullable', 'integer', 'min:1']]);
        $brand = $this->brandFrom($request, $user);
        $studio = app(StudioService::class);
        if ($data['action'] === 'list') {
            return ['folders' => $studio->folders($user, $brand)->toArray()];
        }
        if ($data['action'] === 'create') {
            return $studio->createFolder($user, $brand, $data['name'])->toArray();
        }
        if ($data['action'] === 'move') {
            $design = Design::withoutUserScope()->where('user_id', $user->id)->where('brand_id', $brand->id)->findOrFail($data['design_id']);
            $moved = $studio->moveDesign($user, $design, $data['folder_id'] ?? null);

            return ['design_id' => $moved->id, 'folder_id' => $moved->folder_id];
        }
        $folder = DesignFolder::withoutUserScope()->where('user_id', $user->id)->where('brand_id', $brand->id)->findOrFail($data['folder_id']);
        if ($data['action'] === 'rename') {
            return $studio->renameFolder($user, $folder, $data['name'])->toArray();
        }

        return ['id' => $folder->id, 'deleted' => true, 'designs_moved' => $studio->deleteFolder($user, $folder)];
    }
}
