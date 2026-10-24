<?php

namespace Tests\Support;

use App\Connectors\Contracts\FirstCommenter;
use App\Models\SocialAccount;
use Throwable;

/**
 * Publicador con primer comentario simulado: `comments` es lo que "la red" tiene (externalId => lista de [id, texto]) y
 * `commentScript` la cola de comportamientos de comment(): 'ok' | 'sent-then-fail' (la red lo crea pero el worker muere
 * antes de guardar el id) | excepcion. `hideNew` simula que la lista de la red aun no muestra el comentario recien creado.
 * Sin red.
 */
class FakeCommenter extends FakePublisher implements FirstCommenter
{
    /** @var list<string|Throwable> */
    public array $commentScript = [];

    /** @var array<string, list<array{id: string, text: string}>> */
    public array $comments = [];

    public bool $hideNew = false;

    public int $commentCalls = 0;

    public int $findCommentCalls = 0;

    public function comment(SocialAccount $account, string $externalId, string $text): string
    {
        $this->commentCalls++;
        $step = array_shift($this->commentScript) ?? 'ok';

        if ($step instanceof Throwable) {
            throw $step;
        }

        $id = 'cm-'.$externalId.'-'.(count($this->comments[$externalId] ?? []) + 1);
        $this->comments[$externalId][] = ['id' => $id, 'text' => $text];

        if ($step === 'sent-then-fail') {
            throw new \RuntimeException('El worker murio antes de guardar el id.');
        }

        return $id;
    }

    public function findOwnComment(SocialAccount $account, string $externalId, string $text): ?string
    {
        $this->findCommentCalls++;

        if ($this->hideNew) {
            return null;
        }

        foreach ($this->comments[$externalId] ?? [] as $c) {
            if ($c['text'] === $text) {
                return $c['id'];
            }
        }

        return null;
    }
}
