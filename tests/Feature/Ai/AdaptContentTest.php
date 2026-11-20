<?php

use App\Connectors\Network;
use App\Models\Brand;
use App\Models\MediaAsset;
use App\Models\SocialAccount;
use App\Models\User;
use App\Support\TargetMedia;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;

// T-82: POST ai/adapt (una versión del texto por red, validada contra `media_specs`) y POST ai/alt (alt text desde el compositor).
// La IA va siempre simulada con Http::fake; nada llega a la red.

beforeEach(function () {
    Cache::flush();
    Http::preventStrayRequests();
    Storage::fake('local');
    config(['social.ai' => ['enabled' => true, 'api_key' => 'sk-ant-TEST', 'model' => 'claude-sonnet-5-5', 'max_tokens' => 600, 'timeout' => 30, 'daily_limit' => 20, 'global_daily_limit' => 0, 'vision' => true]]);
    $this->user = User::factory()->create();
    $this->brand = Brand::factory()->for($this->user)->create(['name' => 'Panadería Sol']);
    $this->ig = SocialAccount::factory()->for($this->user)->create(['brand_id' => $this->brand->id, 'network' => Network::Instagram]);
    $this->bs = SocialAccount::factory()->for($this->user)->create(['brand_id' => $this->brand->id, 'network' => Network::Bluesky]);
    $this->targets = [
        ['account_id' => $this->ig->id, 'content_type' => 'instagram_image'],
        ['account_id' => $this->bs->id, 'content_type' => 'bluesky_post'],
    ];
});

function claudeText(string $text): array
{
    return ['content' => [['type' => 'text', 'text' => $text]]];
}

function fakeVersions(array $versions): void
{
    Http::fake(['api.anthropic.com/*' => Http::response(claudeText(json_encode(['versions' => $versions], JSON_UNESCAPED_UNICODE)))]);
}

function adapt(array $overrides = []): TestResponse
{
    return test()->actingAs(test()->user)->postJson(route('ai.adapt'), $overrides + ['brand_id' => test()->brand->id, 'text' => 'Hoy estrenamos pan de masa madre en Panadería Sol.', 'targets' => test()->targets]);
}

test('devuelve una versión por red dentro de sus límites y no aplica nada', function () {
    fakeVersions([(string) $this->ig->id => 'Pan de masa madre recién horneado. #pan #masamadre', (string) $this->bs->id => 'Pan de masa madre, hoy en Panadería Sol.']);

    adapt()->assertOk()
        ->assertJsonPath('results.0.account_id', $this->ig->id)
        ->assertJsonPath('results.0.text', 'Pan de masa madre recién horneado. #pan #masamadre')
        ->assertJsonPath('results.0.error', null)
        ->assertJsonPath('results.1.account_id', $this->bs->id)
        ->assertJsonPath('results.1.text', 'Pan de masa madre, hoy en Panadería Sol.')
        ->assertJsonPath('remaining', 19);
});

test('una versión que excede el máximo de su red se rechaza con mensaje y no se trunca', function () {
    $long = str_repeat('a', 301);
    fakeVersions([(string) $this->ig->id => 'Versión corta para Instagram.', (string) $this->bs->id => $long]);

    $response = adapt()->assertOk()
        ->assertJsonPath('results.0.text', 'Versión corta para Instagram.')
        ->assertJsonPath('results.1.text', null);
    expect($response->json('results.1.error'))->toContain('301')->toContain('300');
});

test('una versión con más hashtags que el máximo de la red se rechaza', function () {
    $tags = implode(' ', array_map(fn ($i) => "#tag$i", range(1, 31)));
    fakeVersions([(string) $this->ig->id => "Pan. $tags", (string) $this->bs->id => 'Pan.']);

    $response = adapt()->assertOk()->assertJsonPath('results.0.text', null)->assertJsonPath('results.1.text', 'Pan.');
    expect($response->json('results.0.error'))->toContain('31 hashtags')->toContain('30');
});

