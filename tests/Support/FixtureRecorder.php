<?php

namespace Tests\Support;

use Illuminate\Http\Client\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Assert;

final class FixtureRecorder
{
    public static function assertRecorded(string $name): void
    {
        $fixture = json_decode(file_get_contents(base_path("tests/Fixtures/providers/$name.json")), true, flags: JSON_THROW_ON_ERROR);
        $exchanges = Http::recorded(function (Request $request) use ($fixture) {
            $url = parse_url($request->url());
            if ($request->method() !== $fixture['method']
                || ($url['host'] ?? null) !== $fixture['host']
                || ($url['path'] ?? null) !== $fixture['path']) {
                return false;
            }

            parse_str($url['query'] ?? '', $query);

            foreach ($fixture['query'] ?? [] as $key => $value) {
                if (($query[$key] ?? null) !== $value) {
                    return false;
                }
            }

            return true;
        });

        $endpoint = $fixture['method'].' '.$fixture['host'].$fixture['path'];
        Assert::assertCount(1, $exchanges, "$name must record exactly one exchange for $endpoint with the required query.");
        [, $response] = $exchanges->first();

        Assert::assertSame($fixture['status'], $response->status(), "$name HTTP status");
        foreach ($fixture['headers'] as $header => $pattern) {
            Assert::assertMatchesRegularExpression($pattern, $response->header($header), "$name response header $header");
        }

        if (is_string($fixture['body'])) {
            Assert::assertSame($fixture['body'], $response->body(), "$name response body");

            return;
        }

        $body = $response->json();
        Assert::assertIsArray($body, "$name JSON body");
        foreach (Arr::dot($fixture['body']) as $path => $example) {
            Assert::assertTrue(Arr::has($body, $path), "$name response field $path");
            $actual = Arr::get($body, $path);
            Assert::assertSame(get_debug_type($example), get_debug_type($actual), "$name response type at $path");
        }

        foreach ($fixture['fixed'] ?? [] as $path) {
            Assert::assertSame(Arr::get($fixture['body'], $path), Arr::get($body, $path), "$name response value at $path");
        }
    }
}
