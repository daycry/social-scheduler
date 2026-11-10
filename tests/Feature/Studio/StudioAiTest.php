<?php

use App\Jobs\Studio\RunStudioAiJob;
use App\Models\Brand;
use App\Models\MediaAsset;
use App\Models\Studio\Design;
use App\Models\Studio\StudioAiJob;
use App\Models\User;
use App\Services\Studio\StudioAi;
use App\Services\Studio\StudioAssets;
use App\Services\Studio\StudioDocument;
use App\Services\Studio\StudioService;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Sleep;
use Tests\Support\McpClient;

uses(McpClient::class);

/** PNG real y pequeño generado con GD. */
function aiPng(int $w = 64, int $h = 64): string
{
    $image = imagecreatetruecolor($w, $h);
    imagefill($image, 0, 0, imagecolorallocate($image, 200, 40, 90));
    ob_start();
    imagepng($image);

    return (string) ob_get_clean();
}

function openAiOk(int $n = 1): array
{
    return ['data' => array_fill(0, $n, ['b64_json' => base64_encode(aiPng())])];
}

function googleOk(): array
{
    return ['id' => 'x', 'steps' => [['type' => 'model_output', 'content' => [['type' => 'image', 'mime_type' => 'image/png', 'data' => base64_encode(aiPng())]]]]];
}

beforeEach(function () {
    Queue::fake();
    Storage::fake('local');
    Sleep::fake();
    config(['studio.ai.providers.openai.key' => 'sk-test-secret-openai', 'studio.ai.providers.google.key' => 'g-test-secret-google', 'studio.ai.backoff' => [2, 6]]);
    $this->user = User::factory()->create();
    $this->brand = Brand::factory()->for($this->user)->create();
    $bytes = aiPng(300, 200);
    $this->image = MediaAsset::factory()->for($this->user)->create([
        'disk' => 'local', 'path' => 'media/'.$this->user->id.'/photo.png', 'mime' => 'image/png', 'kind' => 'image', 'status' => 'listo',
        'size' => strlen($bytes), 'width' => 300, 'height' => 200,
    ]);
    Storage::disk('local')->put($this->image->path, $bytes);
    app(StudioAssets::class)->attach($this->user, $this->brand, $this->image->id);
    $this->url = '/brands/'.$this->brand->id.'/studio/ai-jobs';
    $this->run = function (array $payload, ?User $as = null) {
        $id = $this->actingAs($as ?? $this->user)->postJson($this->url, $payload)->assertAccepted()->json('job.id');
        app(StudioAi::class)->process($id);

        return StudioAiJob::withoutUserScope()->findOrFail($id);
    };
});

test('sin clave el estado dice no configurado y no se puede crear ningun trabajo', function () {
    config(['studio.ai.providers.openai.key' => '', 'studio.ai.providers.google.key' => null]);
    $this->actingAs($this->user)->getJson('/brands/'.$this->brand->id.'/studio/ai')->assertOk()
        ->assertJsonPath('configured', false)->assertJsonPath('providers.0.configured', false)->assertJsonPath('providers.0.capabilities', []);
    $this->postJson($this->url, ['kind' => 'generate', 'provider' => 'openai', 'prompt' => 'un gato'])->assertUnprocessable()->assertJsonValidationErrors('provider');
    Queue::assertNothingPushed();
});

test('el estado nunca devuelve claves y refleja la cuota', function () {
    $body = $this->actingAs($this->user)->getJson('/brands/'.$this->brand->id.'/studio/ai')->assertOk()->assertJsonPath('configured', true)
        ->assertJsonPath('providers.0.remaining', 20)->assertJsonPath('providers.1.capabilities', ['generate', 'outpaint', 'variation'])->getContent();
    expect($body)->not->toContain('sk-test-secret-openai')->not->toContain('g-test-secret-google');
});