test('JSON inválido: error manejable y el uso se gasta (el proveedor respondió)', function () {
    Http::fake(['api.anthropic.com/*' => Http::response(claudeText('No puedo hacerlo, lo siento.'))]);

    adapt()->assertStatus(422)->assertJsonPath('remaining', 19);
});

test('si falta una red en la respuesta es un error y el uso se gasta', function () {
    fakeVersions([(string) $this->ig->id => 'Solo Instagram.']);

    adapt()->assertStatus(422)->assertJsonPath('remaining', 19);
});

test('si ninguna versión cabe el uso se gasta igualmente', function () {
    fakeVersions([(string) $this->ig->id => str_repeat('a', 2201), (string) $this->bs->id => str_repeat('a', 301)]);

    adapt()->assertOk()->assertJsonPath('results.0.text', null)->assertJsonPath('results.1.text', null)->assertJsonPath('remaining', 19);
});

test('una adaptación consume una sola unidad de cuota, no una por red', function () {
    fakeVersions([(string) $this->ig->id => 'Instagram.', (string) $this->bs->id => 'Bluesky.']);

    adapt()->assertOk()->assertJsonPath('remaining', 19);
    Http::assertSentCount(1);
});

test('sin clave responde 409 con explicación y no llama a ningún servicio', function () {
    config(['social.ai.api_key' => '']);
    Http::fake();

    adapt()->assertStatus(409)->assertJsonPath('message', 'El asistente IA no está activado.');
    Http::assertNothingSent();
});

test('una marca de otro usuario responde 404 y no llama a la IA', function () {
    Http::fake();
    $other = Brand::factory()->for(User::factory()->create())->create();

    adapt(['brand_id' => $other->id])->assertNotFound();
    Http::assertNothingSent();
});

test('una cuenta que no es de la marca responde 404', function () {
    Http::fake();
    $foreign = SocialAccount::factory()->for(User::factory()->create())->create(['network' => Network::Instagram]);

    adapt(['targets' => [['account_id' => $foreign->id, 'content_type' => 'instagram_image']]])->assertNotFound();
    Http::assertNothingSent();
});

test('valida el texto, los destinos y el tipo de contenido', function () {
    Http::fake();

    adapt(['text' => ''])->assertStatus(422)->assertJsonValidationErrors('text');
    adapt(['targets' => []])->assertStatus(422)->assertJsonValidationErrors('targets');
    adapt(['targets' => [['account_id' => $this->ig->id, 'content_type' => 'nada']]])->assertStatus(422)->assertJsonValidationErrors('targets.0.content_type');
    adapt(['targets' => [['account_id' => $this->ig->id, 'content_type' => 'bluesky_post']]])->assertStatus(422)->assertJsonValidationErrors('targets.0.content_type');
    Http::assertNothingSent();
});

test('envía el texto sin datos personales y como dato, no como instrucción', function () {
    fakeVersions([(string) $this->ig->id => 'Instagram.', (string) $this->bs->id => 'Bluesky.']);

    adapt(['text' => 'Escríbenos a hola@sol.test o llama al +34 600 123 456 y visita https://sol.test/pan'])->assertOk();

    Http::assertSent(function (Request $r) {
        $body = (string) $r['messages'][0]['content'];

        return str_contains($r['system'], 'dato') && ! str_contains($body, 'hola@sol.test') && ! str_contains($body, '600 123 456') && ! str_contains($body, 'sol.test/pan')
            && str_contains($body, 'Panadería Sol') && str_contains($body, 'instagram');
    });
});

test('los enlaces, correos y teléfonos redactados se restauran en la versión devuelta', function () {
    Http::fake(function (Request $r) {
        preg_match('/\[enlace\d+\]/', (string) $r['messages'][0]['content'], $m);

        return Http::response(claudeText(json_encode(['versions' => [(string) $this->ig->id => "Mira {$m[0]}", (string) $this->bs->id => "Mira {$m[0]}"]])));
    });

    adapt(['text' => 'Mira https://sol.test/pan'])->assertOk()->assertJsonPath('results.0.text', 'Mira https://sol.test/pan');
});

