<?php

use App\Connectors\Network;
use App\Enums\PostStatus;
use App\Models\Brand;
use App\Models\Post;
use App\Models\SocialAccount;
use App\Models\User;
use App\Services\AiAssistant;
use App\Services\ContentPlanService;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;

// T-93 (F-22): plan de contenido en lenguaje natural. La IA PROPONE un lote de borradores con fecha (nada se crea); el usuario revisa,
// edita y desmarca, y al confirmar el servidor revalida todo y crea el lote en una transaccion, idempotente. La respuesta del modelo es
// un dato no confiable. La IA va siempre simulada con Http::fake.

beforeEach(function () {
    Cache::flush();
    Http::preventStrayRequests();
    Carbon::setTestNow('2026-11-30 08:00:00'); // lunes, 09:00 en Madrid
    config(['social.ai' => ['enabled' => true, 'api_key' => 'sk-ant-TEST', 'model' => 'claude-sonnet-5-5', 'max_tokens' => 600, 'timeout' => 30, 'daily_limit' => 20, 'global_daily_limit' => 0, 'plan_max_items' => 3]]);
    $this->user = User::factory()->create();
    $this->brand = Brand::factory()->for($this->user)->create(['timezone' => 'Europe/Madrid', 'name' => 'Panadería Sol']);
    $this->bs = SocialAccount::factory()->for($this->user)->create(['brand_id' => $this->brand->id, 'network' => Network::Bluesky, 'name' => 'Sol BS']);
    $this->ma = SocialAccount::factory()->for($this->user)->create(['brand_id' => $this->brand->id, 'network' => Network::Mastodon, 'name' => 'Sol MA']);
    $this->ig = SocialAccount::factory()->for($this->user)->create(['brand_id' => $this->brand->id, 'network' => Network::Instagram, 'name' => 'Sol IG']);

    $this->otherBrand = Brand::factory()->for($this->user)->create(['timezone' => 'Europe/Madrid']);
    $this->otherBrandAccount = SocialAccount::factory()->for($this->user)->create(['brand_id' => $this->otherBrand->id, 'network' => Network::Bluesky]);
    $this->stranger = User::factory()->create();
    $this->strangerBrand = Brand::factory()->for($this->stranger)->create();
    $this->strangerAccount = SocialAccount::factory()->for($this->stranger)->create(['brand_id' => $this->strangerBrand->id, 'network' => Network::Bluesky]);
});

function planItem(array $overrides = []): array
{
    return $overrides + ['datetime' => '2026-12-02T09:00', 'accounts' => [(string) test()->bs->id, (string) test()->ma->id], 'text' => 'Estrenamos pan de masa madre.'];
}

function fakePlan(array $items): void
{
    Http::fake(['api.anthropic.com/*' => Http::response(['content' => [['type' => 'text', 'text' => json_encode(['items' => $items], JSON_UNESCAPED_UNICODE)]]])]);
}

function propose(array $overrides = []): TestResponse
{
    return test()->actingAs(test()->user)->postJson(route('ai.plan'), $overrides + ['brand_id' => test()->brand->id, 'briefing' => 'La semana que viene, 3 posts sobre el pan de masa madre, por la mañana.']);
}

function confirmPlan(array $overrides = []): TestResponse
{
    return test()->actingAs(test()->user)->postJson(route('content-plan.store'), $overrides + [
        'brand_id' => test()->brand->id,
        'idempotency_key' => 'plan-'.uniqid(),
        'mode' => 'draft',
        'items' => [['scheduled_at' => '2026-12-02T09:00', 'account_ids' => [test()->bs->id], 'text' => 'Primero']],
    ]);
}

function postCount(): int
{
    return Post::withoutUserScope()->count();
}

