<?php

declare(strict_types=1);

use App\Connectors\Bluesky\BlueskyConnector;
use App\Connectors\Data\RefreshResult;
use App\Connectors\Network;
use App\Models\SocialAccount;

/** Keep the real credential-form contract while ensuring connection and refresh cannot contact a PDS. */
class GeneralBlueskyConnector extends BlueskyConnector
{
    public function connectWithCredentials(array $input): array
    {
        return (new T43Connector('bluesky', [Network::Bluesky]))->exchangeCode('fake-local-code', 'http://localhost:8080');
    }

    public function refresh(SocialAccount $account): RefreshResult
    {
        return new RefreshResult('general-fake-access', 'general-fake-refresh', now()->addDays(60));
    }
}
