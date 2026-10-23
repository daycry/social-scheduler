<?php

namespace App\Enums;

/**
 * Estados de una publicacion por red: borrador -> programada -> publicando -> procesando ->
 * publicada | fallida. Las transiciones validas viven aqui (fuente unica).
 */
enum PostStatus: string
{
    case Draft = 'borrador';
    case Scheduled = 'programada';
    case Publishing = 'publicando';
    case Processing = 'procesando';
    case Published = 'publicada';
    case Failed = 'fallida';

    /** @return list<self> */
    public function allowedNext(): array
    {
        return match ($this) {
            self::Draft => [self::Scheduled],
            self::Scheduled => [self::Publishing, self::Draft, self::Failed],
            // `publicando` -> `programada`: reintento diferido (error transitorio, cuota agotada).
            self::Publishing => [self::Processing, self::Published, self::Failed, self::Scheduled],
            self::Processing => [self::Published, self::Failed, self::Processing],
            self::Failed => [self::Scheduled, self::Draft],
            self::Published => [],
        };
    }

    public function canTransitionTo(self $next): bool
    {
        return in_array($next, $this->allowedNext(), true);
    }

    /** Pendiente de publicar: todavia se puede editar, reprogramar o borrar sin consecuencias en la red. */
    public function isPending(): bool
    {
        return $this === self::Draft || $this === self::Scheduled;
    }

    /** Un destino en vuelo o publicado nunca se borra por debajo: hay rastro externo que perder. */
    public function canBeDeleted(): bool
    {
        return ! $this->isInFlight() && ! $this->isFinal();
    }

    /** En vuelo: el publicador esta trabajando en ello. */
    public function isInFlight(): bool
    {
        return $this === self::Publishing || $this === self::Processing;
    }

    public function isFinal(): bool
    {
        return $this === self::Published;
    }

    public function label(): string
    {
        return match ($this) {
            self::Draft => 'Borrador',
            self::Scheduled => 'Programada',
            self::Publishing => 'Publicando',
            self::Processing => 'Procesando',
            self::Published => 'Publicada',
            self::Failed => 'Con error',
        };
    }
}