test('proponer devuelve el lote normalizado, gasta una unidad de cuota y NO crea nada', function () {
    fakePlan([planItem(), planItem(['datetime' => '2026-12-03T10:30', 'accounts' => [(string) test()->bs->id], 'text' => 'Segundo post.'])]);

    $response = propose()->assertOk()
        ->assertJsonPath('items.0.scheduled_at', '2026-12-02T09:00')
        ->assertJsonPath('items.0.account_ids', [$this->bs->id, $this->ma->id])
        ->assertJsonPath('items.0.problems', [])
        ->assertJsonPath('items.1.text', 'Segundo post.')
        ->assertJsonPath('remaining', 19);
    expect($response->json('items'))->toHaveCount(2)
        // Instagram necesita media: no entra en un plan de solo texto y se dice.
        ->and(collect($response->json('excluded'))->pluck('id')->all())->toBe([$this->ig->id]);

    expect(postCount())->toBe(0);
    Http::assertSentCount(1);
    Http::assertSent(function ($request) {
        $sent = json_decode($request['messages'][0]['content'], true);

        return $sent['zona_horaria'] === 'Europe/Madrid' && $sent['max_elementos'] === 3
            && collect($sent['cuentas'])->pluck('id')->all() === [(string) $this->bs->id, (string) $this->ma->id]
            && ! str_contains($request['messages'][0]['content'], 'Sol BS');
    });
});

test('el briefing viaja sin correos ni teléfonos como valor de un JSON', function () {
    fakePlan([planItem()]);

    propose(['briefing' => 'Avisa a ana@ejemplo.com y llama al +34 600 123 456 sobre el lanzamiento'])->assertOk();

    Http::assertSent(function ($request) {
        $sent = json_decode($request['messages'][0]['content'], true);

        return ! str_contains($sent['briefing'], 'ana@ejemplo.com') && ! str_contains($sent['briefing'], '600 123 456') && str_contains($sent['briefing'], 'lanzamiento');
    });
});

test('la respuesta del modelo es un dato no confiable: cuentas ajenas, fechas pasadas, claves de más y tope', function () {
    fakePlan([
        // Cuenta de otra marca y de otro usuario: se descartan; queda la propia.
        planItem(['accounts' => [(string) $this->bs->id, (string) $this->otherBrandAccount->id, (string) $this->strangerAccount->id]]),
        // Solo cuentas ajenas: el elemento entero se descarta.
        planItem(['accounts' => [(string) $this->strangerAccount->id]]),
        // Cuenta de Instagram (necesita media): fuera.
        planItem(['accounts' => [(string) $this->ig->id]]),
        // Fecha pasada y fecha de hace un año.
        planItem(['datetime' => '2026-11-29T09:00']),
        // Clave de más (el modelo intenta elegir el tipo de contenido).
        planItem(['content_type' => 'instagram_image']),
        // Hora que no existe (cambio de hora) y formato con zona.
        planItem(['datetime' => '2027-03-28T02:30']),
        planItem(['datetime' => '2026-12-02T09:00:00+05:00']),
        // Válidos: solo caben 3 (el tope).
        planItem(['datetime' => '2026-12-04T09:00', 'text' => 'A']),
        planItem(['datetime' => '2026-12-05T09:00', 'text' => 'B']),
        planItem(['datetime' => '2026-12-06T09:00', 'text' => 'C']),
        planItem(['datetime' => '2026-12-07T09:00', 'text' => 'D']),
    ]);

    $response = propose()->assertOk();
    $items = $response->json('items');

    expect($items)->toHaveCount(3)
        ->and($items[0]['account_ids'])->toBe([$this->bs->id])
        ->and(collect($items)->pluck('text')->all())->toBe(['Estrenamos pan de masa madre.', 'A', 'B'])
        ->and(collect($items)->flatMap(fn ($i) => $i['account_ids'])->unique()->sort()->values()->all())->toBe([$this->bs->id, $this->ma->id])
        ->and($response->json('notices'))->not->toBeEmpty();
    foreach ($items as $item) {
        expect(Carbon::parse($item['scheduled_at'], 'Europe/Madrid')->isFuture())->toBeTrue();
    }
    expect(postCount())->toBe(0);
});

test('con más elementos que el tope se muestran los primeros y se avisa', function () {
    fakePlan(array_map(fn ($d) => planItem(['datetime' => "2026-12-0{$d}T09:00", 'text' => "Post $d"]), [1, 2, 3, 4, 5]));

    $response = propose()->assertOk();

    expect($response->json('items'))->toHaveCount(3)
        ->and(implode(' ', $response->json('notices')))->toContain('3');
});

