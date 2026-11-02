<?php

namespace App\Connectors\Support;

/**
 * Presupuesto de tiempo de UN paso de publicacion (un job tiene `$timeout` fijo). Los flujos de varias llamadas
 * (carrusel de IG: hijos -> sondeo -> padre -> publicar) consultan `allows()` antes de cada llamada de red y, si
 * no cabe otra, guardan su progreso y salen para continuar en el siguiente sondeo, en vez de morir por timeout
 * y repetir trabajo. Siempre se permite al menos una llamada por paso, para que cada paso avance.
 */
class StepBudget
{
    private int $calls = 0;

    private function __construct(private readonly float $startedAt, private readonly float $budget, private readonly float $perCall) {}

    public static function start(): self
    {
        return new self(microtime(true), (float) config('publishing.step_budget_seconds', 85), (float) config('social.http_timeout', 15));
    }

    /**
     * ¿Cabe otra llamada (en el peor caso, tardara `social.http_timeout`) dentro del presupuesto? `$after` reserva el peor
     * caso de las llamadas que SIEMPRE siguen a esta sin volver a preguntar (p. ej. estado -> media_publish -> permalink).
     */
    public function allows(int $after = 0): bool
    {
        return $this->calls === 0 || (microtime(true) - $this->startedAt) + $this->perCall * (1 + max(0, $after)) <= $this->budget;
    }

    /** Segundos que quedan del presupuesto (0 si se agoto). Sirve para acotar el timeout de una llamada larga que cierra el paso. */
    public function remaining(): float
    {
        return max(0.0, $this->budget - (microtime(true) - $this->startedAt));
    }

    /** Registra una llamada hecha. */
    public function spent(): void
    {
        $this->calls++;
    }
}
