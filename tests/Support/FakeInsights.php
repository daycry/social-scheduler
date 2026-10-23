<?php

namespace Tests\Support;

use App\Analytics\Metric;
use App\Connectors\Contracts\InsightsReader;
use App\Models\PostTarget;
use App\Models\SocialAccount;
use Carbon\CarbonInterface;
use Throwable;

/** Lector de metricas simulado (sin red) para los tests del colector y de los jobs. */
class FakeInsights implements InsightsReader
{
    /** @var array<string, int|float|null> */
    public array $account = [
        Metric::FOLLOWERS => 100, Metric::VIEWS => 50, Metric::REACH => 30, Metric::INTERACTIONS => 5,
        // Con estos flujos el snapshot de Instagram esta COMPLETO (Metric::EXPECTED_FLOWS).
        Metric::PROFILE_VIEWS => 4, Metric::ACCOUNTS_ENGAGED => 9, Metric::FOLLOWERS_GAINED => 2,
    ];

    /** @var array<string, int|float|null> */
    public array $post = [Metric::VIEWS => 10, Metric::LIKES => 2, Metric::COMMENTS => 1, Metric::INTERACTIONS => 3];

    public ?Throwable $error = null;

    public int $accountCalls = 0;

    public int $postCalls = 0;

    /** @var list<string> */
    public array $days = [];

    public bool $readable = true;

    public function accountInsights(SocialAccount $account, CarbonInterface $day): array
    {
        $this->accountCalls++;
        $this->days[] = $day->format('Y-m-d');

        if ($this->error) {
            throw $this->error;
        }

        return $this->account;
    }

    public function canReadPost(PostTarget $target): bool
    {
        return $this->readable;
    }

    public function postInsights(SocialAccount $account, string $externalId): array
    {
        $this->postCalls++;

        if ($this->error) {
            throw $this->error;
        }

        return $this->post;
    }
}