test('un texto que no cabe en una red queda marcado para revisión y nunca se recorta', function () {
    $long = str_repeat('a', 301); // Bluesky: 300; Mastodon admite 500
    fakePlan([planItem(['text' => $long])]);

    $response = propose()->assertOk();

    expect($response->json('items.0.text'))->toBe($long)
        ->and($response->json('items.0.problems'))->toHaveCount(1)
        ->and($response->json('items.0.problems.0.account_id'))->toBe($this->bs->id)
        ->and($response->json('items.0.problems.0.message'))->toContain('301')->toContain('300');
});

test('JSON roto o sin elementos válidos: 422 sin crear nada y el uso se gasta (el proveedor respondió)', function () {
    Http::fake(['api.anthropic.com/*' => Http::response(['content' => [['type' => 'text', 'text' => 'Claro, aquí tienes tu plan: ...']]])]);

    propose()->assertStatus(422)->assertJsonPath('remaining', 19);
    fakePlan([planItem(['datetime' => '2020-01-01T09:00'])]);
    propose()->assertStatus(422)->assertJsonPath('remaining', 18);
    fakePlan([]);
    propose()->assertStatus(422);

    expect(postCount())->toBe(0);
});

test('política de cuota: un rechazo HTTP del proveedor devuelve el uso; un timeout no', function () {
    Http::fake(['api.anthropic.com/*' => Http::response(['error' => ['type' => 'overloaded_error']], 529)]);
    propose()->assertStatus(422)->assertJsonPath('remaining', 20);

    Http::fake(['api.anthropic.com/*' => fn () => throw new ConnectionException('timeout')]);
    propose()->assertStatus(422)->assertJsonPath('remaining', 19);
});

test('sin IA configurada responde 409 sin llamar a nadie; el límite diario sigue aplicando', function () {
    config(['social.ai.enabled' => false]);
    propose()->assertStatus(409)->assertJsonPath('message', 'El asistente IA no está activado.'); // preventStrayRequests: una llamada fallaría

    config(['social.ai.enabled' => true, 'social.ai.daily_limit' => 1]);
    fakePlan([planItem()]);
    propose()->assertOk();
    propose()->assertStatus(422)->assertJsonPath('message', 'Has alcanzado el límite diario de sugerencias.');
});

test('el briefing vacío o demasiado largo y una marca sin cuentas de solo texto se rechazan sin llamar a la IA', function () {
    Http::fake();
    propose(['briefing' => '   '])->assertStatus(422);
    propose(['briefing' => str_repeat('a', 1001)])->assertStatus(422);

    $empty = Brand::factory()->for($this->user)->create();
    SocialAccount::factory()->for($this->user)->create(['brand_id' => $empty->id, 'network' => Network::Instagram]);
    propose(['brand_id' => $empty->id])->assertStatus(422)->assertJsonPath('message', fn ($m) => str_contains($m, 'solo texto'));

    Http::assertNothingSent();
    expect($this->app->make(AiAssistant::class)->remaining($this->user))->toBe(20);
});

test('aislamiento: una marca ajena responde 404 al proponer y al confirmar sin llamar a la IA ni crear nada', function () {
    Http::fake();

    propose(['brand_id' => $this->strangerBrand->id])->assertNotFound();
    confirmPlan(['brand_id' => $this->strangerBrand->id, 'items' => [['scheduled_at' => '2026-12-02T09:00', 'account_ids' => [$this->strangerAccount->id], 'text' => 'x']]])->assertNotFound();
    $this->actingAs($this->stranger)->postJson(route('ai.plan'), ['brand_id' => $this->brand->id, 'briefing' => 'hola'])->assertNotFound();

    Http::assertNothingSent();
    expect(postCount())->toBe(0);
});

