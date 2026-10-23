<?php

namespace Tests\Support;

use App\Connectors\Contracts\Publisher;
use App\Connectors\Contracts\StatusPoller;
use App\Connectors\Data\PublishRequest;
use App\Connectors\Data\PublishResult;
use App\Connectors\Exceptions\TransientPublishException;
use App\Models\SocialAccount;
use Throwable;

/**
 * Publicador simulado: guarda en `externals` lo que "la red" tiene publicado, para comprobar que no hay
 * duplicados. `script` es la cola de comportamientos de publish(): 'ok' | 'sent-then-fail' (la red publica y
 * la respuesta se pierde) | 'processing' | una excepcion a lanzar. Sin red.
 */
class FakePublisher implements Publisher, StatusPoller
{
    /** @var list<string|Throwable> */
    public array $script = [];

    /** @var list<string|Throwable> 'done' | 'wait' | 'fail' | excepcion */
    public array $pollScript = [];

    /** @var array<int, string> targetId => id externo */
    public array $externals = [];

    public int $publishCalls = 0;

    public int $findCalls = 0;

    public int $pollCalls = 0;

    public bool $canLookup = true;

    public function publish(SocialAccount $account, PublishRequest $request): PublishResult
    {
        $this->publishCalls++;
        $step = array_shift($this->script) ?? 'ok';

        if ($step instanceof Throwable) {
            throw $step;
        }

        $this->externals[$request->targetId] ??= 'ext-'.$request->targetId.'-'.count($this->externals);

        return match ($step) {
            'sent-then-fail' => throw new TransientPublishException('Timeout tras enviar la publicacion.', 'timeout'),
            'processing' => PublishResult::processing('ref-'.$request->targetId),
            default => PublishResult::published($this->externals[$request->targetId], 'https://fake.test/p/'.$this->externals[$request->targetId]),
        };
    }

    public function findPublished(SocialAccount $account, PublishRequest $request): ?PublishResult
    {
        $this->findCalls++;

        if (! $this->canLookup || ! isset($this->externals[$request->targetId])) {
            return null;
        }

        return PublishResult::published($this->externals[$request->targetId], 'https://fake.test/p/'.$this->externals[$request->targetId]);
    }

    public function poll(SocialAccount $account, PublishRequest $request): PublishResult
    {
        $this->pollCalls++;
        $step = array_shift($this->pollScript) ?? 'wait';

        if ($step instanceof Throwable) {
            throw $step;
        }

        return match ($step) {
            'done' => PublishResult::published($this->externals[$request->targetId] ??= 'ext-'.$request->targetId, 'https://fake.test/p/'.$request->targetId),
            'fail' => PublishResult::failed('Instagram no pudo procesar el video.'),
            default => PublishResult::processing($request->reference ?? 'ref'),
        };
    }
}
