<?php

namespace App\Services\Studio\Ai;

/** OpenAI Images: POST /images/generations y /images/edits (multipart, con mascara de transparencia). Respuesta: data[].b64_json. */
class OpenAiImages extends AbstractImageProvider
{
    private const SIZES = ['1:1' => '1024x1024', '3:2' => '1536x1024', '16:9' => '1536x1024', '2:3' => '1024x1536', '9:16' => '1024x1536'];

    public function id(): string
    {
        return 'openai';
    }

    public function capabilities(): array
    {
        return ['generate', 'edit', 'outpaint', 'variation'];
    }

    public function request(AiImageRequest $request): array
    {
        $base = rtrim((string) $this->config['endpoint'], '/');
        $fields = ['model' => $this->model(), 'prompt' => $request->prompt, 'n' => $request->count, 'size' => self::SIZES[$request->aspect] ?? '1024x1024'];
        if (filled($this->config['quality'] ?? null)) {
            $fields['quality'] = (string) $this->config['quality'];
        }
        $response = $this->send(function () use ($request, $base, $fields) {
            $http = $this->http($request->deadline)->withToken($this->secret());
            if ($request->image === null) {
                return $http->post($base.'/images/generations', $fields);
            }
            $http = $http->asMultipart()->attach('image[]', $request->image, 'image.png', ['Content-Type' => 'image/png']);
            if ($request->mask !== null) {
                $http = $http->attach('mask', $request->mask, 'mask.png', ['Content-Type' => 'image/png']);
            }

            return $http->post($base.'/images/edits', $fields);
        });
        $items = $response->json('data');
        if (! is_array($items) || $items === []) {
            throw new AiProviderException('invalid_response');
        }
        $images = [];
        foreach (array_slice($items, 0, $request->count) as $item) {
            if (is_array($item) && is_string($item['b64_json'] ?? null)) {
                $images[] = $this->image($item['b64_json']);
            } elseif (is_array($item) && is_string($item['url'] ?? null)) {
                $images[] = $this->download($item['url']);
            } else {
                throw new AiProviderException('invalid_response');
            }
        }

        return $images;
    }

    protected function classify(int $status, ?array $body): string
    {
        $code = strtolower((string) ($body['error']['code'] ?? '').' '.(string) ($body['error']['type'] ?? ''));

        return match (true) {
            in_array($status, [401, 403], true) => 'invalid_key',
            $status === 404 || str_contains($code, 'model_not_found') => 'model_unavailable',
            $status === 429 && str_contains($code, 'insufficient_quota') => 'quota',
            $status === 429 => 'rate_limited',
            $status >= 500 => 'server',
            str_contains($code, 'content_policy') || str_contains($code, 'moderation') || str_contains($code, 'safety') => 'policy',
            default => 'bad_request',
        };
    }
}