test('generar con OpenAI crea un trabajo en cola y al ejecutarlo guarda un recurso de marca con metadatos', function () {
    Http::fake(['api.openai.com/*' => Http::response(openAiOk(2))]);
    $created = $this->actingAs($this->user)->postJson($this->url, ['kind' => 'generate', 'provider' => 'openai', 'prompt' => 'Un faro al amanecer', 'aspect' => '16:9', 'style' => 'photo', 'count' => 2])
        ->assertAccepted()->assertJsonPath('job.status', 'queued')->assertJsonPath('job.output_assets', [])->json('job');
    Queue::assertPushed(RunStudioAiJob::class, 1);
    app(StudioAi::class)->process($created['id']);

    $job = $this->getJson($this->url.'/'.$created['id'])->assertOk()->assertJsonPath('job.status', 'ready')->assertJsonCount(2, 'job.output_assets')->json('job');
    Http::assertSent(fn (Request $r) => $r->url() === 'https://api.openai.com/v1/images/generations' && $r->hasHeader('Authorization', 'Bearer sk-test-secret-openai')
        && $r['model'] === 'gpt-image-2.5-sunburst' && $r['n'] === 2 && $r['size'] === '1536x1024' && str_contains($r['prompt'], 'Un faro al amanecer') && str_contains($r['prompt'], 'Fotografía'));
    $asset = $job['output_assets'][0];
    expect($asset['ai'])->toMatchArray(['provider' => 'openai', 'model' => 'gpt-image-2.5-sunburst'])->and($asset['ai']['prompt'])->toContain('faro')
        ->and($asset['mime'])->toBe('image/png');
    $stored = MediaAsset::withoutUserScope()->findOrFail($asset['id']);
    expect($stored->source)->toBe('ai')->and(Storage::disk('local')->get($stored->path))->toStartWith("\x89PNG");
    // Esta en la biblioteca de la marca.
    expect(app(StudioAssets::class)->list($this->user, $this->brand)->pluck('id')->all())->toContain($asset['id']);
    expect(json_encode($job))->not->toContain('sk-test-secret-openai');
});

test('editar con mascara envia imagen y mascara con transparencia y deja intacto el original', function () {
    Http::fake(['api.openai.com/*' => Http::response(openAiOk())]);
    $before = hash('sha256', Storage::disk('local')->get($this->image->path));
    $job = ($this->run)(['kind' => 'edit', 'provider' => 'openai', 'prompt' => 'Pon un sombrero rojo', 'media_id' => $this->image->id, 'mask' => ['x' => 0.25, 'y' => 0.1, 'width' => 0.5, 'height' => 0.4]]);
    expect($job->status)->toBe('ready');
    Http::assertSent(function (Request $r) {
        if ($r->url() !== 'https://api.openai.com/v1/images/edits' || ! $r->isMultipart()) {
            return false;
        }
        $parts = collect($r->data())->keyBy('name');
        $mask = imagecreatefromstring($parts['mask']['contents']);
        $image = imagecreatefromstring($parts['image[]']['contents']);

        // Transparente donde se edita (centro-arriba), opaca en el resto.
        return imagesx($mask) === imagesx($image) && imagesy($mask) === imagesy($image) && ((imagecolorat($mask, 150, 40) >> 24) & 127) === 127 && ((imagecolorat($mask, 5, 190) >> 24) & 127) === 0;
    });
    expect(hash('sha256', Storage::disk('local')->get($this->image->path)))->toBe($before);
});

test('ampliar el lienzo manda una imagen mayor con la parte nueva transparente y rechaza la misma proporcion', function () {
    Http::fake(['api.openai.com/*' => Http::response(openAiOk())]);
    $this->actingAs($this->user)->postJson($this->url, ['kind' => 'outpaint', 'provider' => 'openai', 'media_id' => $this->image->id, 'aspect' => '3:2'])
        ->assertUnprocessable()->assertJsonValidationErrors('aspect');
    $job = ($this->run)(['kind' => 'outpaint', 'provider' => 'openai', 'media_id' => $this->image->id, 'aspect' => '1:1']);
    expect($job->status)->toBe('ready');
    Http::assertSent(function (Request $r) {
        $parts = collect($r->data())->keyBy('name');
        $image = imagecreatefromstring($parts['image[]']['contents']);

        return $r->url() === 'https://api.openai.com/v1/images/edits' && imagesx($image) === imagesy($image) && imagesx($image) === 300
            && ((imagecolorat($image, 150, 5) >> 24) & 127) === 127 && ((imagecolorat($image, 150, 150) >> 24) & 127) === 0;
    });
});

