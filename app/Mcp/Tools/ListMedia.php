<?php

namespace App\Mcp\Tools;

use App\Mcp\Abilities;
use App\Models\MediaAsset;
use App\Models\User;
use Laravel\Mcp\Request;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[Name('list_media')]
#[Description('Lista la media ya subida y validada del usuario (ultimos 100). Se usa por id en create_post/update_post; el agente no sube binarios por MCP.')]
#[IsReadOnly]
class ListMedia extends SchedulerTool
{
    protected function ability(): string
    {
        return Abilities::BRANDS_READ;
    }

    protected function resource(): ?string
    {
        return 'media';
    }

    protected function run(Request $request, User $user): array
    {
        return ['media' => MediaAsset::withoutUserScope()->where('user_id', $user->id)
            ->where('status', MediaAsset::STATUS_READY)->latest('id')->limit(100)->get()
            ->map->toPublicArray()->values()->all()];
    }
}
