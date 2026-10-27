<?php

namespace App\Connectors;

/**
 * Catalogo de redes soportadas. Es la fuente unica de etiqueta, color, proveedor y forma de conexion:
 * el shell de Inertia, las rutas, la IA y las descripciones del MCP leen de aqui. Los mapas de datos
 * por red (reglas de media, metricas, presets) se quedan en su sitio y los protege NetworkCoverageTest.
 *
 * Instagram y Facebook comparten proveedor OAuth (Meta).
 */
enum Network: string
{
    case Instagram = 'instagram';
    case Facebook = 'facebook';
    case YouTube = 'youtube';
    case TikTok = 'tiktok';
    case Bluesky = 'bluesky';
    case Threads = 'threads';

    /** Conexion por redireccion OAuth. */
    public const AUTH_OAUTH = 'oauth';

    /** Conexion con credenciales escritas por el usuario (handle + app password). */
    public const AUTH_CREDENTIALS = 'credentials';

    public function provider(): string
    {
        return match ($this) {
            self::Instagram, self::Facebook => 'meta',
            self::YouTube => 'google',
            self::TikTok => 'tiktok',
            self::Bluesky => 'bluesky',
            self::Threads => 'threads',
        };
    }

    public function label(): string
    {
        return match ($this) {
            self::Instagram => 'Instagram',
            self::Facebook => 'Facebook',
            self::YouTube => 'YouTube',
            self::TikTok => 'TikTok',
            self::Bluesky => 'Bluesky',
            self::Threads => 'Threads',
        };
    }

    /** Nombre corto para pestanas y filtros estrechos. */
    public function shortLabel(): string
    {
        return match ($this) {
            self::Instagram => 'IG',
            self::Facebook => 'FB',
            self::YouTube => 'YT',
            self::TikTok => 'TT',
            self::Bluesky => 'BS',
            self::Threads => 'TH',
        };
    }

    /** Color de marca de la red (hex). */
    public function color(): string
    {
        return match ($this) {
            self::Instagram => '#db2777',
            self::Facebook => '#2563eb',
            self::YouTube => '#dc2626',
            self::TikTok => '#111827',
            self::Bluesky => '#0a7aff',
            self::Threads => '#000000',
        };
    }

    /** Como se conecta: `oauth` (redireccion) o `credentials` (formulario). */
    public function authKind(): string
    {
        return match ($this) {
            self::Bluesky => self::AUTH_CREDENTIALS,
            default => self::AUTH_OAUTH,
        };
    }

    /** @return list<string> Proveedores de todas las redes del catalogo. */
    public static function providers(): array
    {
        return array_values(array_unique(array_map(fn (self $n) => $n->provider(), self::cases())));
    }

    /**
     * Redes que esta instalacion puede ofrecer: las que tienen conector registrado (p. ej. Threads
     * desaparece si falta su app de Meta).
     *
     * @return list<self>
     */
    public static function offered(): array
    {
        $registry = app(ConnectorRegistry::class);

        return array_values(array_filter(self::cases(), fn (self $n) => $registry->has($n->provider())));
    }

    /**
     * ¿Se puede usar esta red en esta instalacion? Bluesky se apaga con `BLUESKY_ENABLED=false` y Threads con `THREADS_ENABLED=false` o
     * sin `THREADS_APP_ID` (red no configurada): sus cuentas siguen conectadas, pero no se pueden elegir en el compositor, ni reprogramar,
     * ni usar desde el MCP, y la red no sale en Conexiones ni en los filtros.
     */
    public function isAvailable(): bool
    {
        return match ($this) {
            self::Bluesky => (bool) config('social.bluesky.enabled', true),
            self::Threads => (bool) config('social.threads.enabled', true) && (string) config('services.threads.client_id') !== '',
            default => true,
        };
    }

    /** Descripcion para el MCP y la IA: «instagram, facebook, youtube o tiktok» (solo las redes disponibles en esta instalacion). */
    public static function listing(): string
    {
        $values = array_map(fn (self $n) => $n->value, array_values(array_filter(self::cases(), fn (self $n) => $n->isAvailable())));
        $last = array_pop($values);

        return $values ? implode(', ', $values).' o '.$last : $last;
    }

    /**
     * Datos publicos del catalogo para el navegador.
     *
     * @return array{value: string, label: string, short_label: string, color: string, provider: string, auth_kind: string}
     */
    public function toArray(): array
    {
        return [
            'value' => $this->value,
            'label' => $this->label(),
            'short_label' => $this->shortLabel(),
            'color' => $this->color(),
            'provider' => $this->provider(),
            'auth_kind' => $this->authKind(),
        ];
    }
}
