<?php

use App\Connectors\Data\PublishMedia;
use App\Connectors\Data\PublishRequest;
use App\Connectors\Exceptions\TransientPublishException;
use App\Connectors\Meta\Instagram\InstagramPublisher;
use App\Connectors\Network;
use App\Enums\ContentType;
use App\Models\Brand;
use App\Models\SocialAccount;
use App\Models\User;
use Illuminate\Support\Carbon;
use Tests\Support\FakeGraph;

// Revision Fase 3 intento 2: N5 (presupuesto), N7 (story por contenedor), N8 (PUBLISHED sin texto = publicada).

const I2B_IG = '17841400000000012';

beforeEach(function () {
    Carbon::setTestNow('2026-11-01 12:00:00');
    config(['social.meta.graph_version' => 'v23.0', 'social.http_timeout' => 15, 'publishing.step_budget_seconds' => 85]);
    $user = User::factory()->create();
    $brand = Brand::factory()->for($user)->create();
    $this->account = SocialAccount::factory()->for($user)->create(['brand_id' => $brand->id, 'network' => Network::Instagram, 'external_id' => I2B_IG, 'access_token' => 'tok']);
    $this->graph = new FakeGraph;
    $this->ig = new InstagramPublisher;
    $this->graph->on('GET', '#/content_publishing_limit$#', ['data' => [['quota_usage' => 1, 'config' => ['quota_total' => 25]]]])
        ->on('GET', '#^1\d+$#', ['permalink' => 'https://www.instagram.com/p/ABC/']);
});

function i2bRequest(ContentType $type, ?string $text, ?string $reference = null): PublishRequest
{
    return new PublishRequest(77, $type, $text, [new PublishMedia(1, 'image', 'image/jpeg', 'https://cdn.test/a.jpg', 1080, 1080)], [], $reference, now()->subMinutes(5));
}

test('N5: el estado de un contenedor solo se consulta si caben tambien media_publish y el enlace en el presupuesto', function () {
    config(['publishing.step_budget_seconds' => 40]); // estado + publish + permalink = 45 s en el peor caso
    $this->graph->on('POST', '#^'.I2B_IG.'/media$#', ['id' => 'C1'])->on('GET', '#^C1$#', ['status_code' => 'FINISHED'])->on('POST', '#/media_publish$#', ['id' => '19100']);

    $result = $this->ig->publish($this->account, i2bRequest(ContentType::InstagramImage, 'Hola'));

    expect($result->isProcessing())->toBeTrue()->and($result->reference)->toBe('C1')
        ->and($this->graph->callsTo('POST', '#/media_publish$#'))->toBeEmpty()->and($this->graph->callsTo('GET', '#^C1$#'))->toBeEmpty();
});

test('N5: con el presupuesto normal se consulta, se publica y se obtiene el enlace', function () {
    $this->graph->on('POST', '#^'.I2B_IG.'/media$#', ['id' => 'C1'])->on('GET', '#^C1$#', ['status_code' => 'FINISHED'])->on('POST', '#/media_publish$#', ['id' => '19100']);

    $result = $this->ig->publish($this->account, i2bRequest(ContentType::InstagramImage, 'Hola'));

    expect($result->isPublished())->toBeTrue()->and($result->externalId)->toBe('19100')->and($result->url)->toContain('instagram.com');
});

test('N5: findPublished y publish comparten el MISMO presupuesto de la peticion y findPublished no consulta sin presupuesto', function () {
    $request = i2bRequest(ContentType::InstagramImage, 'Hola', 'C9');
    expect($request->budget())->toBe($request->budget());

    config(['publishing.step_budget_seconds' => 10]); // tras la 1.a consulta no cabe otra llamada de 15 s
    $this->graph->on('GET', '#^C9$#', ['status_code' => 'PUBLISHED'])->on('GET', '#^'.I2B_IG.'/media$#', ['data' => []]);
    $request = i2bRequest(ContentType::InstagramImage, 'Hola', 'C9');

    $found = $this->ig->findPublished($this->account, $request);

    expect($found->isProcessing())->toBeTrue()->and($found->reference)->toBe('C9') // se reintenta en el siguiente sondeo, sin publicar a ciegas
        ->and($this->graph->callsTo('GET', '#/media$#'))->toBeEmpty();
});