test('confirmar una selección parcial crea solo lo elegido, como borradores con su fecha', function () {
    $response = confirmPlan(['items' => [
        ['scheduled_at' => '2026-12-02T09:00', 'account_ids' => [$this->bs->id, $this->ma->id], 'text' => 'Primero, editado por el usuario'],
        ['scheduled_at' => '2026-12-04T10:30', 'account_ids' => [$this->ma->id], 'text' => 'Tercero'],
    ]])->assertOk()->assertJsonPath('created', 2)->assertJsonPath('mode', 'draft');

    $posts = Post::withoutUserScope()->orderBy('id')->get();
    expect($posts)->toHaveCount(2)
        ->and($posts[0]->text)->toBe('Primero, editado por el usuario')
        ->and($posts[0]->status)->toBe(PostStatus::Draft)
        ->and($posts[0]->scheduled_at->utc()->format('Y-m-d H:i'))->toBe('2026-12-02 08:00') // 09:00 de Madrid
        ->and($posts[0]->targets()->pluck('social_account_id')->sort()->values()->all())->toBe([$this->bs->id, $this->ma->id])
        ->and($posts[0]->targets()->pluck('content_type')->map(fn ($t) => $t->value)->sort()->values()->all())->toBe(['bluesky_post', 'mastodon_post'])
        ->and($posts[1]->scheduled_at->utc()->format('Y-m-d H:i'))->toBe('2026-12-04 09:30')
        ->and($response->json('post_ids'))->toBe($posts->pluck('id')->all());
});

test('con el modo «programar» las publicaciones quedan programadas', function () {
    confirmPlan(['mode' => 'schedule'])->assertOk()->assertJsonPath('mode', 'schedule');

    expect(Post::withoutUserScope()->sole()->status)->toBe(PostStatus::Scheduled);
});

test('el servidor revalida: un payload manipulado se rechaza entero y no deja nada a medias', function (array $change, string $needle) {
    $good = ['scheduled_at' => '2026-12-02T09:00', 'account_ids' => [$this->bs->id], 'text' => 'Bien'];
    // Las cuentas se piden por nombre de propiedad del test: el dataset se evalúa antes de crearlas.
    if (isset($change['account_ids'])) {
        $change['account_ids'] = array_map(fn (string $name) => $this->{$name}->id, $change['account_ids']);
    }

    $response = confirmPlan(['items' => [$good, $change + $good]])->assertStatus(422);

    expect($response->json('already_used'))->toBeFalse();
    expect(json_encode($response->json('errors'), JSON_UNESCAPED_UNICODE))->toContain($needle)
        ->and(array_keys($response->json('errors')))->toBe(['items.1'])
        ->and(postCount())->toBe(0);
})->with([
    'cuenta de otra marca' => [['account_ids' => ['otherBrandAccount']], 'cuenta'],
    'cuenta de otro usuario' => [['account_ids' => ['strangerAccount']], 'cuenta'],
    'cuenta que necesita media' => [['account_ids' => ['ig']], 'cuenta'],
    'fecha pasada' => [['scheduled_at' => '2026-11-29T09:00'], 'futura'],
    'fecha con formato libre' => [['scheduled_at' => 'mañana'], 'fecha'],
    'hora inexistente' => [['scheduled_at' => '2027-03-28T02:30'], 'fecha'],
    'fecha a más de un año' => [['scheduled_at' => '2028-12-02T09:00'], 'año'],
    'texto vacío' => [['text' => '  '], 'texto'],
    'texto que no cabe en Bluesky' => [['text' => str_repeat('a', 301)], '300'],
    'sin cuentas' => [['account_ids' => []], 'cuenta'],
]);

test('el lote respeta el tope de elementos y exige la clave de idempotencia y un modo válido', function () {
    $item = ['scheduled_at' => '2026-12-02T09:00', 'account_ids' => [$this->bs->id], 'text' => 'x'];

    confirmPlan(['items' => array_fill(0, 4, $item)])->assertStatus(422);
    confirmPlan(['items' => []])->assertStatus(422);
    confirmPlan(['mode' => 'publicar'])->assertStatus(422);
    confirmPlan(['idempotency_key' => ''])->assertStatus(422);

    expect(postCount())->toBe(0);
});

