<?php

namespace App\Mcp\Tools;

use App\Mcp\Abilities;
use App\Models\Brand;
use App\Models\User;
use App\Services\BrandService;
use Laravel\Mcp\Request;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[Name('list_brands')]
#[Description('Lista las marcas del usuario (id, nombre y zona horaria).')]
#[IsReadOnly]
class ListBrands extends SchedulerTool
{
    protected function ability(): string
    {
        return Abilities::BRANDS_READ;
    }

    protected function resource(): ?string
    {
        return 'brand';
    }

    protected function run(Request $request, User $user): array
    {
        return ['brands' => app(BrandService::class)->list($user)
            ->map(fn (Brand $b) => ['id' => $b->id, 'name' => $b->name, 'description' => $b->description, 'timezone' => $b->timezone])
            ->values()->all()];
    }
}
