<?php

namespace App\Connectors\Contracts;

use App\Models\SocialAccount;

/**
 * Capacidad de dejar un comentario propio en una publicacion ya publicada (primer comentario, C-01b). Es un contrato
 * APARTE de `Publisher`: TikTok no lo implementa y `ConnectorRegistry::commenterFor()` devuelve null para el.
 *
 * Los errores usan las excepciones de publicacion: transitorio (se reintenta), permanente / reconexion (queda `fallido`)
 * y `CommentNotAllowedException` (comentarios cerrados, video privado o «para ninos»: `no_admitido`).
 */
interface FirstCommenter
{
    /** Publica el comentario sobre `$externalId` (id de la publicacion en la red) y devuelve el id del comentario creado. */
    public function comment(SocialAccount $account, string $externalId, string $text): string;

    /** Id de un comentario PROPIO con ese texto en la publicacion, o null si la red no lo muestra. */
    public function findOwnComment(SocialAccount $account, string $externalId, string $text): ?string;
}
