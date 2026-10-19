<?php

namespace Tests\Support;

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Red simulada para los publicadores que hablan HTTP crudo (YouTube, TikTok): enruta por metodo + URL completa
 * (regex), devuelve respuestas en secuencia y registra cada llamada (cabeceras, tamano del cuerpo y cuerpo JSON).
 * Una ruta sin respuesta devuelve 599 para que el test falle a la vista. Nunca sale a internet.
 */
class FakeHttp
{
    /** @var list<array{method: string, url: string, headers: array<string, string>, body_length: int, json: ?array, body: string}> */
    public array $calls = [];

    /** @var list<array{method: string, pattern: string, queue: list<mixed>}> */
    private array $routes = [];

    public function __construct()
    {
        Http::fake(fn (Request $request) => $this->handle($request));
    }

    /** @param  mixed  $response  array ['status' =>, 'json' =>, 'headers' =>] | Throwable | `seq(...)` */
    public function on(string $method, string $pattern, mixed $response): static
    {
        $this->routes[] = ['method' => strtoupper($method), 'pattern' => $pattern, 'queue' => $response instanceof FakeHttpSequence ? $response->items : [$response]];

        return $this;
    }

    public static function seq(mixed ...$items): FakeHttpSequence
    {
        return new FakeHttpSequence($items);
    }

    /** @return list<array{method: string, url: string, headers: array<string, string>, body_length: int, json: ?array, body: string}> */
    public function callsTo(string $method, string $pattern): array
    {
        return array_values(array_filter($this->calls, fn ($c) => $c['method'] === strtoupper($method) && preg_match($pattern, $c['url'])));
    }

    private function handle(Request $request)
    {
        $headers = [];
        foreach ($request->headers() as $name => $values) {
            $headers[strtolower($name)] = (string) ($values[0] ?? '');
        }

        $body = (string) $request->body();
        $this->calls[] = [
            'method' => $request->method(), 'url' => $request->url(), 'headers' => $headers,
            'body_length' => strlen($body), 'json' => json_decode($body, true) ?: null, 'body' => strlen($body) > 2048 ? '' : $body,
        ];

        foreach ($this->routes as &$route) {
            if ($route['method'] === $request->method() && preg_match($route['pattern'], $request->url())) {
                $item = count($route['queue']) > 1 ? array_shift($route['queue']) : $route['queue'][0];

                if ($item instanceof Throwable) {
                    throw $item;
                }

                return Http::response($item['json'] ?? '', $item['status'] ?? 200, $item['headers'] ?? []);
            }
        }

        return Http::response(['error' => ['message' => 'FakeHttp: ruta sin respuesta '.$request->method().' '.$request->url()]], 599);
    }
}

/** @internal */
final class FakeHttpSequence
{
    /** @param list<mixed> $items */
    public function __construct(public readonly array $items) {}
}
