<?php

namespace App\Connectors\Contracts;

use App\Models\SocialAccount;

/**
 * Capacidad de una `CommentSource` (inbox, T-43): la red deja ocultar y volver a mostrar un comentario ajeno. El inbox ofrece
 * ocultar solo en las redes cuya fuente la implementa; sin ella (Threads, Bluesky, YouTube) el boton no aparece y la accion se rechaza.
 */
interface HidesComments
{
    /** Oculta (o vuelve a mostrar) un comentario en la propia red. */
    public function setHidden(SocialAccount $account, string $commentId, bool $hidden): void;
}
