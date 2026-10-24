<?php

namespace App\Enums;

/**
 * Estado del primer comentario de un destino (C-01b). Es independiente de `PostStatus`: un comentario fallido
 * nunca degrada un destino ya publicado.
 */
enum FirstCommentStatus: string
{
    case Pending = 'pendiente';
    case Commenting = 'comentando';
    case Published = 'publicado';
    case Failed = 'fallido';
    case NotAllowed = 'no_admitido';

    public function label(): string
    {
        return match ($this) {
            self::Pending => 'Pendiente',
            self::Commenting => 'Comentando',
            self::Published => 'Comentado',
            self::Failed => 'Fallido',
            self::NotAllowed => 'No admitido',
        };
    }

    /** Estado final: el job no vuelve a actuar sin una accion del usuario. */
    public function isSettled(): bool
    {
        return in_array($this, [self::Published, self::Failed, self::NotAllowed], true);
    }
}
