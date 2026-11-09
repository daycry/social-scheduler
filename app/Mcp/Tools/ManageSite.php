<?php

namespace App\Mcp\Tools;

use App\Mcp\Abilities;
use App\Models\Site;
use App\Models\User;
use App\Services\Sites\SiteService;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use InvalidArgumentException;
use Laravel\Mcp\Request;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;

#[Name('manage_site')]
#[Description('Sitios publicados de una marca (T-15). action: list (de la marca, o todos si no indicas marca; id, slug, titulo, estado publicado, modo de acceso y URL), get_url (URL publica de site_id y si esta accesible), publish (copia el borrador a la version publicada y la hace visible) o unpublish (la retira al instante; la URL pasa a 404). El contenido se edita en la app; aqui no se escribe contenido ni se cambia el acceso. Una URL con token de acceso nunca se devuelve: los tokens solo se muestran al crearlos en la app. list/get_url requieren sites:read; publish/unpublish, sites:write.')]
class ManageSite extends SchedulerTool
{
    protected function ability(): string
    {
        return Abilities::SITES_READ;
    }

    protected function abilityFor(Request $request): string
    {
        return in_array($request->get('action'), ['publish', 'unpublish'], true) ? Abilities::SITES_WRITE : Abilities::SITES_READ;
    }

    protected function resource(): ?string
    {
        return 'site';
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'action' => $schema->string()->description('list | get_url | publish | unpublish')->required(),
            'site_id' => $schema->integer()->description('ID del sitio (get_url, publish, unpublish)'),
            'brand_id' => $schema->integer()->description('Filtra list por marca'),
        ];
    }

    protected function run(Request $request, User $user): array
    {
        $action = (string) $request->get('action');
        if (! in_array($action, ['list', 'get_url', 'publish', 'unpublish'], true)) {
            throw new InvalidArgumentException('action debe ser list, get_url, publish o unpublish.');
        }
        $sites = app(SiteService::class);

        if ($action === 'list') {
            $query = Site::withoutUserScope()->where('user_id', $user->id)->orderBy('id');
            if (filled($request->get('brand_id'))) {
                $brand = $this->owned(\App\Models\Brand::class, $user, $request->get('brand_id'), 'Marca');
                $query->where('brand_id', $brand->id);
            }

            return ['sites' => $query->get()->map(fn (Site $s) => $this->present($s))->all()];
        }

        /** @var Site $site */
        $site = $this->owned(Site::class, $user, $request->get('site_id'), 'Sitio');
        if ($action === 'publish') {
            $site = $sites->publish($user, $site);
        } elseif ($action === 'unpublish') {
            $site = $sites->unpublish($user, $site);
        }

        return $this->present($site) + ['id' => $site->id];
    }

    /** @return array<string, mixed> */
    private function present(Site $s): array
    {
        return [
            'id' => $s->id, 'brand_id' => $s->brand_id, 'slug' => $s->slug, 'title' => $s->title, 'published' => $s->is_published,
            'access_mode' => $s->access_mode, 'noindex' => $s->noindex, 'url' => $s->url(),
            'accessible' => $s->is_published && $s->access_mode === Site::ACCESS_PUBLIC,
            'published_at' => $s->published_at?->toIso8601String(), 'has_unpublished_changes' => $s->is_published && $s->published_revision !== $s->revision,
        ];
    }
}
