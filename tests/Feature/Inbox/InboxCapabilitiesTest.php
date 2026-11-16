<?php

use App\Connectors\ConnectorRegistry;
use App\Connectors\Contracts\CommentSource;
use App\Connectors\Contracts\HidesComments;
use App\Connectors\Network;
use App\Mcp\Tools\ListComments;
use App\Mcp\Tools\TriageComments;
use App\Models\Brand;
use App\Models\SocialAccount;
use App\Models\User;
use App\Services\InboxService;
use Tests\Support\McpClient;

uses(McpClient::class);

/** T-43 · las redes del inbox y `can_hide` salen de las capacidades registradas, no de listas escritas a mano. */
class FakeLinkedInComments implements CommentSource
{
    public function hasScopes(SocialAccount $account, bool $write = false): bool
    {
        return true;
    }

    public function missingScopes(SocialAccount $account): array
    {
        return [];
    }

    public function fetch(SocialAccount $account, string $postExternalId, ?float $deadline = null): array
    {
        return [];
    }

    public function reply(SocialAccount $account, string $commentId, string $message): string
    {
        return 'r1';
    }
}

test('Meta es la unica fuente que oculta; el resto de redes con fuente no', function () {
    $inbox = app(InboxService::class);

    expect(collect($inbox->networks())->map->value->all())->toBe(['instagram', 'facebook', 'threads', 'bluesky', 'youtube'])
        ->and($inbox->canHide(Network::Instagram))->toBeTrue()->and($inbox->canHide(Network::Facebook))->toBeTrue()
        ->and($inbox->canHide(Network::Threads))->toBeFalse()->and($inbox->canHide(Network::YouTube))->toBeFalse()
        ->and(app(ConnectorRegistry::class)->commentsFor(Network::Instagram))->toBeInstanceOf(HidesComments::class);
});

test('una red nueva con CommentSource aparece en el inbox y en la cobertura sin tocar InboxService', function () {
    app(ConnectorRegistry::class)->registerComments(Network::LinkedIn, new FakeLinkedInComments);
    $inbox = app(InboxService::class);
    $row = collect($inbox->coverage())->firstWhere('network', 'linkedin');

    expect(collect($inbox->networks())->map->value->all())->toContain('linkedin')
        ->and($inbox->canHide(Network::LinkedIn))->toBeFalse()
        ->and($row['read'])->toBeTrue()->and($row['reply'])->toBeTrue()->and($row['hide'])->toBeFalse()
        ->and(collect($inbox->coverage())->where('network', 'linkedin'))->toHaveCount(1);
});

test('las descripciones MCP del buzon se generan del registro y no se desincronizan', function () {
    expect((new TriageComments)->description())->toContain('solo Instagram y Facebook')
        ->and((new ListComments)->description())->toContain('Instagram, Facebook, Threads, Bluesky y YouTube');

    app(ConnectorRegistry::class)->registerComments(Network::LinkedIn, new FakeLinkedInComments);

    expect((new ListComments)->description())->toContain('Instagram, Facebook, Threads, Bluesky, YouTube y LinkedIn')
        ->and((new TriageComments)->description())->toContain('solo Instagram y Facebook');
});

test('una red nueva con CommentSource la aceptan los filtros web, marcar todo leido y la API v1, y la pagina la ofrece', function () {
    app(ConnectorRegistry::class)->registerComments(Network::LinkedIn, new FakeLinkedInComments);
    $user = User::factory()->create();
    $brand = Brand::factory()->for($user)->create();

    $this->actingAs($user)->get(route('inbox.index', ['network' => 'linkedin']))->assertOk()->assertSessionDoesntHaveErrors()
        ->assertInertia(fn ($page) => $page->where('coverage', fn ($rows) => collect($rows)->contains(fn ($r) => $r['network'] === 'linkedin' && $r['read'] === true)));
    $this->actingAs($user)->post(route('inbox.read-all'), ['network' => 'linkedin'])->assertSessionDoesntHaveErrors();

    app('auth')->forgetGuards();
    $this->withToken($this->mcpKey($user))->getJson('/api/v1/brands/'.$brand->id.'/comments?network=linkedin')->assertOk();
    app('auth')->forgetGuards();
    $this->withToken($this->mcpKey($user))->getJson('/api/v1/brands/'.$brand->id.'/comments?network=tiktok')->assertUnprocessable();
});

test('la pagina del buzon no escribe las redes en el desplegable: salen de la prop del servidor', function () {
    $vue = file_get_contents(resource_path('js/Pages/Inbox/Index.vue'));

    expect($vue)->not->toContain('<option value="instagram">')->and($vue)->not->toContain('<option value="youtube">')
        ->and($vue)->toContain('v-for="n in networks"');
});
