<?php

namespace App\Connectors\Google;

use App\Connectors\Exceptions\QuotaExceededException;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;

/**
 * Cupo diario de la API de YouTube Data (10.000 ud/dia por PROYECTO de Google, ~1.600 por subida). Se lleva una
 * cuenta local en cache para no intentar subidas que la API rechazaria. El dia de cuota acaba a medianoche
 * de Pacifico; el contador caduca solo. La decision de reprogramar o fallar es de `QuotaPolicy`.
 */
class YouTubeQuota
{
    private const ZONE = 'America/Los_Angeles';

    public function limit(): int
    {
        return (int) config('social.youtube.daily_quota', 10000);
    }

    public function used(): int
    {
        return (int) Cache::get($this->key(), 0);
    }

    /** @throws QuotaExceededException si `$cost` no cabe en lo que queda del dia. */
    public function assertAvailable(int $cost): void
    {
        if ($this->used() + $cost > $this->limit()) {
            throw new QuotaExceededException(
                'Se ha agotado el cupo diario de la API de YouTube ('.$this->used().' de '.$this->limit().' unidades usadas); se libera a medianoche (hora del Pacifico).',
                $this->resetsAt(),
            );
        }
    }

    public function charge(int $cost): void
    {
        Cache::add($this->key(), 0, $this->resetsAt()->addDay());
        Cache::increment($this->key(), $cost);
    }

    /** Marca el dia como agotado (la propia API dijo `quotaExceeded`). */
    public function markExhausted(): void
    {
        Cache::put($this->key(), $this->limit(), $this->resetsAt()->addDay());
    }

    /** Proxima medianoche de Pacifico (+5 min de margen). */
    public function resetsAt(): CarbonImmutable
    {
        return CarbonImmutable::now(self::ZONE)->addDay()->startOfDay()->addMinutes(5)->setTimezone('UTC');
    }

    private function key(): string
    {
        return 'youtube:quota:'.CarbonImmutable::now(self::ZONE)->format('Y-m-d');
    }
}
