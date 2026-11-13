<?php

namespace App\Mcp\Tools;

use App\Mcp\Abilities;
use App\Models\SmartLink;
use App\Models\SmartLinkBlock;
use App\Models\User;
use App\Services\SmartLinkService;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;

#[Name('manage_smartlink')]
#[Description('SmartLink (microsite publico de enlaces) de una marca: una por marca. action: get (el microsite con sus bloques y su URL publica), save (lo crea o lo reemplaza entero: slug, title y los campos opcionales bio, avatar_media_id, theme_color #rrggbb, theme_mode light|dark, published y blocks; los bloques con id conservan sus clics y los que no llegan se borran) o stats (visitas y clics diarios de los ultimos days, 1-365, por defecto 30; solo contadores agregados, sin datos personales). get/stats requieren smartlinks:read; save, smartlinks:write.')]
class ManageSmartLink extends SchedulerTool
{
    protected function ability(): string
    {
        return Abilities::SMARTLINKS_READ;
    }

    protected function abilityFor(Request $request): string
    {
        return $request->get('action') === 'save' ? Abilities::SMARTLINKS_WRITE : Abilities::SMARTLINKS_READ;
    }

    protected function resource(): ?string
    {
        return 'smartlink';
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'action' => $schema->string()->enum(['get', 'save', 'stats'])->required(),
            ...$this->brandSchema($schema, 'Marca (obligatoria)'),
            'slug' => $schema->string()->description('save: de 3 a 40 caracteres, minusculas, numeros y guiones; unico en toda la instalacion'),
            'title' => $schema->string()->description('save: titulo (hasta 100 caracteres)'),
            'bio' => $schema->string()->description('save: descripcion (hasta 300 caracteres)'),
            'avatar_media_id' => $schema->integer()->description('save: id de una imagen propia de list_media'),
            'theme_color' => $schema->string()->description('save: color #rrggbb'),
            'theme_mode' => $schema->string()->enum(['light', 'dark']),
            'published' => $schema->boolean()->description('save: visible al publico (por defecto true)'),
            'blocks' => $schema->array()->items($schema->object([
                'id' => $schema->integer()->description('Id del bloque existente (conserva sus clics)'),
                'type' => $schema->string()->enum(SmartLinkBlock::TYPES)->required(),
                'label' => $schema->string(),
                'url' => $schema->string(),
                'media_id' => $schema->integer(),
                'data' => $schema->object()->description('Datos del tipo (items, cta, consent, count)'),
                'visible_from' => $schema->string()->description('Hora local de la marca AAAA-MM-DDTHH:MM'),
                'visible_until' => $schema->string()->description('Hora local de la marca AAAA-MM-DDTHH:MM'),
            ]))->description('save: hasta '.SmartLinkService::MAX_BLOCKS.' bloques, en orden'),
            'days' => $schema->integer()->min(1)->max(365)->description('stats: dias hacia atras (por defecto 30)'),
        ];
    }

    protected function run(Request $request, User $user): array
    {
        $action = $request->validate(['action' => ['required', 'in:get,save,stats']])['action'];
        $brand = $this->brandFrom($request, $user);
        $links = app(SmartLinkService::class);
        $current = SmartLink::withoutUserScope()->where('user_id', $user->id)->where('brand_id', $brand->id)->first();

        if ($action === 'save') {
            $data = $request->validate(SmartLinkService::rules($current?->id), SmartLinkService::MESSAGES);
            $link = $links->save($user, $brand, $data);

            return $links->present($link->load('blocks'), $brand);
        }

        $link = $current ?? throw new NotOwned('La marca no tiene SmartLink. Crealo con save.', 'smartlink', null, false);

        if ($action === 'stats') {
            return $links->stats($link, (int) ($request->validate(['days' => ['nullable', 'integer', 'min:1', 'max:365']])['days'] ?? 30));
        }

        return $links->present($link->load('blocks'), $brand);
    }
}
