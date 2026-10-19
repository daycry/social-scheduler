<?php

namespace App\Enums;

use App\Connectors\Network;

/**
 * Tipos de contenido nativos que se pueden programar (CA-06). La historia de Instagram admite imagen
 * o video: para validar se resuelve a su variante segun la media (13 reglas de `config/media_specs.php`).
 */
enum ContentType: string
{
    case InstagramImage = 'instagram_image';
    case InstagramCarousel = 'instagram_carousel';
    case InstagramReel = 'instagram_reel';
    case InstagramStory = 'instagram_story';
    case FacebookText = 'facebook_text';
    case FacebookPhoto = 'facebook_photo';
    case FacebookVideo = 'facebook_video';
    case FacebookReel = 'facebook_reel';
    case YouTubeVideo = 'youtube_video';
    case YouTubeShort = 'youtube_short';
    case TikTokVideo = 'tiktok_video';
    case TikTokPhoto = 'tiktok_photo';

    public function network(): Network
    {
        return match ($this) {
            self::InstagramImage, self::InstagramCarousel, self::InstagramReel, self::InstagramStory => Network::Instagram,
            self::FacebookText, self::FacebookPhoto, self::FacebookVideo, self::FacebookReel => Network::Facebook,
            self::YouTubeVideo, self::YouTubeShort => Network::YouTube,
            self::TikTokVideo, self::TikTokPhoto => Network::TikTok,
        };
    }

    public function label(): string
    {
        return match ($this) {
            self::InstagramImage => 'Publicacion de imagen',
            self::InstagramCarousel => 'Carrusel',
            self::InstagramReel => 'Reel',
            self::InstagramStory => 'Historia',
            self::FacebookText => 'Texto o enlace',
            self::FacebookPhoto => 'Foto',
            self::FacebookVideo => 'Video',
            self::FacebookReel => 'Reel',
            self::YouTubeVideo => 'Video',
            self::YouTubeShort => 'Short',
            self::TikTokVideo => 'Video',
            self::TikTokPhoto => 'Fotos (carrusel)',
        };
    }

    /**
     * Limitaciones de la API de la red que el compositor avisa al elegir el tipo (T-21).
     *
     * @return list<string>
     */
    public function limitations(): array
    {
        return match ($this) {
            self::InstagramImage => ['Instagram solo acepta imagenes JPEG; las PNG o WebP se rechazan.'],
            self::InstagramCarousel => ['El carrusel necesita entre 2 y 10 archivos; todos se recortan a la proporcion del primero.'],
            self::InstagramReel => ['El reel se publica tambien en el feed salvo que lo desactives; el procesamiento del video puede tardar unos minutos.'],
            self::InstagramStory => ['Las historias no admiten texto, enlaces ni stickers desde la API: solo se publica la imagen o el video (maximo 60 s).'],
            self::YouTubeVideo => ['La visibilidad elegida se respeta tal cual. Cada subida gasta ~1.600 de las 10.000 unidades diarias de la API (unas 6 subidas al dia).'],
            self::YouTubeShort => ['Video vertical de hasta 3 minutos; se anade #Shorts a la descripcion. Cada subida gasta ~1.600 de las 10.000 unidades diarias de la API.'],
            self::TikTokVideo, self::TikTokPhoto => ['Sin auditoria de TikTok el contenido se envia como borrador a la bandeja de la app: el usuario termina de publicarlo desde TikTok.'],
            default => [],
        };
    }

    /**
     * Clave de la regla en `config/media_specs.php`.
     *
     * @param  string|null  $firstMediaKind  'image' | 'video' (solo importa en las historias de Instagram).
     */
    public function specKey(?string $firstMediaKind = null): string
    {
        if ($this === self::InstagramStory) {
            return $firstMediaKind === 'video' ? 'instagram_story_video' : 'instagram_story_image';
        }

        return $this->value;
    }

    /**
     * ¿Se ofrece en el compositor? Las fotos de TikTok solo existen por PULL_FROM_URL, que exige un dominio
     * verificado en TikTok (`social.tiktok.verified_domain`); sin el, el tipo no se ofrece ni se acepta.
     */
    public function isOffered(): bool
    {
        return $this !== self::TikTokPhoto || (string) config('social.tiktok.verified_domain') !== '';
    }

    /** @return list<self> */
    public static function forNetwork(Network $network): array
    {
        return array_values(array_filter(self::cases(), fn (self $t) => $t->network() === $network));
    }
}
