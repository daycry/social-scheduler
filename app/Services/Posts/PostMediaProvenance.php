<?php

namespace App\Services\Posts;

use App\Models\Studio\DesignExport;

/** Archivo → exportación únicamente para los adjuntos que incorpora Estudio. */
final readonly class PostMediaProvenance
{
    /**
     * @param  list<int>  $commonIds
     * @param  array<string, list<int>>|null  $targetIds  null al crear con el mismo origen para todos los destinos
     */
    public function __construct(public DesignExport $export, private array $commonIds, private ?array $targetIds = null) {}

    /** @return array<int, int> */
    public function common(): array
    {
        return array_fill_keys($this->commonIds, $this->export->id);
    }

    /** @return array<int, int> */
    public function target(string $key): array
    {
        return array_fill_keys($this->targetIds === null ? $this->commonIds : ($this->targetIds[$key] ?? []), $this->export->id);
    }
}