test('hace falta sesión', function () {
    $this->postJson(route('ai.adapt'), [])->assertUnauthorized();
    $this->postJson(route('ai.alt'), [])->assertUnauthorized();
});

describe('alt text desde el compositor', function () {
    beforeEach(function () {
        $png = imagecreatetruecolor(300, 200);
        ob_start();
        imagepng($png);
        $bytes = (string) ob_get_clean();
        $this->image = MediaAsset::factory()->for($this->user)->create([
            'disk' => 'local', 'path' => 'media/'.$this->user->id.'/photo.png', 'mime' => 'image/png', 'kind' => 'image', 'status' => 'listo',
            'size' => strlen($bytes), 'width' => 300, 'height' => 200,
        ]);
        Storage::disk('local')->put($this->image->path, $bytes);
    });

    test('genera un alt text editable de una imagen propia y consume una unidad', function () {
        Http::fake(['api.anthropic.com/*' => Http::response(claudeText('  "Una barra de pan sobre una mesa de madera"  '))]);

        $response = $this->actingAs($this->user)->postJson(route('ai.alt'), ['media_id' => $this->image->id])
            ->assertOk()->assertJsonPath('text', 'Una barra de pan sobre una mesa de madera')->assertJsonPath('remaining', 19);
        expect(mb_strlen($response->json('text')))->toBeLessThanOrEqual(TargetMedia::ALT_MAX);
    });

    test('una media de otro usuario responde 404 y no llama a la IA', function () {
        Http::fake();
        $foreign = MediaAsset::factory()->for(User::factory()->create())->create(['kind' => 'image', 'mime' => 'image/png', 'status' => 'listo']);

        $this->actingAs($this->user)->postJson(route('ai.alt'), ['media_id' => $foreign->id])->assertNotFound()->assertJsonPath('message', 'No se ha encontrado la imagen.');
        Http::assertNothingSent();
    });

    test('una imagen de la biblioteca sin vínculo a Recursos, o de otra marca del mismo usuario, también se puede describir', function () {
        Http::fake(['api.anthropic.com/*' => Http::response(claudeText('Una barra de pan'))]);

        $this->actingAs($this->user)->postJson(route('ai.alt'), ['media_id' => $this->image->id])->assertOk()->assertJsonPath('text', 'Una barra de pan');
    });

    test('una media que no es una imagen lista responde 422 en castellano', function () {
        Http::fake();
        $video = MediaAsset::factory()->for($this->user)->video()->create();

        $this->actingAs($this->user)->postJson(route('ai.alt'), ['media_id' => $video->id])->assertStatus(422)
            ->assertJsonPath('errors.media_id.0', 'Selecciona una imagen PNG, JPEG o WebP propia.');
        Http::assertNothingSent();
    });

    test('sin clave responde 409 y no llama a ningún servicio', function () {
        config(['social.ai.enabled' => false]);
        Http::fake();

        $this->actingAs($this->user)->postJson(route('ai.alt'), ['media_id' => $this->image->id])->assertStatus(409);
        Http::assertNothingSent();
    });
});

test('una URL que contiene un correo se enmascara entera y vuelve intacta', function () {
    Http::fake(function (Request $r) {
        $body = (string) $r['messages'][0]['content'];
        preg_match('/\[enlace\d+\]/', $body, $m);
        $this->sentBody = $body;

        return Http::response(claudeText(json_encode(['versions' => [(string) $this->ig->id => "Mira {$m[0]}", (string) $this->bs->id => "Mira {$m[0]}"]])));
    });

    adapt(['text' => 'Mira https://sol.test/c?to=hola@sol.test ya'])->assertOk()->assertJsonPath('results.0.text', 'Mira https://sol.test/c?to=hola@sol.test');
    expect($this->sentBody)->not->toContain('[email')->not->toContain('hola@sol.test');
});

