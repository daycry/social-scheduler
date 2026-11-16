<?php

use App\Connectors\ConnectorRegistry;
use App\Connectors\Contracts\CommentSource;
use App\Connectors\Contracts\HidesComments;
use App\Connectors\Network;
use App\Models\SocialAccount;
use App\Services\InboxService;

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
