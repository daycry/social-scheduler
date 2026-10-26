<?php

namespace App\Services;

use App\Enums\FirstCommentStatus;
use App\Enums\PostStatus;
use App\Exceptions\PostValidationException;
use App\Jobs\PostFirstComment;
use App\Models\PostTarget;
use Illuminate\Support\Facades\DB;

/**
 * Acciones del usuario sobre el primer comentario de un destino YA publicado (C-01b, A1). Es la unica edicion admitida en un
 * destino `publicada` y no pasa por `PostService::lockEditableTargets()`: solo toca columnas del comentario, nunca `text`,
 * `status` ni los intentos de publicacion.
 */
class FirstCommentService
{
    /** Edita el texto de un comentario `fallido` antes de reintentar. */
    public function update(PostTarget $target, ?string $text): PostTarget
    {
        $text = $text !== null && trim($text) !== '' ? trim($text) : null;

        if ($text === null) {
            throw new PostValidationException(['first_comment' => ['El primer comentario no puede quedar vacio.']]);
        }

        $max = config("media_specs.types.{$target->content_type->value}.first_comment.max");
        if ($max !== null && mb_strlen($text) > (int) $max) {
            throw new PostValidationException(['first_comment' => ["El primer comentario admite como maximo $max caracteres en {$target->network->label()} (tiene ".mb_strlen($text).').']]);
        }

        return DB::transaction(function () use ($target, $text) {
            $fresh = $this->lockFailed($target, 'Solo se puede editar un primer comentario que ha fallado.');
            $fresh->forceFill(['first_comment' => $text])->save();

            return $fresh;
        });
    }

    /**
     * Reintenta un comentario `fallido` con el texto vigente: reinicia los intentos y despacha el job. NO borra
     * `first_comment_sent_at`: con una busqueda inconclusa y un envio previo posible, no se crea a ciegas salvo `$force`
     * («Publicar igualmente», confirmado por el usuario).
     */
    public function retry(PostTarget $target, bool $force = false): PostTarget
    {
        $fresh = DB::transaction(function () use ($target, $force) {
            $fresh = $this->lockFailed($target, 'Solo se puede reintentar un primer comentario que ha fallado.');
            $fresh->forceFill([
                'first_comment_status' => FirstCommentStatus::Pending,
                'first_comment_attempts' => 0,
                'first_comment_error' => null,
                'first_comment_at' => null,
            ])->save();

            DB::afterCommit(fn () => PostFirstComment::dispatch($fresh->id, $force));

            return $fresh;
        });

        return $fresh;
    }

    /** «Ya esta publicado»: el usuario confirma que el comentario existe en la red. Cierra como `publicado` sin llamar a la red. */
    public function markPublished(PostTarget $target): PostTarget
    {
        return DB::transaction(function () use ($target) {
            $fresh = $this->lockFailed($target, 'Solo se puede marcar como publicado un primer comentario que ha fallado.');
            $fresh->forceFill(['first_comment_status' => FirstCommentStatus::Published, 'first_comment_error' => null])->save();

            return $fresh;
        });
    }

    private function lockFailed(PostTarget $target, string $message): PostTarget
    {
        $fresh = PostTarget::withoutUserScope()->lockForUpdate()->findOrFail($target->id);

        if ($fresh->status !== PostStatus::Published || $fresh->first_comment_status !== FirstCommentStatus::Failed || $fresh->first_comment_external_id !== null) {
            throw new PostValidationException(['first_comment' => [$message]]);
        }

        return $fresh;
    }
}