test('una variacion usa la imagen de origen sin mascara', function () {
    Http::fake(['api.openai.com/*' => Http::response(openAiOk())]);
    expect(($this->run)(['kind' => 'variation', 'provider' => 'openai', 'media_id' => $this->image->id])->status)->toBe('ready');
    Http::assertSent(fn (Request $r) => $r->isMultipart() && ! collect($r->data())->contains('name', 'mask') && str_contains((string) collect($r->data())->firstWhere('name', 'prompt')['contents'], 'variación'));
});

test('Google genera con la API Interactions, una llamada por variante, y no admite mascara', function () {
    Http::fake(['generativelanguage.googleapis.com/*' => Http::response(googleOk())]);
    $job = ($this->run)(['kind' => 'generate', 'provider' => 'google', 'prompt' => 'Una taza de café', 'aspect' => '9:16', 'count' => 2]);
    expect($job->status)->toBe('ready')->and($job->output_media_ids)->toHaveCount(2);
    Http::assertSentCount(2);
    Http::assertSent(fn (Request $r) => $r->url() === 'https://generativelanguage.googleapis.com/v1beta/interactions' && $r->hasHeader('x-goog-api-key', 'g-test-secret-google')
        && $r->hasHeader('Api-Revision') && $r['response_format']['aspect_ratio'] === '9:16');
    $this->postJson($this->url, ['kind' => 'edit', 'provider' => 'google', 'prompt' => 'x y z', 'media_id' => $this->image->id, 'mask' => ['x' => 0, 'y' => 0, 'width' => 0.5, 'height' => 0.5]])
        ->assertUnprocessable()->assertJsonValidationErrors('kind');
});

test('Google tambien acepta la forma de generateContent', function () {
    Http::fake(['generativelanguage.googleapis.com/*' => Http::response(['candidates' => [['content' => ['parts' => [['text' => 'ok'], ['inlineData' => ['mimeType' => 'image/png', 'data' => base64_encode(aiPng())]]]]]]])]);
    expect(($this->run)(['kind' => 'generate', 'provider' => 'google', 'prompt' => 'Una taza de café'])->status)->toBe('ready');
});

test('un 429 y un 5xx se reintentan con espera acotada y el trabajo termina', function () {
    Http::fake(['api.openai.com/*' => Http::sequence()->push(['error' => ['code' => 'rate_limit_exceeded']], 429, ['Retry-After' => '4'])->push([], 503)->push(openAiOk())]);
    $job = ($this->run)(['kind' => 'generate', 'provider' => 'openai', 'prompt' => 'Un faro al amanecer']);
    expect($job->status)->toBe('ready')->and($job->attempts)->toBe(3);
    Sleep::assertSlept(fn ($d) => (int) $d->totalSeconds === 4, 1);
    Sleep::assertSlept(fn ($d) => (int) $d->totalSeconds === 6, 1);
});

test('si el 429 persiste tras los intentos el trabajo falla con un mensaje en español y no consume cuota', function () {
    Http::fake(['api.openai.com/*' => Http::response(['error' => ['code' => 'rate_limit_exceeded']], 429)]);
    $job = ($this->run)(['kind' => 'generate', 'provider' => 'openai', 'prompt' => 'Un faro al amanecer', 'count' => 2]);
    expect($job->status)->toBe('failed')->and($job->error_code)->toBe('rate_limited')->and($job->attempts)->toBe(3)->and($job->error)->toContain('limitando');
    Http::assertSentCount(3);
    $this->getJson('/brands/'.$this->brand->id.'/studio/ai')->assertJsonPath('providers.0.remaining', 20);
});

