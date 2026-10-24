<?php

namespace App\Mcp\Tools;

use App\Mcp\Abilities;
use App\Models\Brand;
use App\Models\Campaign;
use App\Models\User;
use App\Services\CampaignService;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[Name('list_campaigns')]
#[Description('Lista las campañas de una marca (id, nombre, color, fechas, si está archivada y cuántas publicaciones tiene), las activas primero. El id es el campaign_id de create_post y update_post.')]
#[IsReadOnly]
class ListCampaigns extends SchedulerTool
{
    protected function ability(): string
    {
        return Abilities::BRANDS_READ;
    }

    protected function resource(): ?string
    {
        return 'brand';
    }

    public function schema(JsonSchema $schema): array
    {
        return ['brand_id' => $schema->integer()->description('ID de la marca')->required()];
    }

    protected function run(Request $request, User $user): array
    {
        $brand = $this->owned(Brand::class, $user, $request->get('brand_id'), 'Marca');
        $service = app(CampaignService::class);

        return ['campaigns' => $service->listForBrand($user, $brand->id)
            ->map(fn (Campaign $c) => $service->toArray($c))->values()->all()];
    }
}
