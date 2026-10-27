<?php

namespace App\Mcp\Tools;

use App\Connectors\Network;
use App\Enums\ContentType;
use App\Mcp\Abilities;
use App\Models\SocialAccount;
use App\Models\User;
use App\Services\SocialAccountService;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[Name('list_accounts')]
#[Description('Lista las cuentas conectadas de una marca y los tipos de contenido (content_type) que admite cada una. Nunca expone tokens de las redes.')]
#[IsReadOnly]
class ListAccounts extends SchedulerTool
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
        return $this->brandSchema($schema, 'Marca (obligatoria)');
    }

    protected function run(Request $request, User $user): array
    {
        $brand = $this->brandFrom($request, $user);

        return ['brand_id' => $brand->id, 'brand_name' => $brand->name, 'accounts' => app(SocialAccountService::class)->listForBrand($user, $brand)
            ->map(fn (SocialAccount $a) => [
                'id' => $a->id,
                'network' => $a->network->value,
                'name' => $a->name,
                'username' => $a->username,
                'status' => $a->status,
                // false = red apagada en esta instalacion (BLUESKY_ENABLED=false): sin tipos de contenido y create_post la rechaza.
                'available' => ($a->network instanceof Network ? $a->network : Network::from($a->network))->isAvailable(),
                'content_types' => array_values(array_map(
                    fn (ContentType $t) => $t->value,
                    array_filter(ContentType::forNetwork($a->network instanceof Network ? $a->network : Network::from($a->network)), fn (ContentType $t) => $t->isOffered()),
                )),
            ])->values()->all()];
    }
}
