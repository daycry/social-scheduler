<?php

namespace App\Support;

use App\Models\Site;

/**
 * Resuelve las URL de un sitio para el MISMO render (publico o vista previa): paginas, imagenes y acciones de formulario.
 * La vista previa apunta a rutas autenticadas del borrador; lo publicado, a `/s/{slug}`. Una imagen que no esta en la lista
 * permitida del documento (instantanea publicada o borrador) no tiene URL: nunca se sirve media ajena.
 */
final class SiteView
{
    /** @param list<int> $media */
    public function __construct(private readonly Site $site, private readonly array $doc, private readonly bool $preview, private readonly array $media) {}

    public function pageUrl(string $key, bool $absolute = false): string
    {
        $index = array_search($key, array_column($this->doc['pages'], 'key'), true);
        $slug = $index === false ? null : $this->doc['pages'][$index]['slug'];
        if ($this->preview) {
            return route('sites.preview', ['site' => $this->site->id, 'page' => $slug], $absolute);
        }
        $path = '/s/'.$this->site->slug.($index === 0 || $slug === null ? '' : '/'.$slug);

        return $absolute ? url($path) : $path;
    }

    public function mediaUrl(int $id): ?string
    {
        if (! in_array($id, $this->media, true)) {
            return null;
        }

        return $this->preview ? route('sites.preview-media', ['site' => $this->site->id, 'media' => $id], false) : '/s/'.$this->site->slug.'/_m/'.$id;
    }

    public function action(string $kind, string $pageKey, string $blockKey): string
    {
        return $this->preview ? '#' : '/s/'.$this->site->slug.'/_'.($kind === 'vote' ? 'vote' : 'contact').'/'.$pageKey.'/'.$blockKey;
    }

    /** Marca de tiempo firmada del formulario (anti-bot): se rechaza un envio inmediato o de una pagina muy vieja. */
    public function formToken(string $blockKey): string
    {
        return self::token($this->site, $blockKey, now()->getTimestamp());
    }

    public static function token(Site $site, string $blockKey, int $time): string
    {
        return $time.'.'.hash_hmac('sha256', 'form|'.$site->id.'|'.$blockKey.'|'.$time, (string) config('app.key'));
    }

    public static function tokenAge(Site $site, string $blockKey, string $token): ?int
    {
        if (preg_match('/^(\d{9,11})\.([a-f0-9]{64})$/', $token, $m) !== 1 || ! hash_equals(self::token($site, $blockKey, (int) $m[1]), $token)) {
            return null;
        }

        return now()->getTimestamp() - (int) $m[1];
    }
}