test('los errores permanentes no se reintentan: cuota, clave invalida y politica de contenido', function (int $status, array $body, string $code, string $needle) {
    Http::fake(['api.openai.com/*' => Http::response($body, $status)]);
    $job = ($this->run)(['kind' => 'generate', 'provider' => 'openai', 'prompt' => 'Un faro al amanecer']);
    expect($job->status)->toBe('failed')->and($job->error_code)->toBe($code)->and($job->error)->toContain($needle)->and($job->attempts)->toBe(1);
    Http::assertSentCount(1);
    expect($job->error)->not->toContain('sk-test-secret-openai')->not->toContain('secret body');
})->with([
    'cuota' => [429, ['error' => ['code' => 'insufficient_quota', 'message' => 'secret body']], 'quota', 'cuota'],
    'clave' => [401, ['error' => ['code' => 'invalid_api_key', 'message' => 'secret body']], 'invalid_key', 'clave'],
    'politica' => [400, ['error' => ['code' => 'content_policy_violation', 'message' => 'secret body']], 'policy', 'política de contenido'],
]);

test('la respuesta debe ser una imagen real: se comprueban los magic bytes y los topes de tamaño', function () {
    Http::fake(['api.openai.com/*' => Http::sequence()->push(['data' => [['b64_json' => base64_encode('<?php echo 1; ?> no soy una imagen')]]])->push(openAiOk())]);
    $job = ($this->run)(['kind' => 'generate', 'provider' => 'openai', 'prompt' => 'Un faro al amanecer']);
    expect($job->status)->toBe('failed')->and($job->error_code)->toBe('invalid_response')->and(MediaAsset::withoutUserScope()->where('source', 'ai')->count())->toBe(0);

    config(['studio.ai.max_output_bytes' => 100]);
    $big = ($this->run)(['kind' => 'generate', 'provider' => 'openai', 'prompt' => 'Un faro al amanecer distinto']);
    expect($big->status)->toBe('failed')->and($big->error_code)->toBe('output_too_large');
});

test('una URL devuelta por el proveedor pasa por la guardia SSRF y las internas nunca se piden', function () {
    Http::fake(['api.openai.com/*' => Http::response(['data' => [['url' => 'http://127.0.0.1/secreto.png']]]), '*' => Http::response('interno')]);
    $job = ($this->run)(['kind' => 'generate', 'provider' => 'openai', 'prompt' => 'Un faro al amanecer']);
    expect($job->status)->toBe('failed')->and($job->error_code)->toBe('invalid_response');
    Http::assertNotSent(fn (Request $r) => str_contains($r->url(), '127.0.0.1'));
});

test('la cuota diaria es por usuario y proveedor, se puede ajustar por usuario y los fallos no la gastan', function () {
    Http::fake(['api.openai.com/*' => Http::response(openAiOk(2))]);
    config(['studio.ai.providers.openai.daily_limit' => 2]);
    ($this->run)(['kind' => 'generate', 'provider' => 'openai', 'prompt' => 'Primera imagen', 'count' => 2]);
    $this->postJson($this->url, ['kind' => 'generate', 'provider' => 'openai', 'prompt' => 'Segunda imagen'])->assertUnprocessable()->assertJsonValidationErrors('count');
    // Otro proveedor tiene su propia cuota; otro usuario, la suya.
    Http::fake(['generativelanguage.googleapis.com/*' => Http::response(googleOk())]);
    expect(($this->run)(['kind' => 'generate', 'provider' => 'google', 'prompt' => 'Primera de Google'])->status)->toBe('ready');
    $other = User::factory()->create();
    $otherBrand = Brand::factory()->for($other)->create();
    $this->actingAs($other)->postJson('/brands/'.$otherBrand->id.'/studio/ai-jobs', ['kind' => 'generate', 'provider' => 'openai', 'prompt' => 'Imagen de otro'])->assertAccepted();
    // Ajuste por usuario.
    config(['studio.ai.user_limits' => [$this->user->id => ['openai' => 0]]]);
    $this->actingAs($this->user)->postJson($this->url, ['kind' => 'generate', 'provider' => 'openai', 'prompt' => 'Tercera imagen'])->assertUnprocessable();
    $this->getJson('/brands/'.$this->brand->id.'/studio/ai')->assertJsonPath('providers.0.daily_limit', 0);
});

