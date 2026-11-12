<?php

declare(strict_types=1);

use App\Connectors\Bluesky\BlueskyConnector;
use App\Connectors\Data\RefreshResult;
use App\Connectors\Network;
use App\Models\SocialAccount;
use Firebase\JWT\JWT;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

/** Keep the real credential-form contract while ensuring connection and refresh cannot contact a PDS. */
class GeneralBlueskyConnector extends BlueskyConnector
{
    public function connectWithCredentials(array $input): array
    {
        return (new T43Connector('bluesky', [Network::Bluesky]))->exchangeCode('fake-local-code', (string) getenv('APP_URL'));
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
    $prompt = static function (Request $request): string {
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
    Http::fake([
        'api.openai.com/*' => function (Request $request) use ($png, $prompt) {
            $text = $prompt($request);
            if (str_contains($text, 'e2e-quota')) {
                return Http::response(['error' => ['code' => 'insufficient_quota', 'message' => 'quota']], 429);
            }
            if (str_contains($text, 'e2e-policy')) {
                return Http::response(['error' => ['code' => 'content_policy_violation', 'message' => 'blocked']], 400);
            }
            $n = max(1, (int) (collect($request->data())->firstWhere('name', 'n')['contents'] ?? $request->data()['n'] ?? 1));

            return Http::response(['data' => array_fill(0, $n, ['b64_json' => base64_encode($png())])]);
        },
        'generativelanguage.googleapis.com/*' => fn () => Http::response(['steps' => [['type' => 'model_output', 'content' => [['type' => 'image', 'mime_type' => 'image/png', 'data' => base64_encode($png())]]]]]),
        'api.anthropic.com/*' => function (Request $request) {
            $content = $request['messages'][0]['content'] ?? '';
            if (is_array($content)) {
                return Http::response(['content' => [['type' => 'text', 'text' => 'Un círculo amarillo sobre un fondo violeta liso']]]);
            }
            $input = json_decode((string) $content, true);
            if (is_array($input) && isset($input['campos'])) {
                $rows = array_map(fn ($item) => array_combine($input['campos'], array_map(fn ($field) => $field.' de '.$item, $input['campos'])), $input['elementos']);

                return Http::response(['content' => [['type' => 'text', 'text' => json_encode(['filas' => $rows], JSON_UNESCAPED_UNICODE)]]]);
            }

            return Http::response(['content' => [['type' => 'text', 'text' => json_encode(['headlines' => ['Verano sin límites', 'Tu mejor ola', 'Solo esta semana'], 'copies' => ['Descubre la colección que huele a mar.', 'Reserva hoy y llévate un regalo.', 'Edición limitada para quienes no esperan.']], JSON_UNESCAPED_UNICODE)]]]);
        },
    ]);
}

/** Par RSA del Canva falso (`<run root>/canva-jwt.pem`, lo crea la primera llamada): firma el `correlation_jwt` y publica su clave publica. */
function generalCanvaKey(string $root): OpenSSLAsymmetricKey
{
    $file = $root.'/canva-jwt.pem';
    if (! is_file($file)) {
        $options = generalCanvaOpenSsl($root);
        openssl_pkey_export(openssl_pkey_new($options), $pem, null, $options);
        file_put_contents($file, $pem);
    }

    return openssl_pkey_get_private((string) file_get_contents($file));
}

/** Configuracion minima de OpenSSL: en Windows puede no encontrar su `openssl.cnf`. @return array<string, mixed> */
function generalCanvaOpenSsl(string $root): array
{
    $config = $root.'/canva-openssl.cnf';
    is_file($config) || file_put_contents($config, "[req]\ndistinguished_name = dn\n[dn]\n");

    return ['config' => $config, 'private_key_type' => OPENSSL_KEYTYPE_RSA, 'private_key_bits' => 2048];
}

/** @return array{keys: list<array<string, string>>} las claves publicas (JWKS) que Canva publica en `/connect/keys`. */
function generalCanvaJwks(string $root): array
{
    $rsa = openssl_pkey_get_details(generalCanvaKey($root))['rsa'];
    $b64 = static fn (string $raw) => rtrim(strtr(base64_encode($raw), '+/', '-_'), '=');

    return ['keys' => [['kty' => 'RSA', 'use' => 'sig', 'kid' => 'e2e-canva-key', 'n' => $b64($rsa['n']), 'e' => $b64($rsa['e'])]]];
}

/** `correlation_jwt` firmado RS256 con la clave del fake; `$claims` completa o sustituye las reclamaciones validas por defecto. */
function generalCanvaJwt(string $root, array $claims): string
{
    openssl_pkey_export(generalCanvaKey($root), $pem, null, generalCanvaOpenSsl($root));

    return JWT::encode($claims + ['type' => 'rti', 'aud' => (string) config('services.canva.client_id'), 'exp' => time() + 3600], $pem, 'RS256', 'e2e-canva-key');
}

/**
 * T-16: Canva falso para la bateria E2E. Atiende OAuth (token, revocacion), perfil, capacidades, disenos (listar, buscar, crear), exportaciones
 * y la descarga del fichero exportado (PNG real de 96x96 o un PDF pequeño) sin salir nunca a la red. `<run root>/canva-pro` anade la capacidad de
 * PNG transparente y `canva-templates` las de plantillas de marca y Autofill. Tambien publica las claves de `/connect/keys` (T-26).
 */
function generalFakeCanva(string $root): void
{
    $png = static function (): string {
        $image = imagecreatetruecolor(96, 96);
        imagefill($image, 0, 0, imagecolorallocate($image, 14, 165, 233));
        imagefilledellipse($image, 48, 48, 56, 56, imagecolorallocate($image, 250, 250, 250));
        ob_start();
        imagepng($image);

        return (string) ob_get_clean();
    };
    $design = static fn (string $id, string $title) => [
        'id' => $id, 'title' => $title, 'updated_at' => 1790000000,
        'urls' => ['edit_url' => "https://www.canva.com/api/design/token-$id/edit", 'view_url' => "https://www.canva.com/api/design/token-$id/view"],
    ];
    $all = [$design('DAFe2eRebaj1', 'Rebajas E2E'), $design('DAFe2eHisto2', 'Historia E2E')];
    Http::fake([
        'api.canva.com/rest/v1/oauth/token' => Http::response(['access_token' => 'e2e-canva-access', 'refresh_token' => 'e2e-canva-refresh', 'token_type' => 'Bearer', 'expires_in' => 14400, 'scope' => implode(' ', (array) config('services.canva.scopes'))]),
        'api.canva.com/rest/v1/oauth/revoke' => Http::response('', 200),
        'api.canva.com/rest/v1/users/me/profile' => Http::response(['profile' => ['display_name' => 'Cuenta Canva E2E']]),
        'api.canva.com/rest/v1/users/me/capabilities' => fn () => Http::response(['capabilities' => [
            ...(is_file($root.'/canva-pro') ? ['export_png_transparency'] : []),
            ...(is_file($root.'/canva-templates') ? ['brand_template', 'autofill'] : []),
        ]]),
        'api.canva.com/rest/v1/connect/keys' => fn () => Http::response(generalCanvaJwks($root)),
        'api.canva.com/rest/v1/brand-templates/BTe2eCiudad1/dataset' => Http::response(['dataset' => ['CITY' => ['type' => 'text'], 'TEMPERATURE' => ['type' => 'text'], 'BACKGROUND' => ['type' => 'image']]]),
        'api.canva.com/rest/v1/brand-templates*' => Http::response(['items' => [['id' => 'BTe2eCiudad1', 'title' => 'Ciudad del día E2E', 'view_url' => 'https://www.canva.com/brand/BTe2eCiudad1/view', 'created_at' => 1790000000]]]),
        'api.canva.com/rest/v1/autofills' => Http::response(['job' => ['id' => 'e2e-autofill-1', 'status' => 'in_progress']]),
        'api.canva.com/rest/v1/autofills/*' => Http::response(['job' => ['id' => 'e2e-autofill-1', 'status' => 'success', 'result' => ['type' => 'create_design', 'design' => $design('DAFe2eAutof4', 'Ciudad de hoy E2E')]]]),
        'api.canva.com/rest/v1/designs*' => function (Request $request) use ($all, $design) {
            if ($request->method() === 'POST') {
                return Http::response(['design' => $design('DAFe2eNuevo3', (string) ($request['title'] ?? 'Nuevo')) + ['thumbnail' => null]]);
            }
            if (preg_match('~/designs/([A-Za-z0-9_-]+)$~', (string) parse_url($request->url(), PHP_URL_PATH), $one)) {
                return Http::response(['design' => $design($one[1], $one[1] === 'DAFe2eAutof4' ? 'Ciudad de hoy E2E' : 'Diseño E2E')]);
            }
            parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);
            $needle = mb_strtolower((string) ($query['query'] ?? ''));

            return Http::response(['items' => array_values(array_filter($all, fn ($d) => $needle === '' || str_contains(mb_strtolower($d['title']), $needle)))]);
        },
        'api.canva.com/rest/v1/exports' => fn (Request $request) => Http::response(['job' => ['id' => ($request['format']['type'] ?? 'png') === 'pdf' ? 'e2e-export-pdf' : 'e2e-export-1', 'status' => 'in_progress']]),
        'api.canva.com/rest/v1/exports/e2e-export-pdf' => Http::response(['job' => ['id' => 'e2e-export-pdf', 'status' => 'success', 'urls' => ['https://export-download.canva.com/e2e/Rebajas%20E2E.pdf?X-Amz-Signature=e2e']]]),
        'api.canva.com/rest/v1/exports/*' => Http::response(['job' => ['id' => 'e2e-export-1', 'status' => 'success', 'urls' => ['https://export-download.canva.com/e2e/rebajas.png?X-Amz-Signature=e2e']]]),
        'export-download.canva.com/*.pdf*' => fn () => Http::response('%PDF-1.4
1 0 obj
<< /Type /Catalog >>
endobj
trailer
<< /Root 1 0 R >>
%%EOF
', 200, ['Content-Type' => 'application/pdf']),
        'export-download.canva.com/*' => fn () => Http::response($png(), 200, ['Content-Type' => 'image/png']),
    ]);
}
