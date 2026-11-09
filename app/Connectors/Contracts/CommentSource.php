<?php

namespace App\Connectors\Contracts;

use App\Models\SocialAccount;
use Carbon\CarbonImmutable;

/**
 * Lectura y respuesta de comentarios de publicaciones PROPIAS en una red (inbox, T-20). Cada red lo implementa con su API
 * oficial; las que no tienen una API publica de comentarios (TikTok) no tienen implementacion y el inbox las marca fuera.
 */
interface CommentSource
{
    /** ¿La cuenta tiene concedidos los permisos? `$write` = responder en vez de solo leer. */
    public function hasScopes(SocialAccount $account, bool $write = false): bool;

    /**
     * Comentarios (y respuestas) normalizados, mas recientes primero.
     *
     * @return list<array{external_id: string, parent_external_id: ?string, author_name: ?string, text: ?string, is_own: bool, hidden: bool, commented_at: ?CarbonImmutable}>
     */
    public function fetch(SocialAccount $account, string $postExternalId, ?float $deadline = null): array;

    /** Responde a un comentario con la cuenta; devuelve el id de la respuesta creada. */
    public function reply(SocialAccount $account, string $commentId, string $message): string;
}
