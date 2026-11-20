<?php

namespace App\Services\Posts;

use App\Connectors\Network;
use App\Exceptions\PostValidationException;
use App\Models\Brand;
use App\Models\Post;
use App\Models\SocialAccount;
use App\Models\User;
use App\Services\PostService;
use App\Support\Ownership;

/** Reconstruye contenido mediante el creador de borradores; nunca replica filas ni historial. */
final class PostDuplicator
{
    public function __construct(private readonly PostService $posts, private readonly PostPlanner $planner) {}

    public function duplicate(User $user, Post $source, Brand $destination): DuplicatePostResult
    {
        Ownership::assertAll([$source, $source->brand, $destination], $user);

        $accounts = SocialAccount::ownedBy($user->id)->where('brand_id', $destination->id)
            ->where('status', '!=', SocialAccount::STATUS_DISCONNECTED)->get();
        $targets = $source->targets()->get();
        Ownership::assertAll($targets, $user);
        $data = $this->planner->dataFromPost($source, ['schedule' => false, 'publish_now' => false, 'scheduled_at' => null], $targets);
        $content = $data['targets'];
        $data['targets'] = [];
        $sameBrand = (int) $source->brand_id === (int) $destination->id;
        $resetByTarget = [];
        $sourceAccounts = $targets->groupBy(fn ($target) => $target->network->value)
            ->map(fn ($group) => $group->pluck('social_account_id')->unique()->count());
        foreach ($targets as $i => $target) {
            // Varios textos para la misma red no se reducen eligiendo el primero.
            // Un tablero de Pinterest pertenece a una cuenta concreta: se elige de nuevo.
            if (! $sameBrand && ($sourceAccounts[$target->network->value] !== 1 || $target->network === Network::Pinterest)) {
                continue;
            }
            $matches = $sameBrand
                ? $accounts->where('id', $target->social_account_id)
                : $accounts->where('network', $target->network);
            $account = $matches->count() === 1 ? $matches->first() : null;
            if ($account === null) {
                continue;
            }
            $copy = array_replace($content[$i], ['account_id' => $account->id]);
            // El catálogo de audio depende de la cuenta autorizada; la privacidad de TikTok se elige por publicación.
            $reset = match ($target->network) {
                Network::Instagram => $sameBrand ? [] : ['instagram_audio'],
                Network::TikTok => ['privacy_level'],
                default => [],
            };
            foreach ($reset as $key) {
                if (filled($copy['options'][$key] ?? null)) {
                    $resetByTarget[count($data['targets'])][$key] = true;
                }
                unset($copy['options'][$key]);
            }
            $data['targets'][] = $copy;
        }

        // Valida con las mismas reglas que crear/editar. Solo se omiten errores de un destino:
        // un problema del contenido común se devuelve al usuario y nunca se oculta.
        $errors = $this->posts->validate($user, $destination, $data);
        foreach (array_keys($errors) as $field) {
            $index = PostPlanner::targetErrorIndex($field);
            if ($index === null) {
                throw new PostValidationException($errors);
            }
            unset($data['targets'][$index]);
            unset($resetByTarget[$index]);
        }
        $data['targets'] = array_values($data['targets']);
        $omitted = $targets->count() - count($data['targets']);
        $resetOptions = [];
        foreach ($resetByTarget as $keys) {
            $resetOptions += $keys;
        }

        return new DuplicatePostResult($this->posts->create($user, $destination, $data), $omitted, array_keys($resetOptions));
    }
}