test('N7: una story PUBLISHED se resuelve por el ID de su contenedor, no por la mas reciente', function () {
    $this->graph->on('GET', '#^S1$#', ['status_code' => 'PUBLISHED'])->on('GET', '#^'.I2B_IG.'/stories$#', ['data' => [
        ['id' => 'S9', 'permalink' => 'https://www.instagram.com/stories/x/S9', 'timestamp' => '2026-11-01T11:59:59+0000'], // mas reciente, de OTRA publicacion
        ['id' => 'S1', 'permalink' => 'https://www.instagram.com/stories/x/S1', 'timestamp' => '2026-11-01T11:57:00+0000'],
    ]]);

    $found = $this->ig->findPublished($this->account, i2bRequest(ContentType::InstagramStory, null, 'S1'));

    expect($found->isPublished())->toBeTrue()->and($found->externalId)->toBe('S1')->and($found->url)->toContain('S1');
});

test('N7: con varias historias candidatas y ninguna con el ID del contenedor no se adivina: se queda con el contenedor y una nota', function () {
    $this->graph->on('GET', '#^S1$#', ['status_code' => 'PUBLISHED'])->on('GET', '#^'.I2B_IG.'/stories$#', ['data' => [
        ['id' => 'S8', 'permalink' => 'a', 'timestamp' => '2026-11-01T11:59:59+0000'],
        ['id' => 'S9', 'permalink' => 'b', 'timestamp' => '2026-11-01T11:58:00+0000'],
    ]]);

    $found = $this->ig->findPublished($this->account, i2bRequest(ContentType::InstagramStory, null, 'S1'));

    expect($found->isPublished())->toBeTrue()->and($found->externalId)->toBe('S1')->and($found->url)->toBeNull()->and($found->note)->toContain('enlace');
});

test('N7: con una unica historia candidata y sin coincidencia de ID se usa esa', function () {
    $this->graph->on('GET', '#^S1$#', ['status_code' => 'PUBLISHED'])->on('GET', '#^'.I2B_IG.'/stories$#', ['data' => [
        ['id' => 'S8', 'permalink' => 'https://www.instagram.com/stories/x/S8', 'timestamp' => '2026-11-01T11:59:59+0000'],
        ['id' => 'S0', 'permalink' => 'old', 'timestamp' => '2026-10-31T08:00:00+0000'],
    ]]);

    expect($this->ig->findPublished($this->account, i2bRequest(ContentType::InstagramStory, null, 'S1'))->externalId)->toBe('S8');
});

test('N8: un contenedor PUBLISHED de imagen sin texto se da por publicado (no fallido) al sondear', function () {
    $this->graph->on('GET', '#^C5$#', ['status_code' => 'PUBLISHED']);

    $result = $this->ig->poll($this->account, i2bRequest(ContentType::InstagramImage, null, 'C5'));

    expect($result->isPublished())->toBeTrue()->and($result->isFailed())->toBeFalse()->and($result->externalId)->toBe('C5')
        ->and($result->note)->not->toBeNull()->and($this->graph->callsTo('POST', '#.#'))->toBeEmpty();
});

test('N8: un contenedor PUBLISHED con texto no localizable en la media reciente tambien se da por publicado', function () {
    $this->graph->on('GET', '#^C6$#', ['status_code' => 'PUBLISHED'])->on('GET', '#^'.I2B_IG.'/media$#', ['data' => []]);

    $result = $this->ig->poll($this->account, i2bRequest(ContentType::InstagramReel, 'Con texto', 'C6'));

    expect($result->isPublished())->toBeTrue()->and($result->externalId)->toBe('C6');
});

test('N5: findPublished sin contenedor y con el presupuesto agotado no publica a ciegas: error transitorio (el reintento vuelve a preguntar)', function () {
    config(['publishing.step_budget_seconds' => 0]);
    $budget = (new PublishRequest(1, ContentType::InstagramImage, 'x', [], []))->budget();
    $budget->spent();
    $request = new PublishRequest(77, ContentType::InstagramImage, 'Hola', [], [], null, now()->subMinutes(5), [], null, $budget);

    expect(fn () => $this->ig->findPublished($this->account, $request))->toThrow(TransientPublishException::class);
});
