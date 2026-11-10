<?php

declare(strict_types=1);

use App\Connectors\Bluesky\BlueskyConnector;
use App\Connectors\Data\RefreshResult;
use App\Connectors\Network;
use App\Models\SocialAccount;

/** Keep the real credential-form contract while ensuring connection and refresh cannot contact a PDS. */
class GeneralBlueskyConnector extends BlueskyConnector
{
    public function connectWithCredentials(array $input): array
    {
        return (new T43Connector('bluesky', [Network::Bluesky]))->exchangeCode('fake-local-code', 'http://localhost:8080');
    }

    public function refresh(SocialAccount $account): RefreshResult
    {
        return new RefreshResult('general-fake-access', 'general-fake-refresh', now()->addDays(60));
    }
}

/**
 * T-14: proveedores de IA falsos para la bateria E2E. Responden con un PNG real de 96x96 y nunca salen a la red.
 * Un prompt con `e2e-quota` simula la cuota agotada del proveedor y `e2e-policy` el rechazo por politica de contenido.
 */
function generalFakeAi(): void
{
    $png = static function (): string {
        $image = imagecreatetruecolor(96, 96);
        imagefill($image, 0, 0, imagecolorallocate($image, 124, 58, 237));
        imagefilledellipse($image, 48, 48, 56, 56, imagecolorallocate($image, 250, 204, 21));
        ob_start();
        imagepng($image);

        return (string) ob_get_clean();
    };
    $prompt = static function (Illuminate\Http\Client\Request $request): string {
        $data = $request->data();
        if ($request->isMultipart()) {
            foreach ($data as $part) {
                if (($part['name'] ?? '') === 'prompt') {
                    return (string) $part['contents'];
                }
            }

            return '';
        }

        return (string) ($data['prompt'] ?? $data['input'][0]['text'] ?? '');
    };
    Illuminate\Support\Facades\Http::fake([
        'api.openai.com/*' => function (Illuminate\Http\Client\Request $request) use ($png, $prompt) {
            $text = $prompt($request);
            if (str_contains($text, 'e2e-quota')) {
                return Illuminate\Support\Facades\Http::response(['error' => ['code' => 'insufficient_quota', 'message' => 'quota']], 429);
            }
            if (str_contains($text, 'e2e-policy')) {
                return Illuminate\Support\Facades\Http::response(['error' => ['code' => 'content_policy_violation', 'message' => 'blocked']], 400);
            }
            $n = max(1, (int) (collect($request->data())->firstWhere('name', 'n')['contents'] ?? $request->data()['n'] ?? 1));

            return Illuminate\Support\Facades\Http::response(['data' => array_fill(0, $n, ['b64_json' => base64_encode($png())])]);
        },
        'generativelanguage.googleapis.com/*' => fn () => Illuminate\Support\Facades\Http::response(['steps' => [['type' => 'model_output', 'content' => [['type' => 'image', 'mime_type' => 'image/png', 'data' => base64_encode($png())]]]]]),
        'api.anthropic.com/*' => function (Illuminate\Http\Client\Request $request) {
            $content = $request['messages'][0]['content'] ?? '';
            if (is_array($content)) {
                return Illuminate\Support\Facades\Http::response(['content' => [['type' => 'text', 'text' => 'Un círculo amarillo sobre un fondo violeta liso']]]);
            }
            $input = json_decode((string) $content, true);
            if (is_array($input) && isset($input['campos'])) {
                $rows = array_map(fn ($item) => array_combine($input['campos'], array_map(fn ($field) => $field.' de '.$item, $input['campos'])), $input['elementos']);

                return Illuminate\Support\Facades\Http::response(['content' => [['type' => 'text', 'text' => json_encode(['filas' => $rows], JSON_UNESCAPED_UNICODE)]]]);
            }

            return Illuminate\Support\Facades\Http::response(['content' => [['type' => 'text', 'text' => json_encode(['headlines' => ['Verano sin límites', 'Tu mejor ola', 'Solo esta semana'], 'copies' => ['Descubre la colección que huele a mar.', 'Reserva hoy y llévate un regalo.', 'Edición limitada para quienes no esperan.']], JSON_UNESCAPED_UNICODE)]]]);
        },
    ]);
}