test('una doble confirmación con la misma clave no duplica el lote', function () {
    $payload = ['idempotency_key' => 'plan-doble-clic', 'items' => [
        ['scheduled_at' => '2026-12-02T09:00', 'account_ids' => [$this->bs->id], 'text' => 'Uno'],
        ['scheduled_at' => '2026-12-03T09:00', 'account_ids' => [$this->bs->id], 'text' => 'Dos'],
    ]];

    $first = confirmPlan($payload)->assertOk()->assertJsonPath('replayed', false);
    $second = confirmPlan($payload)->assertOk()->assertJsonPath('replayed', true)->assertJsonPath('created', 2);

    expect(postCount())->toBe(2)->and($second->json('post_ids'))->toBe($first->json('post_ids'));

    // La misma clave con otro cuerpo no se acepta.
    confirmPlan(['idempotency_key' => 'plan-doble-clic', 'items' => [['scheduled_at' => '2026-12-09T09:00', 'account_ids' => [$this->bs->id], 'text' => 'Otro']]])->assertStatus(422)->assertJsonPath('already_used', true)->assertJsonPath('message', fn ($m) => str_contains($m, 'ya se confirmó'));
    expect(postCount())->toBe(2);
});

test('confirmar no necesita la IA ni gasta cuota', function () {
    config(['social.ai.enabled' => false]);
    Http::fake();

    confirmPlan()->assertOk();

    expect(postCount())->toBe(1);
    Http::assertNothingSent();
});

test('la página del plan lista las cuentas que admiten texto y las que no', function () {
    $this->actingAs($this->user)->get(route('content-plan.index', ['brand_id' => $this->brand->id]))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->component('Posts/Plan')
            ->where('brand.id', $this->brand->id)
            ->where('maxItems', 3)
            ->where('accounts', fn ($accounts) => collect($accounts)->pluck('id')->all() === [$this->bs->id, $this->ma->id])
            ->where('excluded', fn ($excluded) => collect($excluded)->pluck('id')->all() === [$this->ig->id]));
});

test('el texto conserva los emojis compuestos y las banderas, y pierde solo controles e invisibles peligrosos', function () {
    $family = "\u{1F468}\u{200D}\u{1F469}\u{200D}\u{1F467}";
    $scotland = "\u{1F3F4}\u{E0067}\u{E0062}\u{E0073}\u{E0063}\u{E0074}\u{E007F}";
    fakePlan([planItem(['text' => "Hola $family $scotland\u{202E}ocultar\u{200B}\u{0007}"])]);

    propose()->assertOk()->assertJsonPath('items.0.text', "Hola $family $scotland".'ocultar');
});

test('si el proveedor corta la respuesta por longitud se devuelve un error claro y el uso se gasta', function () {
    Http::fake(['api.anthropic.com/*' => Http::response(['stop_reason' => 'max_tokens', 'content' => [['type' => 'text', 'text' => '{"items":[{"datetime":"2026-12-02T09:00","acc']]])]);

    propose()->assertStatus(422)->assertJsonPath('remaining', 19)->assertJsonPath('message', fn ($m) => str_contains($m, 'se cortó'));
    expect(postCount())->toBe(0);
});

test('al modelo se le pide una longitud máxima por texto acotada', function () {
    fakePlan([planItem()]);

    propose()->assertOk();

    Http::assertSent(function ($request) {
        $sent = json_decode($request['messages'][0]['content'], true);

        return $sent['maximo_texto'] === 500 && $request['max_tokens'] === 300 + 3 * (500 + 80);
    });
});

test('si 10 textos no caben en el tope de tokens se acorta el texto pedido en lugar de pedir algo que se cortaría', function () {
    config(['social.ai.plan_max_items' => 10, 'social.ai.adapt_max_tokens' => 2000]);
    fakePlan([planItem()]);

    $response = propose()->assertOk();
    expect(implode(' ', $response->json('notices')))->toContain('hasta 90 caracteres');

    Http::assertSent(fn ($request) => json_decode($request['messages'][0]['content'], true)['maximo_texto'] === 90 && $request['max_tokens'] === 2000);
});

test('una clave mal formada o sin registro no se marca como ya usada y se puede reintentar', function () {
    $response = confirmPlan(['idempotency_key' => 'clave con espacios'])->assertStatus(422);

    expect($response->json('already_used'))->toBeFalse()
        ->and($response->json('message'))->toContain('clave de idempotencia')
        ->and(postCount())->toBe(0);
    confirmPlan(['idempotency_key' => 'clave-valida'])->assertOk();
});

test('un plan no puede pedir más publicaciones que el tope duro aunque la config lo permita', function () {
    config(['social.ai.plan_max_items' => 100]);

    expect(ContentPlanService::maxItems())->toBe(20);
});
