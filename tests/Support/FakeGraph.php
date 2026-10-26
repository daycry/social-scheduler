<?php

namespace Tests\Support;

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Graph API simulada (sin red): enruta por metodo + ruta, devuelve respuestas en secuencia y registra las
 * llamadas (`calls`) para comprobar parametros. Una ruta sin respuesta devuelve 404 para que el test falle a la vista.
 */
class FakeGraph
{
    /** @var list<array{method: string, path: string, params: array<string, mixed>, authorization: ?string, url: string}> */
    public array $calls = [];

    /** @var list<array{method: string, pattern: string, queue: list<mixed>}> */
    private array $routes = [];

    public function __construct()
    {
        Http::fake(fn (Request $request) => $this->handle($request));
    }

    /**
     * @param  mixed  $response  array JSON | Throwable | lista de respuestas (secuencia) envuelta en `seq()`.
     */
    public function on(string $method, string $pattern, mixed $response): static
    {
        $this->routes[] = ['method' => strtoupper($method), 'pattern' => $pattern, 'queue' => $response instanceof Sequence ? $response->items : [$response]];

        return $this;
    }

    public static function seq(mixed ...$items): Sequence
    {
        return new Sequence($items);
    }

    /** Pagina de comentarios de Graph con `$n` ajenos (ids X<from>..), mas los `$own` dados, y cursor a la siguiente si `$next`. */
    public static function commentsPage(int $n, int $from, bool $next = true, array $own = []): array
    {
        $data = [];
        for ($i = 0; $i < $n; $i++) {
            $id = 'X'.($from + $i);
            $data[] = ['id' => $id, 'message' => 'ruido '.$id, 'text' => 'ruido '.$id, 'username' => 'otro', 'from' => ['id' => 'OTRO', 'name' => 'Otro'], 'created_time' => '2026-11-01T10:00:00+0000', 'timestamp' => '2026-11-01T10:00:00+0000'];
        }

        return ['data' => array_merge($data, $own), 'paging' => array_filter(['cursors' => ['after' => 'CUR'.$from], 'next' => $next ? 'https://graph.facebook.com/next' : null])];
    }

    /** Respuesta de error de Graph. */
    public static function error(int $code, string $message, ?int $subcode = null, int $status = 400): array
    {
        return ['__status' => $status, 'error' => array_filter(['code' => $code, 'message' => $message, 'error_subcode' => $subcode], fn ($v) => $v !== null)];
    }

    /** @return list<array{method: string, path: string, params: array<string, mixed>}> */
    public function callsTo(string $method, string $pattern): array
    {
        return array_values(array_filter($this->calls, fn ($c) => $c['method'] === strtoupper($method) && preg_match($pattern, $c['path'])));
    }

    private function handle(Request $request)
    {
        $url = parse_url($request->url());
        $path = ltrim(preg_replace('#^/v[\d.]+/#', '', $url['path'] ?? ''), '/');
        parse_str($url['query'] ?? '', $query);
        $params = $request->method() === 'GET' ? $query : (array) $request->data();
        $this->calls[] = ['method' => $request->method(), 'path' => $path, 'params' => $params, 'authorization' => $request->header('Authorization')[0] ?? null, 'url' => $request->url()];

        foreach ($this->routes as &$route) {
            if ($route['method'] === $request->method() && preg_match($route['pattern'], $path)) {
                $item = count($route['queue']) > 1 ? array_shift($route['queue']) : $route['queue'][0];

                if ($item instanceof Throwable) {
                    throw $item;
                }

                $status = $item['__status'] ?? 200;
                unset($item['__status']);

                return Http::response($item, $status);
            }
        }

        return Http::response(['error' => ['code' => 100, 'message' => 'FakeGraph: ruta sin respuesta '.$request->method().' '.$path]], 404);
    }
}

/** @internal */
final class Sequence
{
    /** @param list<mixed> $items */
    public function __construct(public readonly array $items) {}
}
