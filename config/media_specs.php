<?php

/*
|--------------------------------------------------------------------------
| Reglas de media por red y tipo de contenido (15)
|--------------------------------------------------------------------------
|
| Versionadas aqui: cuando una red cambia un limite se edita este fichero (y `version`).
| Fuente: documentacion oficial de cada API, revisada 2026-10 (VERIFICAR contra la doc vigente al
| tocar un limite: Instagram Content Publishing, Facebook Pages/Reels API, YouTube Data API, TikTok
| Content Posting API). Lo ya medido en el spike (spike-report.md): TikTok exige >= 23 fps
| (`frame_rate_check_failed`); Graph v23.0.
|
| Por tipo:
|   label        nombre legible en los mensajes
|   media        count (min/max de elementos) y kinds permitidos (image|video)
|   text         max (caracteres), required y max_hashtags (opcional; Instagram 30, el resto sin tope); count (chars|graphemes|threads,
|                por defecto chars), max_bytes (opcional) y required_without_media (texto obligatorio si no hay media)
|   first_comment max (caracteres) del primer comentario; ausente = el tipo no lo admite (TikTok y Stories)
|   recommended  recomendaciones por clase de media (mismas claves min/max_*): solo avisos, nunca bloquean
|   image|video  reglas por clase de media: mimes, max_bytes, min/max_width, min/max_height,
|                aspect (ancho/alto) min|max, min/max_duration (s), min/max_fps, codecs, audio_codecs
*/

$jpeg = ['image/jpeg'];
$mb = 1024 * 1024;

