<?php

declare(strict_types=1);
use App\Connectors\Contracts\Publisher;
use App\Connectors\Data\ConnectionCandidate;
use App\Connectors\Data\PublishRequest;
use App\Connectors\Data\PublishResult;
use App\Connectors\Data\RefreshResult;
use App\Connectors\Exceptions\ReconnectRequiredException;
use App\Connectors\Exceptions\TransientPublishException;
use App\Models\SocialAccount;
use Tests\Support\FakeConnector;

class T43Connector extends FakeConnector
{
    public function authorizationUrl(string $state, string $redirectUri, bool $rerequest = false): string
    {
        return $redirectUri.'?'.http_build_query(['state' => $state, 'code' => 't43-local-code']);
    }

    public function exchangeCode(string $code, string $redirectUri): array
    {
        return array_map(fn ($network) => new ConnectionCandidate(
            $network, 't43-'.$network->value, 'T43 '.$network->label(), 't43-'.$network->value,
            't43-fake-access-'.$network->value, 't43-fake-refresh', now()->addDays(60), scopes: ['publish'],
        ), $this->networks());
    }

    public function refresh(SocialAccount $account): RefreshResult
    {
        if (($account->meta['t43_refresh_fail'] ?? false) === true) {
            throw new ReconnectRequiredException('El proveedor simulado exige reconexion.');
        }

        return new RefreshResult('t43-renewed-'.$account->id, 't43-renewed-refresh', now()->addDays(60));
    }
}

/** Durable fake external network: its records survive separate web/worker processes. */
class T43Publisher implements Publisher
{
    public function __construct(private readonly string $root) {}

    private function mutate(Closure $callback): mixed
    {
        $handle = fopen($this->root.'/externals.json', 'c+');
        if ($handle === false || ! flock($handle, LOCK_EX)) {
            throw new RuntimeException('Cannot lock fake external network.');
        }
        try {
            $state = json_decode(stream_get_contents($handle) ?: '{}', true, flags: JSON_THROW_ON_ERROR);
            $result = $callback($state);
            rewind($handle);
            ftruncate($handle, 0);
            fwrite($handle, json_encode($state, JSON_THROW_ON_ERROR));

            return $result;
        } finally {
            flock($handle, LOCK_UN);
            fclose($handle);
        }
    }

    public function publish(SocialAccount $account, PublishRequest $request): PublishResult
    {
        $outcome = $this->mutate(function (array &$state) use ($request): string {
            $id = (string) $request->targetId;
            $state['calls'][$id] = ($state['calls'][$id] ?? 0) + 1;
            if (str_contains((string) $request->text, 't43-transient') && $state['calls'][$id] === 1) {
                return 'transient';
            }
            $state['records'][$id] ??= ['id' => 't43-external-'.$id, 'created_at' => now()->toIso8601String()];
            // T-17: lo que la red recibe de media (ids, orden, alt, portada y huella de los bytes) para comprobar la media efectiva del destino.
            $state['records'][$id]['media'] = array_map(fn ($m) => [
                'id' => $m->id, 'alt' => $m->alt, 'cover_id' => $m->cover?->id,
                'sha1' => $m->disk !== null && $m->path !== null ? sha1((string) \Illuminate\Support\Facades\Storage::disk($m->disk)->get($m->path)) : null,
            ], $request->media);
            if (str_contains((string) $request->text, 't43-timeout') && $state['calls'][$id] === 1) {
                return 'timeout';
            }

            return 'ok';
        });
        if ($outcome !== 'ok') {
            throw new TransientPublishException('Fallo transitorio simulado '.$outcome, $outcome);
        }

        return PublishResult::published('t43-external-'.$request->targetId, 'https://example.test/t43/'.$request->targetId);
    }

    public function findPublished(SocialAccount $account, PublishRequest $request): ?PublishResult
    {
        $found = $this->mutate(fn (array &$state) => $state['records'][(string) $request->targetId] ?? null);

        return $found ? PublishResult::published($found['id'], 'https://example.test/t43/'.$request->targetId) : null;
    }
}
