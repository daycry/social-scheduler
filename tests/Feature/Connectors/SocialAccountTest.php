<?php

use App\Connectors\ConnectorRegistry;
use App\Connectors\Data\ConnectionCandidate;
use App\Connectors\Exceptions\ConnectorException;
use App\Connectors\Network;
use App\Models\Brand;
use App\Models\SocialAccount;
use App\Models\User;
use App\Services\SocialAccountService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Tests\Support\FakeConnector;

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->brand = Brand::factory()->for($this->user)->create();
    $this->service = app(SocialAccountService::class);
});

function candidate(array $over = []): ConnectionCandidate
{
    return new ConnectionCandidate(
        network: $over['network'] ?? Network::Instagram,
        externalId: $over['externalId'] ?? '17841400000000000',
        name: 'Aipocalipsis',
        username: 'aipocalipsis_oficial',
        accessToken: $over['accessToken'] ?? 'PLAIN-ACCESS-TOKEN-123',
        refreshToken: 'PLAIN-REFRESH-TOKEN-456',
        tokenExpiresAt: now()->addDays(60),
        scopes: ['instagram_basic'],
        meta: $over['meta'] ?? [],
    );
}

test('SocialAccount stores tokens encrypted at rest (no plain text in the database)', function () {
    $this->service->connect($this->user, $this->brand, candidate());

    $raw = DB::table('social_accounts')->first();

    expect($raw->access_token)->not->toContain('PLAIN-ACCESS-TOKEN-123')
        ->and($raw->refresh_token)->not->toContain('PLAIN-REFRESH-TOKEN-456')
        ->and(json_encode($raw))->not->toContain('PLAIN-');

    $account = SocialAccount::withoutUserScope()->first();
    expect($account->access_token)->toBe('PLAIN-ACCESS-TOKEN-123')
        ->and($account->refresh_token)->toBe('PLAIN-REFRESH-TOKEN-456')
        ->and($account->status)->toBe('activa')
        ->and($account->network)->toBe(Network::Instagram);
});

test('SocialAccount never serializes its tokens', function () {
    $account = $this->service->connect($this->user, $this->brand, candidate());

    expect($account->toJson())->not->toContain('PLAIN-')->and($account->toArray())->not->toHaveKeys(['access_token', 'refresh_token']);
});

test('SocialAccount connect is idempotent per brand, network and external id and keeps per-account options', function () {
    $first = $this->service->connect($this->user, $this->brand, candidate(['meta' => ['direct_post' => true]]));
    $second = $this->service->connect($this->user, $this->brand, candidate(['accessToken' => 'NEW', 'meta' => ['x' => 1]]));

    expect(SocialAccount::withoutUserScope()->count())->toBe(1)
        ->and($second->id)->toBe($first->id)
        ->and($second->access_token)->toBe('NEW')
        ->and($second->meta)->toBe(['direct_post' => true, 'x' => 1]);
});

test('SocialAccount disconnect removes the tokens but keeps the row', function () {
    $account = $this->service->connect($this->user, $this->brand, candidate());
    $this->service->disconnect($account);

    $raw = DB::table('social_accounts')->first();
    expect($raw->status)->toBe('desconectada')->and($raw->access_token)->toBeNull()->and($raw->refresh_token)->toBeNull();
});

test('SocialAccount is isolated by user through BelongsToUser and the service', function () {
    $other = User::factory()->create();
    $otherBrand = Brand::factory()->for($other)->create();
    $mine = $this->service->connect($this->user, $this->brand, candidate());
    $this->service->connect($other, $otherBrand, candidate(['externalId' => '999']));

    $this->actingAs($this->user);
    expect(SocialAccount::count())->toBe(1)->and(SocialAccount::first()->id)->toBe($mine->id);

    expect($this->service->listForBrand($this->user, $this->brand))->toHaveCount(1)
        ->and($this->service->find($this->user, SocialAccount::withoutUserScope()->where('user_id', $other->id)->first()->id))->toBeNull();

    expect(fn () => $this->service->connect($this->user, $otherBrand, candidate()))->toThrow(InvalidArgumentException::class);
    expect(fn () => $this->service->listForBrand($this->user, $otherBrand))->toThrow(InvalidArgumentException::class);
    expect(Gate::forUser($this->user)->allows('view', SocialAccount::withoutUserScope()->where('user_id', $other->id)->first()))->toBeFalse();
});

test('SocialAccount rows cascade when the brand is deleted', function () {
    $this->service->connect($this->user, $this->brand, candidate());
    $this->brand->delete();

    expect(SocialAccount::withoutUserScope()->count())->toBe(0);
});

test('SocialAccount registry resolves connectors by provider and network and rejects unknown ones', function () {
    $registry = (new ConnectorRegistry)->register(new FakeConnector('meta'));

    expect($registry->forProvider('meta')->provider())->toBe('meta')
        ->and($registry->forNetwork(Network::Facebook)->provider())->toBe('meta')
        ->and($registry->has('google'))->toBeFalse();
    expect(fn () => $registry->forProvider('google'))->toThrow(ConnectorException::class);
});

test('SocialAccount candidate public array carries no tokens', function () {
    expect(json_encode(candidate()->toPublicArray()))->not->toContain('PLAIN-')->and(candidate()->key())->toBe('instagram:17841400000000000');
});
