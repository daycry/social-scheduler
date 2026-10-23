<?php

namespace App\Mcp\Tools;

use App\Mcp\Abilities;
use App\Models\Brand;
use App\Models\User;
use App\Services\PostService;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;

#[Name('create_post')]
#[Description('Crea una publicacion en una marca: borrador (schedule=false) o programada (schedule=true, fecha futura). Se valida igual que en la web (limites y media por red).')]
class CreatePost extends SchedulerTool
{
    use PostPayload;

    protected function ability(): string
    {
        return Abilities::POSTS_WRITE;
    }

    protected function resource(): ?string
    {
        return 'post';
    }

    public function schema(JsonSchema $schema): array
    {
        return ['brand_id' => $schema->integer()->description('ID de la marca')->required()] + $this->payloadSchema($schema);
    }

    protected function run(Request $request, User $user): array
    {
        $brand = $this->owned(Brand::class, $user, $request->get('brand_id'), 'Marca');
        $data = $this->validatedPayload($request, ['brand_id' => ['required', 'integer']]);
        unset($data['brand_id']);

        return $this->presentPost(app(PostService::class)->create($user, $brand, $data));
    }
}