test('la idempotencia devuelve el mismo trabajo y rechaza reutilizar la clave con otra peticion', function () {
    $payload = ['kind' => 'generate', 'provider' => 'openai', 'prompt' => 'Un faro al amanecer', 'idempotency_key' => 'clave-estable-0001'];
    $first = $this->actingAs($this->user)->postJson($this->url, $payload)->assertAccepted()->json('job.id');
    $this->postJson($this->url, $payload)->assertAccepted()->assertJsonPath('job.id', $first);
    $this->postJson($this->url, ['prompt' => 'Otro faro distinto'] + $payload)->assertUnprocessable()->assertJsonValidationErrors('idempotency_key');
    // Sin clave, una peticion identica y activa tampoco duplica el trabajo.
    unset($payload['idempotency_key']);
    $this->postJson($this->url, $payload)->assertAccepted()->assertJsonPath('job.id', $first);
    Queue::assertPushed(RunStudioAiJob::class, 1);
    expect(StudioAiJob::withoutUserScope()->count())->toBe(1);
    // Procesar dos veces el mismo trabajo no genera dos cobros.
    Http::fake(['api.openai.com/*' => Http::response(openAiOk())]);
    app(StudioAi::class)->process($first);
    app(StudioAi::class)->process($first);
    Http::assertSentCount(1);
});

test('los trabajos y los recursos de origen son privados: otro usuario u otra marca reciben 404', function () {
    $id = $this->actingAs($this->user)->postJson($this->url, ['kind' => 'generate', 'provider' => 'openai', 'prompt' => 'Un faro al amanecer'])->assertAccepted()->json('job.id');
    $this->actingAs(User::factory()->create())->getJson($this->url.'/'.$id)->assertNotFound();
    $otherBrand = Brand::factory()->for($this->user)->create();
    $this->actingAs($this->user)->getJson('/brands/'.$otherBrand->id.'/studio/ai-jobs/'.$id)->assertNotFound();
    $intruder = User::factory()->create();
    $this->actingAs($intruder)->postJson($this->url, ['kind' => 'generate', 'provider' => 'openai', 'prompt' => 'Un faro al amanecer'])->assertNotFound();
    $foreign = MediaAsset::factory()->for($intruder)->create(['disk' => 'local', 'path' => 'media/x/y.png', 'mime' => 'image/png', 'kind' => 'image', 'status' => 'listo', 'width' => 10, 'height' => 10, 'size' => 10]);
    $this->actingAs($this->user)->postJson($this->url, ['kind' => 'variation', 'provider' => 'openai', 'media_id' => $foreign->id])->assertNotFound();
});

test('valida la peticion: propiedades desconocidas, prompt corto, mascara invalida y variantes de mas', function () {
    $base = ['kind' => 'generate', 'provider' => 'openai', 'prompt' => 'Un faro al amanecer'];
    $this->actingAs($this->user);
    $this->postJson($this->url, $base + ['model' => 'otro'])->assertUnprocessable()->assertJsonValidationErrors('request');
    $this->postJson($this->url, ['prompt' => 'a'] + $base)->assertUnprocessable()->assertJsonValidationErrors('prompt');
    $this->postJson($this->url, ['count' => 9] + $base)->assertUnprocessable()->assertJsonValidationErrors('count');
    $this->postJson($this->url, ['kind' => 'edit', 'media_id' => $this->image->id, 'mask' => ['x' => 0.9, 'y' => 0, 'width' => 0.5, 'height' => 0.5]] + $base)->assertUnprocessable()->assertJsonValidationErrors('mask');
    Queue::assertNothingPushed();
});

test('un trabajo interrumpido por la cola queda fallido y legible', function () {
    $id = $this->actingAs($this->user)->postJson($this->url, ['kind' => 'generate', 'provider' => 'openai', 'prompt' => 'Un faro al amanecer'])->json('job.id');
    (new RunStudioAiJob($id))->failed(new RuntimeException('detalle privado'));
    $this->getJson($this->url.'/'.$id)->assertJsonPath('job.status', 'failed')->assertJsonPath('job.error_code', 'processing_interrupted');
});

test('los fallos no escriben la clave ni el cuerpo del proveedor en el log', function () {
    Log::spy();
    Http::fake(['api.openai.com/*' => Http::response(['error' => ['code' => 'invalid_api_key', 'message' => 'Incorrect API key sk-test-secret-openai']], 401)]);
    ($this->run)(['kind' => 'generate', 'provider' => 'openai', 'prompt' => 'Un faro al amanecer']);
    Log::shouldHaveReceived('warning')->withArgs(fn ($message, $context = []) => $message === 'studio.ai_provider_failed' && ! str_contains(json_encode($context), 'sk-test') && $context['status'] === 401)->once();
});

