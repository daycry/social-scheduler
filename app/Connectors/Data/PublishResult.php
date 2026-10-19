<?php

namespace App\Connectors\Data;

/** Resultado de publicar o sondear: publicada, en proceso (asincrono) o fallida de forma definitiva. */
final class PublishResult
{
    public const PUBLISHED = 'published';

    public const PROCESSING = 'processing';

    public const FAILED = 'failed';

    /** @param array<string, mixed> $raw Respuesta de la red (sin secretos) para depuracion. */
    private function __construct(
        public readonly string $status,
        public readonly ?string $externalId = null,
        public readonly ?string $url = null,
        public readonly ?string $reference = null,
        public readonly ?string $message = null,
        public readonly array $raw = [],
        public readonly ?string $note = null,
    ) {}

    /** @param ?string $note Aclaracion para el usuario cuando «publicada» no es la historia completa (p. ej. borrador en la bandeja de TikTok). */
    public static function published(string $externalId, ?string $url = null, array $raw = [], ?string $note = null): self
    {
        return new self(self::PUBLISHED, $externalId, $url, null, null, $raw, $note);
    }

    public static function processing(string $reference, array $raw = []): self
    {
        return new self(self::PROCESSING, null, null, $reference, null, $raw);
    }

    public static function failed(string $message, array $raw = []): self
    {
        return new self(self::FAILED, null, null, null, $message, $raw);
    }

    public function isPublished(): bool
    {
        return $this->status === self::PUBLISHED;
    }

    public function isProcessing(): bool
    {
        return $this->status === self::PROCESSING;
    }

    public function isFailed(): bool
    {
        return $this->status === self::FAILED;
    }
}
