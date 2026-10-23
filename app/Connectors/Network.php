<?php

namespace App\Connectors;

/**
 * Redes soportadas. Instagram y Facebook comparten proveedor OAuth (Meta).
 */
enum Network: string
{
    case Instagram = 'instagram';
    case Facebook = 'facebook';
    case YouTube = 'youtube';
    case TikTok = 'tiktok';

    public function provider(): string
    {
        return match ($this) {
            self::Instagram, self::Facebook => 'meta',
            self::YouTube => 'google',
            self::TikTok => 'tiktok',
        };
    }

    public function label(): string
    {
        return match ($this) {
            self::Instagram => 'Instagram',
            self::Facebook => 'Facebook',
            self::YouTube => 'YouTube',
            self::TikTok => 'TikTok',
        };
    }

    /** @return list<string> */
    public static function providers(): array
    {
        return ['meta', 'google', 'tiktok'];
    }
}
