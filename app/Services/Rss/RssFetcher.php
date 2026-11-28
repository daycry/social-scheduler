<?php

namespace App\Services\Rss;

use App\Services\Media\UnsafeUrlException;
use App\Services\Media\UrlGuard;
use App\Services\Media\ValidatedUrl;
use App\Support\SafeUrl;
use DOMDocument;
use GuzzleHttp\Exception\TransferException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Psr\Http\Message\ResponseInterface;
use SimpleXMLElement;

/**
 * Lector de feeds RSS 2.0 / RSS 1.0 / Atom (T-88). La URL la escribe el usuario, asi que:
 *  - la peticion pasa por `UrlGuard` (IP publica validada y fijada, redirecciones seguidas a mano y revalidadas, sin proxy);
 *  - el cuerpo tiene tope (`social.rss.max_bytes`): se corta por `Content-Length` y, sin el, en cuanto lo descargado lo pasa, sin leerlo entero;
 *  - la peticion tiene limite de conexion y total (`social.rss.connect_timeout` / `timeout`);
 *  - el XML se parsea sin red ni sustitucion de entidades, y se rechaza cualquier documento con DTD (`doctype` del DOM ya parseado, valga cualquier
 *    codificacion, UTF-16 incluida): ni XXE ni expansion de entidades (billion laughs).
 * Las entradas salen ordenadas de la mas reciente a la mas antigua por su fecha (`pubDate`, `updated`, `published`, `dc:date`); las que no la traen
 * van detras, en el orden del documento. Devuelve entradas normalizadas; los fallos son `RssException` con un mensaje legible para el usuario.
 */
class RssFetcher
{
    /** Longitud maxima del titulo y del resumen que se conservan de cada entrada. */
    private const MAX_TITLE = 300;

    private const MAX_SUMMARY = 2000;

    public function __construct(private readonly UrlGuard $guard) {}

    /**
     * Descarga y lee el feed: las `social.rss.max_entries` entradas mas recientes por fecha (las sin fecha, detras, en el orden del documento).
     *
     * @return list<array{key: string, title: string, link: ?string, summary: string}> De la mas reciente a la mas antigua.
     *
     * @throws RssException
     */
    public function fetch(string $url): array
    {
        return $this->parse($this->download($url));
    }

    /**
     * @return list<array{key: string, title: string, link: ?string, summary: string}>
     *
     * @throws RssException
     */
    public function parse(string $xml): array
    {
        $invalid = new RssException('La dirección no es un feed válido (RSS o Atom).');

        if ($xml === '') {
            throw $invalid;
        }

        $previous = libxml_use_internal_errors(true);

        try {
            // Sin LIBXML_NOENT / DTDLOAD / DTDATTR: no se sustituyen entidades ni se carga ningun recurso. LIBXML_NONET: nunca toca la red.
            $dom = new DOMDocument;
            $loaded = $dom->loadXML($xml, LIBXML_NONET | LIBXML_NOCDATA | LIBXML_NOWARNING | LIBXML_NOERROR | LIBXML_COMPACT);
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }

        // Un feed legitimo casi nunca lleva DOCTYPE; con DTD (interno o externo, en la codificacion que sea) no se lee.
        if (! $loaded || $dom->doctype !== null) {
            throw $invalid;
        }

        $root = simplexml_import_dom($dom);

        if (! $root instanceof SimpleXMLElement || ! in_array(strtolower($root->getName()), ['rss', 'rdf', 'feed'], true)) {
            throw $invalid;
        }

        $entries = [];
        foreach ($root->xpath("//*[local-name()='item' or local-name()='entry']") ?: [] as $node) {
            $entry = $this->entry($node);
            if ($entry !== null && ! isset($entries[$entry['key']])) { // un guid repetido dentro del feed cuenta una vez
                $entries[$entry['key']] = $entry + ['order' => count($entries)];
            }
        }

        // Mas reciente primero; empate o sin fecha: orden del documento (las sin fecha, tras las fechadas). Despues se aplica el tope de entradas.
        $sorted = array_values($entries);
        usort($sorted, fn (array $a, array $b) => match (true) {
            $a['at'] !== null && $b['at'] !== null && $a['at'] !== $b['at'] => $b['at'] <=> $a['at'],
            ($a['at'] === null) !== ($b['at'] === null) => $a['at'] === null ? 1 : -1,
            default => $a['order'] <=> $b['order'],
        });

        return array_map(
            fn (array $e) => ['key' => $e['key'], 'title' => $e['title'], 'link' => $e['link'], 'summary' => $e['summary']],
            array_slice($sorted, 0, max(1, (int) config('social.rss.max_entries', 20))),
        );
    }

