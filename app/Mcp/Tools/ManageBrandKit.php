<?php

namespace App\Mcp\Tools;

use App\Mcp\Abilities;
use App\Models\User;
use App\Services\Studio\StudioService;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;

#[Name('manage_brand_kit')]
#[Description('Consulta o actualiza kit de una marca: palette hexadecimal, fonts familias sin CSS/URL, logos IDs propios registrados, guidelines y voice. kit omitido consulta; campos omitidos se conservan. Requiere designs:write; get_design también incluye el kit con designs:read.')]
class ManageBrandKit extends SchedulerTool
{
    protected function ability(): string
    {
        return Abilities::DESIGNS_WRITE;
    }

    protected function resource(): ?string
    {
        return 'brand';
    }

    public function schema(JsonSchema $schema): array
    {
        return $this->brandSchema($schema) + ['kit' => $schema->object(['palette' => $schema->array()->items($schema->string()),
            'fonts' => $schema->array()->items($schema->string()), 'font_ids' => $schema->array()->items($schema->integer()->nullable()), 'logos' => $schema->array()->items($schema->integer()),
            'guidelines' => $schema->string(), 'voice' => $schema->string()])];
    }

    protected function run(Request $request, User $user): array
    {
        $brand = $this->brandFrom($request, $user);
        $data = $request->validate(['kit' => ['sometimes', 'array']]);
        $studio = app(StudioService::class);

        return array_key_exists('kit', $data) ? $studio->updateKit($user, $brand, $data['kit']) : $studio->kit($user, $brand);
    }
}