test('API: exige la ability studio:ai y solo ve sus propios trabajos', function () {
    $ai = $this->mcpKey($this->user, ['studio:ai']);
    $design = $this->mcpKey($this->user, ['designs:write', 'designs:read']);
    $url = '/api/v1/brands/'.$this->brand->id.'/ai-image-jobs';
    $payload = ['kind' => 'generate', 'provider' => 'openai', 'prompt' => 'Un faro al amanecer'];
    $this->withToken($design)->postJson($url, $payload)->assertForbidden();
    app('auth')->forgetGuards();
    $id = $this->withToken($ai)->postJson($url, $payload)->assertAccepted()->json('job.id');
    app('auth')->forgetGuards();
    $this->withToken($ai)->getJson($url.'/'.$id)->assertOk()->assertJsonPath('job.status', 'queued');
    app('auth')->forgetGuards();
    $this->withToken($ai)->getJson('/api/v1/brands/'.$this->brand->id.'/ai')->assertOk()->assertJsonPath('configured', true);
    app('auth')->forgetGuards();
    $this->withToken($this->mcpKey(User::factory()->create(), ['studio:ai']))->getJson($url.'/'.$id)->assertNotFound();
});

test('MCP: generate_studio_image consulta el estado, encola y lee el trabajo con la misma ability', function () {
    $key = $this->mcpKey($this->user, ['studio:ai']);
    [$e0, $status] = $this->callTool($key, 'generate_studio_image', ['brand_id' => $this->brand->id, 'action' => 'status']);
    expect($e0)->toBeFalse()->and($status['configured'])->toBeTrue();
    [$e1, $start] = $this->callTool($key, 'generate_studio_image', ['brand_id' => $this->brand->id, 'action' => 'start', 'kind' => 'generate', 'provider' => 'openai', 'prompt' => 'Un faro al amanecer', 'idempotency_key' => 'mcp-clave-0001']);
    expect($e1)->toBeFalse()->and($start['job']['status'])->toBe('queued');
    Http::fake(['api.openai.com/*' => Http::response(openAiOk())]);
    app(StudioAi::class)->process($start['job']['id']);
    [$e2, $get] = $this->callTool($key, 'generate_studio_image', ['brand_id' => $this->brand->id, 'action' => 'get', 'job_id' => $start['job']['id']]);
    expect($e2)->toBeFalse()->and($get['job']['status'])->toBe('ready')->and($get['job']['output_assets'])->toHaveCount(1);
    [$denied] = $this->callTool($this->mcpKey($this->user, ['designs:write']), 'generate_studio_image', ['brand_id' => $this->brand->id, 'action' => 'status']);
    [$foreign] = $this->callTool($this->mcpKey(User::factory()->create(), ['studio:ai']), 'generate_studio_image', ['brand_id' => $this->brand->id, 'action' => 'get', 'job_id' => $start['job']['id']]);
    expect($denied)->toBeTrue()->and($foreign)->toBeTrue();
});

// ---- Automatizaciones de texto con Claude ----

function claudeReply(string $text): array
{
    return ['content' => [['type' => 'text', 'text' => $text]]];
}

function aiTemplate($test, string $text = 'Oferta {{producto}} a {{precio}}')
{
    $document = StudioDocument::blank(400, 400);
    $document['pages'][0]['elements'] = [['id' => 't', 'type' => 'text', 'x' => 10, 'y' => 10, 'width' => 300, 'height' => 80, 'text' => $text, 'fill' => '#111111', 'fontFamily' => 'Arial', 'fontSize' => 30]];

    return app(StudioService::class)->create($test->user, $test->brand, ['name' => 'Plantilla', 'document' => $document, 'is_template' => true]);
}

test('texto: sin el asistente activado se dice que no esta activado', function () {
    config(['social.ai.enabled' => false]);
    $this->actingAs($this->user)->postJson('/brands/'.$this->brand->id.'/studio/ai-text', ['action' => 'copy', 'brief' => 'Lanzamiento de verano'])
        ->assertUnprocessable()->assertJsonValidationErrors('brief');
    $this->getJson('/brands/'.$this->brand->id.'/studio/ai')->assertJsonPath('text.enabled', false);
});