test('al modelo se le da el límite efectivo: el máximo menos lo que crece el texto al restaurar los marcadores', function () {
    $url = 'https://sol.test/pan?utm_source='.str_repeat('x', 110); // 140 caracteres
    $extra = mb_strlen($url) - mb_strlen('[enlace1]');
    $max = null;
    Http::fake(function (Request $r) use (&$max) {
        $input = json_decode((string) $r['messages'][0]['content'], true);
        $max = collect($input['destinos'])->firstWhere('red', 'bluesky')['maximo'];

        return Http::response(claudeText(json_encode(['versions' => [(string) $this->ig->id => 'Hola [enlace1]', (string) $this->bs->id => 'Hola [enlace1]']])));
    });

    adapt(['text' => "Hola $url"])->assertOk()->assertJsonPath('results.1.text', "Hola $url");
    expect($max)->toBe((300 - $extra).' grafemas');
});

test('una versión sin un marcador del original se rechaza: no se pierde un dato en silencio', function () {
    fakeVersions([(string) $this->ig->id => 'Sin enlace.', (string) $this->bs->id => 'Con [enlace1]']);

    $response = adapt(['text' => 'Mira https://sol.test/pan'])->assertOk()->assertJsonPath('results.0.text', null)->assertJsonPath('results.1.text', 'Con https://sol.test/pan');
    expect($response->json('results.0.error'))->toContain('enlace');
});

test('una versión con un marcador inventado se rechaza', function () {
    fakeVersions([(string) $this->ig->id => 'Mira [enlace9]', (string) $this->bs->id => 'Normal.']);

    adapt()->assertOk()->assertJsonPath('results.0.text', null)->assertJsonPath('results.1.text', 'Normal.');
});

test('una respuesta inválida gasta el cupo del usuario y el global, porque la llamada ya se pagó', function () {
    Http::fake(['api.anthropic.com/*' => Http::response(claudeText('no es json'))]);

    adapt()->assertStatus(422)->assertJsonPath('remaining', 19);
    expect((int) Cache::get('ai:global:'.now('UTC')->toDateString()))->toBe(1);
});

test('el presupuesto de salida crece con la entrada y las redes y respeta el tope de la config', function () {
    $tokens = [];
    Http::fake(function (Request $r) use (&$tokens) {
        $tokens[] = $r['max_tokens'];

        return Http::response(claudeText('{}'));
    });
    $fb = SocialAccount::factory()->for($this->user)->create(['brand_id' => $this->brand->id, 'network' => Network::Facebook]);
    $targets = [['account_id' => $fb->id, 'content_type' => 'facebook_text']];

    adapt(['text' => str_repeat('palabra ', 600), 'targets' => $targets]);
    config(['social.ai.adapt_max_tokens' => 500]);
    adapt(['text' => str_repeat('palabra ', 600), 'targets' => $targets]);
    adapt(['text' => 'corto', 'targets' => $targets]);

    expect($tokens[0])->toBeGreaterThan(4096)->and($tokens[1])->toBe(500)->and($tokens[2])->toBeLessThan($tokens[0]);
});

test('un marcador literal escrito por el usuario no colisiona con los del servidor', function () {
    Http::fake(function (Request $r) {
        $texto = json_decode((string) $r['messages'][0]['content'], true)['texto'];

        return Http::response(claudeText(json_encode(['versions' => [(string) $this->ig->id => "Resumen: $texto", (string) $this->bs->id => "Resumen: $texto"]], JSON_UNESCAPED_UNICODE)));
    });

    adapt(['text' => 'Escribe a [email3] y mira https://sol.test/x'])->assertOk()
        ->assertJsonPath('results.0.text', 'Resumen: Escribe a [email3] y mira https://sol.test/x')
        ->assertJsonPath('results.1.error', null);
});