    /** @throws RssException */
    private function download(string $url): string
    {
        $cap = max(1024, (int) config('social.rss.max_bytes', 1_048_576));
        $exceeded = false;

        try {
            $response = $this->guard->follow($url, function (ValidatedUrl $v) use ($cap, &$exceeded): Response {
                $http = Http::withHeaders(['Accept' => 'application/rss+xml, application/atom+xml, application/xml;q=0.9, text/xml;q=0.8'])
                    ->connectTimeout(max(1, (int) config('social.rss.connect_timeout', 5)))
                    ->timeout(max(1, (int) config('social.rss.timeout', 10)))
                    ->withoutRedirecting()
                    // Opciones nativas de Guzzle: no cambian el manejador cURL, asi que la IP fijada sigue aplicandose (`stream => true` la rechazaria).
                    ->withOptions($this->guard->requestOptions($v) + [
                        'on_headers' => function (ResponseInterface $response) use ($cap, &$exceeded): void {
                            if ((int) $response->getHeaderLine('Content-Length') > $cap) {
                                $exceeded = true;

                                throw new RssException('El feed es demasiado grande.');
                            }
                        },
                        // Abortar desde el callback de cURL llega como un fallo de conexion: `$exceeded` distingue el corte por tamano.
                        'progress' => function ($downloadTotal, $downloaded) use ($cap, &$exceeded): void {
                            if ($downloaded > $cap) {
                                $exceeded = true;

                                throw new RssException('El feed es demasiado grande.');
                            }
                        },
                    ]);

                return $http->get($v->url);
            });
        } catch (UnsafeUrlException) {
            throw new RssException('La dirección del feed no está permitida.');
        } catch (ConnectionException) {
            throw $exceeded ? new RssException('El feed es demasiado grande.') : new RssException('El feed no respondió a tiempo o no se pudo contactar.');
        } catch (RequestException $e) {
            throw new RssException('El feed respondió con un error ('.$e->response->status().').');
        } catch (TransferException) {
            // Fallo del cliente HTTP a mitad de la respuesta (p. ej. un callback de tope que lo aborta).
            throw $exceeded ? new RssException('El feed es demasiado grande.') : new RssException('El feed no se pudo leer.');
        }

        if ($response->status() >= 300 && $response->status() < 400) {
            throw new RssException('El feed redirige demasiadas veces o a un destino no válido.');
        }
        if ($response->failed()) {
            throw new RssException('El feed respondió con un error ('.$response->status().').');
        }
        if ((int) $response->header('Content-Length') > $cap || strlen($response->body()) > $cap) {
            throw new RssException('El feed es demasiado grande.');
        }

        return $response->body();
    }

    /** @return array{key: string, title: string, link: ?string, summary: string, at: ?int}|null */
    private function entry(SimpleXMLElement $node): ?array
    {
        $title = $this->text($this->child($node, 'title'), self::MAX_TITLE);
        $summary = $this->text($this->child($node, 'summary') ?? $this->child($node, 'description') ?? $this->child($node, 'content'), self::MAX_SUMMARY);
        $link = $this->link($node);
        $id = trim((string) ($this->child($node, 'guid') ?? $this->child($node, 'id') ?? ''));

        if ($title === '' && $summary === '' && $link === null) {
            return null;
        }

        // Identidad estable: guid/id de la entrada; si no hay, su enlace; si no, el contenido (titulo + resumen). Con prefijo para que no choquen.
        $key = $id !== '' ? 'id:'.$id : ($link !== null ? 'link:'.$link : 'hash:'.$title."\n".$summary);

        return [
            'key' => hash('sha256', $key), 'title' => $title !== '' ? $title : mb_substr($summary, 0, self::MAX_TITLE), 'link' => $link, 'summary' => $summary,
            'at' => $this->date($node),
        ];
    }

    /** Fecha de la entrada como marca de tiempo (`pubDate`, `updated`, `published` o `dc:date`); null si no hay o no se entiende. */
    private function date(SimpleXMLElement $node): ?int
    {
        foreach (['pubDate', 'published', 'updated', 'date'] as $name) {
            $value = trim((string) ($this->child($node, $name) ?? ''));
            if ($value !== '' && ($time = strtotime($value)) !== false) {
                return $time;
            }
        }

        return null;
    }

    private function child(SimpleXMLElement $node, string $name): ?SimpleXMLElement
    {
        $found = $node->xpath("./*[local-name()='{$name}']");

        return $found ? $found[0] : null;
    }

    /** Enlace http(s) de la entrada: el texto de `<link>` (RSS) o el `href` del `<link rel="alternate">` (Atom). Cualquier otro esquema se descarta. */
    private function link(SimpleXMLElement $node): ?string
    {
        foreach ($node->xpath("./*[local-name()='link']") ?: [] as $link) {
            $rel = strtolower(trim((string) ($link['rel'] ?? '')));
            if ($rel !== '' && $rel !== 'alternate') {
                continue;
            }
            $href = trim((string) ($link['href'] ?? '') !== '' ? (string) $link['href'] : (string) $link);
            if (SafeUrl::isSafe($href)) {
                return $href;
            }
        }

        return null;
    }

    /** Texto plano: sin etiquetas HTML ni entidades, con los espacios colapsados y recortado. */
    private function text(?SimpleXMLElement $node, int $max): string
    {
        if ($node === null) {
            return '';
        }

        $text = html_entity_decode(strip_tags((string) $node), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        // Fuera controles, marcas de direccion que reordenan el texto (override/isolate), espacios de anchura cero y BOM; se conservan ZWJ, selectores de
        // variacion y etiquetas (emojis compuestos y banderas).
        $text = (string) preg_replace('/[\x00-\x08\x0B-\x1F\x7F-\x9F\x{200B}\x{2060}\x{FEFF}\x{202A}-\x{202E}\x{2066}-\x{2069}]/u', '', $text);
        $text = (string) preg_replace('/\s+/u', ' ', $text);

        return mb_substr(trim($text), 0, $max);
    }
}
