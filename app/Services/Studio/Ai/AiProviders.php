<?php

namespace App\Services\Studio\Ai;

use App\Models\Studio\StudioAiJob;
use App\Models\User;

/** Proveedores de imagen configurados (OpenAI, Google) y su cuota diaria por usuario. Una clave vacia deja el proveedor «no configurado». */
class AiProviders
{
    /** @var array<string, class-string<AbstractImageProvider>> */
    private const CLASSES = ['openai' => OpenAiImages::class, 'google' => GoogleImages::class];

    /** @return array<string, ImageProvider> todos los proveedores conocidos, configurados o no */
    public function all(): array
    {
        $providers = [];
        foreach (self::CLASSES as $id => $class) {
            $config = (array) config('studio.ai.providers.'.$id, []);
            $providers[$id] = new $class((string) ($config['key'] ?? ''), $config);
        }

        return $providers;
    }

    public function get(string $id): ?ImageProvider
    {
        return $this->all()[$id] ?? null;
    }

    public function anyConfigured(): bool
    {
        return array_filter($this->all(), fn (ImageProvider $p) => $p->configured()) !== [];
    }

    public function limit(User $user, string $provider): int
    {
        $override = config('studio.ai.user_limits.'.$user->id.'.'.$provider);

        return max(0, (int) ($override ?? config('studio.ai.providers.'.$provider.'.daily_limit', 20)));
    }

    /** Imagenes pedidas hoy a ese proveedor (los trabajos fallidos no consumen cuota). */
    public function used(User $user, string $provider): int
    {
        return (int) StudioAiJob::withoutUserScope()->where('user_id', $user->id)->where('provider', $provider)
            // Un fallo ambiguo (timeout tras enviar) puede haberse cobrado: no devuelve cuota. El resto de fallos si.
            ->where(fn ($q) => $q->where('status', '!=', 'failed')->orWhere('error_code', 'timeout_ambiguous'))
            ->where('created_at', '>=', now()->startOfDay())->sum('count');
    }
}