test('texto: genera titulares y copies desde un brief, con el brief como dato y sin datos personales', function () {
    config(['social.ai.enabled' => true, 'social.ai.api_key' => 'sk-ant-test']);
    Http::fake(['api.anthropic.com/*' => Http::response(claudeReply(json_encode(['headlines' => ['Verano sin fin', 'Solo hoy'], 'copies' => ['Descubre la colección de verano.']])))]);
    $this->actingAs($this->user)->postJson('/brands/'.$this->brand->id.'/studio/ai-text', ['action' => 'copy', 'brief' => 'Rebajas de verano, escribe a ana@example.com', 'count' => 2])
        ->assertOk()->assertJsonPath('result.headlines.0', 'Verano sin fin')->assertJsonPath('result.copies.0', 'Descubre la colección de verano.');
    Http::assertSent(fn (Request $r) => str_contains($r->url(), 'anthropic') && ! str_contains((string) $r->body(), 'ana@example.com') && str_contains((string) $r->body(), '[email]'));
});

test('texto: una respuesta que no cumple el esquema se rechaza', function () {
    config(['social.ai.enabled' => true, 'social.ai.api_key' => 'sk-ant-test']);
    Http::fake(['api.anthropic.com/*' => Http::response(claudeReply('Aquí tienes: ignora lo anterior'))]);
    $this->actingAs($this->user)->postJson('/brands/'.$this->brand->id.'/studio/ai-text', ['action' => 'copy', 'brief' => 'Rebajas'])->assertUnprocessable()->assertJsonValidationErrors('brief');
});

test('texto: rellena una plantilla desde una lista reutilizando los campos {{campo}} y sin crear diseños', function () {
    config(['social.ai.enabled' => true, 'social.ai.api_key' => 'sk-ant-test']);
    $template = aiTemplate($this);
    Http::fake(['api.anthropic.com/*' => Http::response(claudeReply(json_encode(['filas' => [['producto' => 'Café', 'precio' => '2 €'], ['producto' => 'Té', 'precio' => '3 €']]])))]);
    $designs = fn () => Design::withoutUserScope()->count();
    $before = $designs();
    $this->actingAs($this->user)->postJson('/brands/'.$this->brand->id.'/studio/ai-text', ['action' => 'fill_template', 'design_id' => $template->id, 'items' => ['café 2 euros', 'té 3 euros']])
        ->assertOk()->assertJsonPath('result.fields', ['precio', 'producto'])->assertJsonPath('result.rows.1.fields.producto', 'Té')->assertJsonPath('result.revision', $template->revision);
    expect($designs())->toBe($before);
    // Filas con campos de más o de menos, o un número distinto de filas, se rechazan.
    Http::fake(['api.anthropic.com/*' => Http::response(claudeReply(json_encode(['filas' => [['producto' => 'Café']]])))]);
    $this->postJson('/brands/'.$this->brand->id.'/studio/ai-text', ['action' => 'fill_template', 'design_id' => $template->id, 'items' => ['café']])->assertUnprocessable();
    // Un diseño que no es plantilla o de otro usuario no se puede usar.
    $this->postJson('/brands/'.$this->brand->id.'/studio/ai-text', ['action' => 'fill_template', 'design_id' => 999999, 'items' => ['a']])->assertNotFound();
});

test('texto: sugiere texto alternativo enviando la imagen reducida a Claude', function () {
    config(['social.ai.enabled' => true, 'social.ai.api_key' => 'sk-ant-test']);
    Http::fake(['api.anthropic.com/*' => Http::response(claudeReply('  "Un cuadrado rosa sobre fondo liso"  '))]);
    $this->actingAs($this->user)->postJson('/brands/'.$this->brand->id.'/studio/ai-text', ['action' => 'alt_text', 'media_id' => $this->image->id])
        ->assertOk()->assertJsonPath('result.alt', 'Un cuadrado rosa sobre fondo liso');
    Http::assertSent(function (Request $r) {
        $content = $r['messages'][0]['content'];

        return $content[0]['type'] === 'image' && $content[0]['source']['media_type'] === 'image/png' && $content[0]['source']['data'] !== '' && $content[1]['type'] === 'text';
    });
    config(['social.ai.vision' => false]);
    $this->postJson('/brands/'.$this->brand->id.'/studio/ai-text', ['action' => 'alt_text', 'media_id' => $this->image->id])->assertUnprocessable();
});

