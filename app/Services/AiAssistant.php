<?php

namespace App\Services;

use App\Connectors\Network;
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

        $keys = $this->consume($user);

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
            $this->refund($keys);
            throw new PostValidationException(['brief' => ['El asistente no responde ahora mismo. Inténtalo de nuevo en unos minutos.']]);
        }

        $text = $response->successful() ? $this->extract($response->json()) : null;
        if ($text === null) {
            $this->refund($keys);
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
        if ($network !== null && Network::tryFrom($network) !== null) {
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

    private function key(User $user, ?string $day = null): string
    {
        return 'ai:'.$user->id.':'.($day ?? now('UTC')->toDateString());
    }

    private function globalKey(string $day): string
    {
        return 'ai:global:'.$day;
    }

    /**
     * Reserva una sugerencia: tope diario por usuario y tope GLOBAL (`AI_ASSISTANT_GLOBAL_DAILY_LIMIT`, 0 = sin tope: protege
     * el gasto aunque haya varias cuentas). Devuelve las claves del dia en que se reservo, para devolverlas aunque cambie el dia.
     *
     * @return array{0: string, 1: string}
     */
    private function consume(User $user): array
    {
        $day = now('UTC')->toDateString();
        $keys = [$this->key($user, $day), $this->globalKey($day)];
        $ttl = now('UTC')->endOfDay()->addMinutes(5);
        foreach ($keys as $key) {
            Cache::add($key, 0, $ttl);
        }

        if (Cache::increment($keys[0]) > (int) config('social.ai.daily_limit', 20)) {
            $this->refund([$keys[0]]);
            throw new PostValidationException(['brief' => ['Has alcanzado el límite diario de sugerencias.']]);
        }

        $global = (int) config('social.ai.global_daily_limit', 0);
        if (Cache::increment($keys[1]) > $global && $global > 0) {
            $this->refund($keys);
            throw new PostValidationException(['brief' => ['Se alcanzó el límite diario del asistente. Inténtalo mañana.']]);
        }

        return $keys;
    }

    /** Devuelve una reserva SIN pasar nunca de cero (la clave puede haber caducado o ser de un dia anterior). @param list<string> $keys */
    private function refund(array $keys): void
    {
        foreach ($keys as $key) {
            if ((int) Cache::get($key, 0) > 0) {
                Cache::decrement($key);
            }
        }
    }
}
