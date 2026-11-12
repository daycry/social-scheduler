<?php

namespace App\Connectors\Google;

use App\Connectors\Exceptions\QuotaExceededException;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;

/**
 * Presupuesto diario de cupo de YouTube que pueden gastar los COMPETIDORES (T-25): contador propio por dia del Pacifico, bajo por
 * defecto (`social.competitors.youtube_daily_units`) para que seguir canales nunca deje una subida sin cupo (~1.600 ud), igual que
 * `YouTubeInboxBudget` con el buzon. El cupo global del proyecto lo sigue llevando `YouTubeQuota`: cada llamada se comprueba y se
 * carga en los dos.
 */
class YouTubeCompetitorBudget
{
    private const ZONE = 'America/Los_Angeles';

    public function limit(): int
    {
        return max(0, (int) config('social.competitors.youtube_daily_units', 300));
    }

    public function used(): int
    {
        return (int) Cache::get($this->key(), 0);
    }

    /** @throws QuotaExceededException */
    public function assertAllows(int $cost): void
    {
        if ($this->used() + $cost > $this->limit()) {
            throw new QuotaExceededException('Los competidores de YouTube ya han gastado su presupuesto diario ('.$this->used().' de '.$this->limit().' unidades); se renueva a medianoche (hora del Pacífico) y así quedan unidades para subir videos.', $this->resetsAt());
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
        return 'youtube:competitors:'.CarbonImmutable::now(self::ZONE)->format('Y-m-d');
    }
}