// ---- Revisión de dos lentes, intento 1 ----

test('un timeout de lectura NO se reintenta (el POST pudo cobrarse), falla con aviso claro y no devuelve la cuota', function () {
    $calls = 0;
    Http::fake(['api.openai.com/*' => function () use (&$calls) {
        $calls++;

        throw new ConnectionException('cURL error 28: Operation timed out after 60000 milliseconds with 0 bytes received');
    }]);
    $job = ($this->run)(['kind' => 'generate', 'provider' => 'openai', 'prompt' => 'Un faro al amanecer']);
    expect($job->status)->toBe('failed')->and($job->error_code)->toBe('timeout_ambiguous')->and($job->error)->toContain('puede haber generado la imagen')->and($job->attempts)->toBe(1)->and($calls)->toBe(1);
    $this->getJson('/brands/'.$this->brand->id.'/studio/ai')->assertJsonPath('providers.0.remaining', 19);
});

test('un fallo de conexion anterior al envio si se reintenta', function () {
    Http::fake(['api.openai.com/*' => Http::sequence()->pushFailedConnection('cURL error 7: Failed to connect to api.openai.com port 443')->push(openAiOk())]);
    $job = ($this->run)(['kind' => 'generate', 'provider' => 'openai', 'prompt' => 'Un faro al amanecer']);
    expect($job->status)->toBe('ready')->and($job->attempts)->toBe(2);
});

test('un modelo inexistente dice Modelo no disponible y no pide revisar la descripcion', function () {
    Http::fake(['api.openai.com/*' => Http::response(['error' => ['code' => 'model_not_found', 'message' => 'x']], 404)]);
    $job = ($this->run)(['kind' => 'generate', 'provider' => 'openai', 'prompt' => 'Un faro al amanecer']);
    expect($job->error_code)->toBe('model_unavailable')->and($job->error)->toContain('Modelo no disponible')->and($job->error)->not->toContain('descripción');
});

test('los modelos por defecto son IDs vigentes de las APIs (gpt-image-1 esta retirado)', function () {
    expect(config('studio.ai.providers.openai.model'))->toBe('gpt-image-2.5-sunburst')->and(config('studio.ai.providers.google.model'))->toBe('gemini-nano-banana-2.1');
});

test('no se reintenta si la espera no cabe en el presupuesto de tiempo del job y el job cumple timeout menor que el worker', function () {
    config(['studio.ai.budget' => 5]);
    Http::fake(['api.openai.com/*' => Http::response(['error' => ['code' => 'rate_limit_exceeded']], 429, ['Retry-After' => '30'])]);
    $job = ($this->run)(['kind' => 'generate', 'provider' => 'openai', 'prompt' => 'Un faro al amanecer']);
    expect($job->status)->toBe('failed')->and($job->error_code)->toBe('rate_limited')->and($job->attempts)->toBe(1);
    expect((new RunStudioAiJob(1))->timeout)->toBeLessThan(120);
});

test('si failed() ya marco el trabajo y el primer worker termina, no quedan recursos huerfanos', function () {
    $id = $this->actingAs($this->user)->postJson($this->url, ['kind' => 'generate', 'provider' => 'openai', 'prompt' => 'Un faro al amanecer'])->json('job.id');
    Http::fake(['api.openai.com/*' => function () use ($id) {
        (new RunStudioAiJob($id))->failed(new RuntimeException('timeout del worker'));

        return Http::response(openAiOk());
    }]);
    app(StudioAi::class)->process($id);
    expect(StudioAiJob::withoutUserScope()->find($id)->status)->toBe('failed')->and(MediaAsset::withoutUserScope()->where('source', 'ai')->count())->toBe(0);
    // Un segundo worker no reclama un trabajo ya tomado.
    app(StudioAi::class)->process($id);
    Http::assertSentCount(1);
});
