<?php

namespace App\Connectors\Google;

use App\Connectors\Exceptions\QuotaExceededException;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;

/**
 * Presupuesto diario de cupo de YouTube que pueden gastar los COMPETIDORES (T-25), en dos niveles para repartirlo:
 *  - GLOBAL de la instalacion (`social.competitors.youtube_daily_units`, 300): bajo a proposito para que seguir canales nunca deje una
 *    subida sin cupo (~1.600 ud), igual que `YouTubeInboxBudget` con el buzon;
 *  - TOPE POR USUARIO (`social.competitors.youtube_user_daily_units`, 60): un usuario no puede agotar el global y dejar sin datos a los demas.
 * Contadores por dia del Pacifico. El cupo del proyecto lo sigue llevando `YouTubeQuota`: cada llamada se comprueba y se carga en los dos.
 *
 * Capacidad: un competidor gasta ~3 ud al dia (perfil 1 + publicaciones 2), asi que con los valores por defecto caben unos 100 competidores
 * en la instalacion y unos 20 por usuario. Se ajusta con las dos variables segun la cuota que se quiera ceder a esta funcion.
 */
class YouTubeCompetitorBudget
{
    private const ZONE = 'America/Los_Angeles';

    public function limit(): int
    {
        return max(0, (int) config('social.competitors.youtube_daily_units', 300));
    }

    public function userLimit(): int
    {
        return max(0, (int) config('social.competitors.youtube_user_daily_units', 60));
    }

    public function used(): int
    {
        return (int) Cache::get($this->key(), 0);
    }

    public function usedBy(int $userId): int
    {
        return (int) Cache::get($this->key($userId), 0);
    }

    /** @throws QuotaExceededException */
    public function assertAllows(int $cost, int $userId): void
    {
        if ($this->usedBy($userId) + $cost > $this->userLimit()) {
            throw new QuotaExceededException('Has gastado tu parte del presupuesto diario de YouTube para competidores ('.$this->usedBy($userId).' de '.$this->userLimit().' unidades); se renueva a medianoche (hora del Pacífico).', $this->resetsAt());
        }
        if ($this->used() + $cost > $this->limit()) {
            throw new QuotaExceededException('Los competidores de YouTube de toda la instalacion ya han gastado su presupuesto diario ('.$this->used().' de '.$this->limit().' unidades); se renueva a medianoche (hora del Pacífico) y así quedan unidades para subir videos.', $this->resetsAt());
        }
    }

    public function charge(int $cost, int $userId): void
    {
        foreach ([$this->key(), $this->key($userId)] as $key) {
            Cache::add($key, 0, $this->resetsAt()->addDay());
            Cache::increment($key, $cost);
        }
    }

    public function resetsAt(): CarbonImmutable
    {
        return CarbonImmutable::now(self::ZONE)->addDay()->startOfDay()->addMinutes(5)->setTimezone('UTC');
    }

    private function key(?int $userId = null): string
    {
        return 'youtube:competitors:'.CarbonImmutable::now(self::ZONE)->format('Y-m-d').($userId === null ? '' : ':u'.$userId);
    }
}
