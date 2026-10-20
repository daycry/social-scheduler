<?php

namespace App\Services;

use App\Exceptions\PostValidationException;
use App\Models\User;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Asistente IA opcional (T-14): sugiere el texto de una publicacion con la Messages API de Anthropic (Http, sin SDK).
 * Solo existe con `AI_ASSISTANT_ENABLED=true` Y `ANTHROPIC_API_KEY`; si no, `enabled()` es false y la UI no lo muestra.
 * Privacidad: se envia SOLO el brief que escribe el usuario (sin emails, telefonos ni enlaces), el nombre de la marca y la
 * red de destino; nunca datos del usuario, cuentas, publicaciones ni media. Limite diario por usuario.
 */
class AiAssistant
{
    public const ENDPOINT = 'https://api.anthropic.com/v1/messages';

    public const VERSION = '2023-06-01';

    public const MAX_BRIEF = 500;

    public function enabled(): bool
    {
        return (bool) config('social.ai.enabled') && trim((string) config('social.ai.api_key')) !== '';
    }

    public function remaining(User $user): int
    {
        return max(0, (int) config('social.ai.daily_limit', 20) - (int) Cache::get($this->key($user), 0));
    }

    /**
     * @throws PostValidationException brief vacio/largo, limite diario o fallo del servicio (mensaje legible)
     */
    public function suggest(User $user, string $brief, ?string $brandName = null, ?string $network = null): string
    {
        if (! $this->enabled()) {
            throw new PostValidationException(['brief' => ['El asistente IA no está activado.']]);
        }

        $brief = trim($brief);
        if ($brief === '' || mb_strlen($brief) > self::MAX_BRIEF) {
            throw new PostValidationException(['brief' => ['Describe la idea en '.self::MAX_BRIEF.' caracteres como máximo.']]);
        }

        $this->consume($user);

        try {
            $response = Http::timeout((int) config('social.ai.timeout', 30))->acceptJson()
                ->withHeaders(['x-api-key' => (string) config('social.ai.api_key'), 'anthropic-version' => self::VERSION])
                ->post(self::ENDPOINT, [
                    'model' => (string) config('social.ai.model', 'claude-sonnet-5-5'),
                    'max_tokens' => (int) config('social.ai.max_tokens', 600),
                    'system' => 'Eres un redactor de redes sociales. Escribe UN texto breve y natural para una publicación, en el idioma del usuario, sin inventar datos ni precios, sin hashtags excesivos. Responde solo con el texto de la publicación.',
                    'messages' => [['role' => 'user', 'content' => $this->prompt($this->redact($brief), $brandName, $network)]],
                ]);
        } catch (ConnectionException) {
            $this->refund($user);
            throw new PostValidationException(['brief' => ['El asistente no responde ahora mismo. Inténtalo de nuevo en unos minutos.']]);
        }

        $text = $response->successful() ? $this->extract($response->json()) : null;
        if ($text === null) {
            $this->refund($user);
            // Sin la clave ni el cuerpo: solo el estado.
            Log::warning('ai.suggest_failed', ['status' => $response->status()]);

            throw new PostValidationException(['brief' => [$response->status() === 429
                ? 'El asistente está saturado. Inténtalo de nuevo en unos minutos.'
                : 'El asistente no pudo generar el texto. Inténtalo de nuevo.']]);
        }

        return $text;
    }

    private function prompt(string $brief, ?string $brandName, ?string $network): string
    {
        $lines = [];
        if ($brandName !== null && trim($brandName) !== '') {
            $lines[] = 'Marca: '.mb_substr(trim($brandName), 0, 120);
        }
        if ($network !== null && in_array($network, ['instagram', 'facebook', 'youtube', 'tiktok'], true)) {
            $lines[] = 'Red de destino: '.$network;
        }
        $lines[] = 'Idea de la publicación: '.$brief;

        return implode("\n", $lines);
    }

    /** Quita emails, telefonos y enlaces del brief: no se envian a un tercero. */
    private function redact(string $brief): string
    {
        $brief = preg_replace('/[A-Za-z0-9._%+\-]+@[A-Za-z0-9.\-]+\.[A-Za-z]{2,}/u', '[email]', $brief) ?? $brief;
        $brief = preg_replace('~https?://\S+|www\.\S+~iu', '[enlace]', $brief) ?? $brief;

        return preg_replace('/\+?\d[\d\s().\-]{7,}\d/u', '[teléfono]', $brief) ?? $brief;
    }

    /** @param array<string, mixed>|null $json */
    private function extract(?array $json): ?string
    {
        foreach ((array) ($json['content'] ?? []) as $block) {
            if (($block['type'] ?? null) === 'text' && trim((string) ($block['text'] ?? '')) !== '') {
                return trim((string) $block['text']);
            }
        }

        return null;
    }

    private function key(User $user): string
    {
        return 'ai:'.$user->id.':'.now('UTC')->toDateString();
    }

    private function consume(User $user): void
    {
        $key = $this->key($user);
        Cache::add($key, 0, now('UTC')->endOfDay()->addMinutes(5));

        if (Cache::increment($key) > (int) config('social.ai.daily_limit', 20)) {
            Cache::decrement($key);
            throw new PostValidationException(['brief' => ['Has alcanzado el límite diario de sugerencias.']]);
        }
    }

    private function refund(User $user): void
    {
        Cache::decrement($this->key($user));
    }
}
