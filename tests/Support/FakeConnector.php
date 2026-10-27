<?php

namespace Tests\Support;

use App\Connectors\Contracts\OAuthConnector;
use App\Connectors\Data\ConnectionCandidate;
use App\Connectors\Data\RefreshResult;
use App\Connectors\Exceptions\ConnectorException;
use App\Connectors\Network;
use App\Models\SocialAccount;
use Throwable;

/** Conector simulado para tests: sin red. */
class FakeConnector implements OAuthConnector
{
    /** @var list<ConnectionCandidate> */
    public array $candidates = [];

    public ?Throwable $refreshError = null;

    public ?RefreshResult $refreshResult = null;

    /** @var list<int> */
    public array $refreshed = [];

    /** Se ejecuta durante refresh() (simula algo que pasa mientras la red responde). */
    public ?\Closure $duringRefresh = null;

    /** @param list<Network> $networks */
    public function __construct(
        private readonly string $provider = 'meta',
        private readonly array $networks = [Network::Instagram, Network::Facebook],
        private readonly int $margin = 168,
    ) {}

    public function provider(): string
    {
        return $this->provider;
    }

    public function networks(): array
    {
        return $this->networks;
    }

    public function authorizationUrl(string $state, string $redirectUri, bool $rerequest = false): string
    {
        return 'https://fake.test/authorize?'.http_build_query(['state' => $state, 'redirect_uri' => $redirectUri, 'rerequest' => (int) $rerequest]);
    }

    public function exchangeCode(string $code, string $redirectUri): array
    {
        if ($code === 'bad') {
            throw new ConnectorException('Codigo invalido.');
        }

        return $this->candidates;
    }

    public function refresh(SocialAccount $account): RefreshResult
    {
        $this->refreshed[] = $account->id;

        if ($this->duringRefresh) {
            ($this->duringRefresh)($account);
        }

        if ($this->refreshError) {
            throw $this->refreshError;
        }

        return $this->refreshResult ?? new RefreshResult('new-access', 'new-refresh', now()->addDays(60));
    }

    public function refreshMarginHours(): int
    {
        return $this->margin;
    }
}
