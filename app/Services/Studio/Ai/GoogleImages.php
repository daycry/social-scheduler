<?php

namespace App\Services\Studio\Ai;

/**
 * Google (Gemini image, API Interactions): POST /interactions con `x-goog-api-key` y `Api-Revision`; la imagen sale en
 * steps[].content[] (type=image, data en base64). Se aceptan tambien las respuestas de generateContent (candidates[].content.parts[].inlineData).
 * No admite mascara: el outpaint y las variaciones se guian con la instruccion y la imagen de referencia.
 */
class GoogleImages extends AbstractImageProvider
{
    public function id(): string
    {
        return 'google';
    }

    public function capabilities(): array
    {
        return ['generate', 'outpaint', 'variation'];
    }

    public function request(AiImageRequest $request): array
    {
        $images = [];
        for ($i = 0; $i < $request->count; $i++) {
            try {
                $images[] = $this->one($request);
            } catch (AiProviderException $e) {
                if ($images === []) {
                    throw $e;
                }
                break; // Se conservan las variantes ya obtenidas.
            }
        }

        return $images;
    }

    private function one(AiImageRequest $request): string
    {
        $input = [['type' => 'text', 'text' => $request->prompt]];
        if ($request->image !== null) {
            $input[] = ['type' => 'image', 'mime_type' => 'image/png', 'data' => base64_encode($request->image)];
        }
        $response = $this->send(fn () => $this->http()->withHeaders(['x-goog-api-key' => $this->secret(), 'Api-Revision' => (string) ($this->config['api_revision'] ?? '2026-05-20')])
            ->post(rtrim((string) $this->config['endpoint'], '/').'/interactions', [
                'model' => $this->model(), 'input' => $input,
                'response_format' => ['type' => 'image', 'mime_type' => 'image/png', 'aspect_ratio' => $request->aspect],
            ]));
        $json = $response->json();
        $data = $this->find(is_array($json) ? $json : []);
        if ($data === null) {
            $blocked = preg_match('/safety|blocked|prohibited|policy/i', (string) $response->body()) === 1;
            throw new AiProviderException($blocked ? 'policy' : 'invalid_response');
        }

        return $this->image($data);
    }

    /** @param array<string, mixed> $json */
    private function find(array $json): ?string
    {
        foreach ((array) ($json['steps'] ?? []) as $step) {
            foreach ((array) ($step['content'] ?? []) as $block) {
                if (is_array($block) && ($block['type'] ?? null) === 'image' && is_string($block['data'] ?? null)) {
                    return $block['data'];
                }
            }
        }
        foreach ((array) ($json['candidates'] ?? []) as $candidate) {
            foreach ((array) ($candidate['content']['parts'] ?? []) as $part) {
                $inline = $part['inlineData'] ?? $part['inline_data'] ?? null;
                if (is_array($inline) && is_string($inline['data'] ?? null)) {
                    return $inline['data'];
                }
            }
        }

        return null;
    }

    protected function classify(int $status, ?array $body): string
    {
        $message = strtolower((string) ($body['error']['message'] ?? '').' '.(string) ($body['error']['status'] ?? ''));

        return match (true) {
            in_array($status, [401, 403], true) || str_contains($message, 'api key not valid') || str_contains($message, 'api_key_invalid') => 'invalid_key',
            $status === 429 && preg_match('/billing|per day|daily|check your plan/', $message) === 1 => 'quota',
            $status === 429 => 'rate_limited',
            $status >= 500 => 'server',
            preg_match('/safety|blocked|prohibited|policy/', $message) === 1 => 'policy',
            default => 'bad_request',
        };
    }
}
