<?php

namespace App\Mcp\Tools;

use App\Mcp\Abilities;
use App\Models\User;
use App\Services\ComposerPresetService;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[Name('get_composer_presets')]
#[Description('Interruptores que el compositor recuerda por marca y red (presets), solo lectura: {red: {interruptor: bool}} con los valores iniciales que usa la web al preparar una publicacion de esa marca (por ejemplo share_to_feed en Instagram o made_for_kids en YouTube). Solo los escribe el compositor web al guardar una publicacion; aqui no se modifican. Requiere posts:read.')]
#[IsReadOnly]
class GetComposerPresets extends SchedulerTool
{
    protected function ability(): string
    {
        return Abilities::POSTS_READ;
    }

    protected function resource(): ?string
    {
        return 'brand';
    }

    public function schema(JsonSchema $schema): array
    {
        return $this->brandSchema($schema, 'Marca (obligatoria)');
    }

    protected function run(Request $request, User $user): array
    {
        $brand = $this->brandFrom($request, $user);

        return ['brand_id' => $brand->id, 'brand_name' => $brand->name, 'presets' => (object) app(ComposerPresetService::class)->forBrand($user, $brand)];
    }
}
