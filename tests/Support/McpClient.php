<?php

namespace Tests\Support;

use App\Models\User;
use Illuminate\Testing\TestResponse;

/** Cliente JSON-RPC minimo sobre POST /mcp para los tests (con la key Bearer indicada). */
trait McpClient
{
    private int $rpcId = 0;

    /** Crea una API key del usuario y devuelve su texto plano. */
    protected function mcpKey(User $user, array $abilities = ['*'], string $name = 'test'): string
    {
        return $user->createToken($name, $abilities)->plainTextToken;
    }

    /** @param array<string, mixed> $params */
    protected function rpc(?string $key, string $method, array $params = []): TestResponse
    {
        $headers = ['Accept' => 'application/json, text/event-stream', 'Content-Type' => 'application/json'];
        if ($key !== null) {
            $headers['Authorization'] = 'Bearer '.$key;
        }

        // Cada peticion es independiente (como un cliente real): sin usuario ni guard cacheados de la anterior.
        app('auth')->forgetGuards();

        return $this->postJson('/mcp', ['jsonrpc' => '2.0', 'id' => ++$this->rpcId, 'method' => $method, 'params' => $params], $headers);
    }

    /**
     * Llama a una herramienta; devuelve [isError, datos decodificados o texto, respuesta].
     *
     * @param  array<string, mixed>  $arguments
     * @return array{0: bool, 1: mixed, 2: TestResponse}
     */
    protected function callTool(string $key, string $tool, array $arguments = []): array
    {
        $response = $this->rpc($key, 'tools/call', ['name' => $tool, 'arguments' => $arguments]);
        $response->assertOk();
        $result = $response->json('result') ?? [];
        $text = $result['content'][0]['text'] ?? '';
        $isError = (bool) ($result['isError'] ?? false) || $response->json('error') !== null;

        return [$isError, json_decode($text, true) ?? ($text ?: $response->json('error.message')), $response];
    }
}
