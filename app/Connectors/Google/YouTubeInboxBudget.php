<?php

namespace App\Connectors\Google;

use App\Connectors\Exceptions\QuotaExceededException;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;

/**
 * Presupuesto diario de cupo de YouTube que puede gastar el INBOX (T-20, CWE-770): contador propio por dia del Pacifico, bajo por
 * defecto (`social.inbox.youtube_daily_units`) para que leer y responder comentarios nunca deje una subida sin cupo (~1.600 ud).
 * Se consulta ANTES de cada llamada y se carga despues; el cupo global lo sigue llevando `YouTubeQuota`.
 */
class YouTubeInboxBudget
{
    private const ZONE = 'America/Los_Angeles';

    public function limit(): int
    {
        return max(0, (int) config('social.inbox.youtube_daily_units', 2000));
    }

    public function used(): int
    {
        return (int) Cache::get($this->key(), 0);
    }

    public function allows(int $cost): bool
    {
        return $this->used() + $cost <= $this->limit();
    }

    /** @throws QuotaExceededException */
    public function assertAllows(int $cost): void
    {
        if (! $this->allows($cost)) {
            throw new QuotaExceededException('El buzón ya ha gastado su presupuesto diario de YouTube ('.$this->used().' de '.$this->limit().' unidades); se renueva a medianoche (hora del Pacífico) y así quedan unidades para subir videos.', $this->resetsAt());
        }
    }

    public function charge(int $cost): void
    {
        Cache::add($this->key(), 0, $this->resetsAt()->addDay());
        Cache::increment($this->key(), $cost);
    }

    public function resetsAt(): CarbonImmutable
    {
        return CarbonImmutable::now(self::ZONE)->addDay()->startOfDay()->addMinutes(5)->setTimezone('UTC');
    }

    private function key(): string
    {
        return 'youtube:inbox:'.CarbonImmutable::now(self::ZONE)->format('Y-m-d');
    }
}