return [

    'version' => '2026-10-19',

    'types' => [

        // ---------------------------------------------------------------- Instagram
        'instagram_image' => [
            'label' => 'una publicacion de imagen de Instagram',
            'media' => ['min' => 1, 'max' => 1, 'kinds' => ['image']],
            'text' => ['max' => 2200, 'required' => false, 'max_hashtags' => 30],
            'first_comment' => ['max' => 2200],
            // La API solo admite JPEG; relacion 4:5 a 1.91:1; ancho minimo 320 px; 8 MB. Sin ancho maximo: IG reescala a 1440 px
            // (una foto de movil de 4032x3024 es valida).
            'image' => ['mimes' => $jpeg, 'max_bytes' => 8 * $mb, 'min_width' => 320, 'aspect' => ['min' => 0.8, 'max' => 1.91]],
        ],
        'instagram_carousel' => [
            'label' => 'un carrusel de Instagram',
            'media' => ['min' => 2, 'max' => 10, 'kinds' => ['image', 'video']],
            'text' => ['max' => 2200, 'required' => false, 'max_hashtags' => 30],
            'first_comment' => ['max' => 2200],
            'image' => ['mimes' => $jpeg, 'max_bytes' => 8 * $mb, 'min_width' => 320, 'aspect' => ['min' => 0.8, 'max' => 1.91]],
            'video' => ['mimes' => ['video/mp4', 'video/quicktime'], 'max_bytes' => 100 * $mb, 'min_duration' => 3, 'max_duration' => 60, 'min_fps' => 23, 'max_fps' => 60, 'aspect' => ['min' => 0.8, 'max' => 1.91]],
        ],
        'instagram_reel' => [
            'label' => 'un reel de Instagram',
            'media' => ['min' => 1, 'max' => 1, 'kinds' => ['video']],
            'text' => ['max' => 2200, 'required' => false, 'max_hashtags' => 30],
            'first_comment' => ['max' => 2200],
            // 3 s a 15 min, hasta 300 MB, 9:16 recomendado (se admite 0.01:1 a 10:1), minimo 540x960 recomendado.
            'video' => ['mimes' => ['video/mp4', 'video/quicktime'], 'max_bytes' => 300 * $mb, 'min_duration' => 3, 'max_duration' => 900, 'min_fps' => 23, 'max_fps' => 60, 'aspect' => ['min' => 0.01, 'max' => 10], 'codecs' => ['h264', 'hevc'], 'audio_codecs' => ['aac']],
            // Recomendaciones: avisan en la vista previa pero NO bloquean (la red las acepta aunque degrade la calidad).
            'recommended' => ['video' => ['min_width' => 540, 'min_height' => 960]],
        ],
        'instagram_story_image' => [
            'label' => 'una historia de Instagram',
            'media' => ['min' => 1, 'max' => 1, 'kinds' => ['image', 'video']],
            'text' => ['max' => 2200, 'required' => false, 'max_hashtags' => 30],
            'image' => ['mimes' => $jpeg, 'max_bytes' => 8 * $mb, 'min_width' => 320, 'aspect' => ['min' => 0.1, 'max' => 10]],
            'video' => ['mimes' => ['video/mp4', 'video/quicktime'], 'max_bytes' => 100 * $mb, 'min_duration' => 3, 'max_duration' => 60, 'min_fps' => 23, 'max_fps' => 60, 'aspect' => ['min' => 0.1, 'max' => 10]],
        ],
        'instagram_story_video' => [
            'label' => 'una historia de Instagram',
            'media' => ['min' => 1, 'max' => 1, 'kinds' => ['image', 'video']],
            'text' => ['max' => 2200, 'required' => false, 'max_hashtags' => 30],
            'image' => ['mimes' => $jpeg, 'max_bytes' => 8 * $mb, 'min_width' => 320, 'aspect' => ['min' => 0.1, 'max' => 10]],
            'video' => ['mimes' => ['video/mp4', 'video/quicktime'], 'max_bytes' => 100 * $mb, 'min_duration' => 3, 'max_duration' => 60, 'min_fps' => 23, 'max_fps' => 60, 'aspect' => ['min' => 0.1, 'max' => 10]],
        ],

        // ---------------------------------------------------------------- Facebook (paginas)
        'facebook_text' => [
            'label' => 'una publicacion de texto o enlace de Facebook',
            'media' => ['min' => 0, 'max' => 0, 'kinds' => []],
            'text' => ['max' => 63206, 'required' => true],
            'first_comment' => ['max' => 8000],
        ],
        'facebook_photo' => [
            'label' => 'una foto de Facebook',
            'media' => ['min' => 1, 'max' => 1, 'kinds' => ['image']],
            'text' => ['max' => 63206, 'required' => false],
            'first_comment' => ['max' => 8000],
            'image' => ['mimes' => ['image/jpeg', 'image/png', 'image/webp'], 'max_bytes' => 10 * $mb],
        ],
        'facebook_video' => [
            'label' => 'un video de Facebook',
            'media' => ['min' => 1, 'max' => 1, 'kinds' => ['video']],
            'text' => ['max' => 63206, 'required' => false],
            'first_comment' => ['max' => 8000],
            // Subida por URL (file_url): hasta 1 GB y 20 min.
            'video' => ['mimes' => ['video/mp4', 'video/quicktime'], 'max_bytes' => 1024 * $mb, 'min_duration' => 1, 'max_duration' => 1200],
        ],
        'facebook_reel' => [
            'label' => 'un reel de Facebook',
            'media' => ['min' => 1, 'max' => 1, 'kinds' => ['video']],
            'text' => ['max' => 63206, 'required' => false],
            'first_comment' => ['max' => 8000],
            // 3 a 90 s, 9:16, minimo 540x960, 24 a 60 fps.
            'video' => ['mimes' => ['video/mp4', 'video/quicktime'], 'max_bytes' => 1024 * $mb, 'min_duration' => 3, 'max_duration' => 90, 'min_fps' => 24, 'max_fps' => 60, 'min_width' => 540, 'min_height' => 960, 'aspect' => ['min' => 0.5, 'max' => 0.6]],
        ],

        // ---------------------------------------------------------------- YouTube
        'youtube_video' => [
            'label' => 'un video de YouTube',
            'media' => ['min' => 1, 'max' => 1, 'kinds' => ['video']],
            'text' => ['max' => 5000, 'required' => false],
            'first_comment' => ['max' => 10000],
            'video' => ['mimes' => ['video/mp4', 'video/quicktime'], 'max_bytes' => 4096 * $mb, 'min_duration' => 1, 'max_duration' => 43200],
        ],
        'youtube_short' => [
            'label' => 'un Short de YouTube',
            'media' => ['min' => 1, 'max' => 1, 'kinds' => ['video']],
            'text' => ['max' => 5000, 'required' => false],
            'first_comment' => ['max' => 10000],
            // Hasta 3 minutos y relacion vertical o cuadrada.
            'video' => ['mimes' => ['video/mp4', 'video/quicktime'], 'max_bytes' => 4096 * $mb, 'min_duration' => 1, 'max_duration' => 180, 'aspect' => ['min' => 0.1, 'max' => 1.0]],
        ],

        // ---------------------------------------------------------------- TikTok
        'tiktok_video' => [
            'label' => 'un video de TikTok',
            'media' => ['min' => 1, 'max' => 1, 'kinds' => ['video']],
            'text' => ['max' => 2200, 'required' => false],
            // 3 s a 10 min (max_video_post_duration_sec 600 en el spike), >= 23 fps (frame_rate_check_failed), H.264.
            'video' => ['mimes' => ['video/mp4', 'video/quicktime'], 'max_bytes' => 4096 * $mb, 'min_duration' => 3, 'max_duration' => 600, 'min_fps' => 23, 'max_fps' => 60, 'min_width' => 360, 'max_width' => 4096, 'min_height' => 360, 'max_height' => 4096, 'codecs' => ['h264', 'hevc', 'vp9']],
        ],
        'tiktok_photo' => [
            'label' => 'un carrusel de fotos de TikTok',
            'media' => ['min' => 1, 'max' => 35, 'kinds' => ['image']],
            'text' => ['max' => 4000, 'required' => false],
            // Solo JPEG/WebP (no PNG), hasta 20 MB por foto.
            'image' => ['mimes' => ['image/jpeg', 'image/webp'], 'max_bytes' => 20 * $mb, 'max_width' => 1080 * 4, 'max_height' => 1920 * 4],
        ],

        // ---------------------------------------------------------------- Bluesky
        // Texto: 300 GRAFEMAS (`count` => graphemes) y 3000 bytes UTF-8 (lexicon app.bsky.feed.post). Un post necesita texto o media.
        // El tope de bytes de imagen y video es de esta instalacion (`social.bluesky.*_max_bytes`, ver config/social.php) y se lee en
        // tiempo de validacion: una imagen mayor se recomprime en una copia (T-22) y un video mayor se rechaza al guardar.
        'bluesky_post' => [
            'label' => 'una publicacion de Bluesky',
            'media' => ['min' => 0, 'max' => 4, 'kinds' => ['image']],
            'text' => ['max' => 300, 'max_bytes' => 3000, 'count' => 'graphemes', 'required' => false, 'required_without_media' => true],
            'image' => ['mimes' => ['image/jpeg', 'image/png', 'image/webp'], 'recompress_to_config' => 'social.bluesky.image_max_bytes'],
        ],
        'bluesky_video' => [
            'label' => 'un video de Bluesky',
            'media' => ['min' => 1, 'max' => 1, 'kinds' => ['video']],
            'text' => ['max' => 300, 'max_bytes' => 3000, 'count' => 'graphemes', 'required' => false],
            // MP4; hasta 3 min (limite de Bluesky; getUploadLimits puede ser menor y se comprueba al publicar).
            'video' => [
                'mimes' => ['video/mp4'],
                'max_bytes_config' => 'social.bluesky.video_max_bytes',
                'too_big_message' => 'Bluesky solo admite videos de hasta :max en esta instalacion; este pesa :size.',
                'min_duration' => 1, 'max_duration' => 180,
            ],
        ],
    ],
];
