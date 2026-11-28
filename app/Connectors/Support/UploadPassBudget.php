<?php

namespace App\Connectors\Support;

use App\Connectors\Network;

/**
 * Presupuesto de tiempo de UNA pasada de subida reanudable (TikTok, YouTube), sobre `StepBudget`: cada pasada es un job de
 * `$timeout` fijo (110 s) que sube trozos y, al agotarse, guarda su avance y sale para continuar en el siguiente sondeo.
 *   - Presupuesto BLANDO (`social.<red>.upload_budget_seconds`): no se empieza otro trozo si queda menos de lo que pide la red.
 *   - Marca DURA (`social.<red>.pass_seconds` < `$timeout` del job): el timeout HTTP de cada llamada se acota a lo que queda.
 * Fuera de una pasada (`begin()` sin llamar o tras `end()`) no recorta nada: el tope propio de cada llamada y el presupuesto entero.
 */
class UploadPassBudget
{
    private ?StepBudget $soft = null;

    private ?StepBudget $hard = null;

    public function __construct(private readonly Network $network, private readonly int $minSliceDefault) {}

    /** Arranca el reloj de la pasada (`$startedAt` solo para reanudar un reloj ya en marcha, p. ej. en pruebas). */
    public function begin(?float $startedAt = null): void
    {
        $startedAt ??= microtime(true);
        $this->soft = StepBudget::within($this->seconds('upload_budget_seconds', 80), $startedAt);
        $this->hard = StepBudget::within($this->seconds('pass_seconds', 100), $startedAt);
    }

    public function end(): void
    {
        $this->soft = $this->hard = null;
    }

    /** Segundos que quedan del presupuesto blando de subida de la pasada. */
    public function budgetLeft(): float
    {
        return $this->soft?->remaining() ?? $this->seconds('upload_budget_seconds', 80);
    }

    /** Comprueba si queda margen para otro trozo. El llamador garantiza el primer intento. */
    public function canStartChunk(): bool
    {
        $remaining = $this->budgetLeft();

        return $remaining > 0 && $remaining >= $this->seconds('upload_min_slice_seconds', $this->minSliceDefault);
    }

    /** Timeout HTTP de una llamada: el tope propio `$cap`, acotado a lo que queda hasta la marca dura de la pasada (minimo 1 s). */
    public function callTimeout(float $cap): float
    {
        return $this->hard === null ? $cap : max(1.0, min($cap, $this->hard->remaining()));
    }

    private function seconds(string $key, int $default): float
    {
        return (float) config('social.'.$this->network->value.'.'.$key, $default);
    }
}
