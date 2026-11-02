<?php

use App\Connectors\Network;
use App\Exceptions\PostValidationException;
use App\Models\Brand;
use App\Models\SocialAccount;
use App\Models\User;
use App\Services\AiAssistant;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

// T-51 · CA-16: tabla de frases y zonas horarias con la respuesta de la IA SIMULADA. Se comprueba lo que el servidor hace con ella: la
// interpreta en la zona de la marca, convierte a UTC y se queda con las redes conectadas. «ahora» viaja en la zona de la marca.

beforeEach(function () {
    Cache::flush();
    Http::preventStrayRequests();
    config(['social.ai' => ['enabled' => true, 'api_key' => 'sk-ant-TEST', 'model' => 'claude-sonnet-5-5', 'max_tokens' => 600, 'timeout' => 30, 'daily_limit' => 100, 'global_daily_limit' => 0]]);
    $this->user = User::factory()->create();
});

/** Marca en `$zone` con Instagram, Facebook y Bluesky conectadas. */
function brandIn(string $zone, User $user): Brand
{
    $brand = Brand::factory()->for($user)->create(['timezone' => $zone]);
    foreach ([Network::Instagram, Network::Facebook, Network::Bluesky] as $network) {
        SocialAccount::factory()->for($user)->create(['brand_id' => $brand->id, 'network' => $network]);
    }

    return $brand;
}

// [zona, instante actual (UTC), frase, respuesta simulada, local esperado, UTC esperado, redes esperadas]
dataset('frases y zonas', [
    'Madrid · el viernes a las 18' => ['Europe/Madrid', '2026-11-02 08:00:00', 'el viernes a las 18', ['2026-11-06T18:00', []], '2026-11-06T18:00', '2026-11-06T17:00:00Z', []],
    'Madrid · mañana por la mañana en Instagram' => ['Europe/Madrid', '2026-11-02 08:00:00', 'mañana por la mañana en Instagram', ['2026-11-03T09:00', ['instagram']], '2026-11-03T09:00', '2026-11-03T08:00:00Z', ['instagram']],
    'Madrid · verano (CEST, UTC+2)' => ['Europe/Madrid', '2027-06-01 10:00:00', 'el 15 de julio a las 20:30 en Facebook', ['2027-07-15T20:30', ['facebook']], '2027-07-15T20:30', '2027-07-15T18:30:00Z', ['facebook']],
    'Madrid · dos redes' => ['Europe/Madrid', '2026-11-02 08:00:00', 'el lunes que viene a las 9 en Instagram y Bluesky', ['2026-11-09T09:00', ['instagram', 'bluesky']], '2026-11-09T09:00', '2026-11-09T08:00:00Z', ['instagram', 'bluesky']],
    'Madrid · fecha con guiones' => ['Europe/Madrid', '2026-11-02 08:00:00', 'el 15-11-2026 a las 18:30', ['2026-11-15T18:30', []], '2026-11-15T18:30', '2026-11-15T17:30:00Z', []],
    'México · esta noche (UTC-6)' => ['America/Mexico_City', '2026-11-02 18:00:00', 'hoy a las 21:00 en Facebook', ['2026-11-02T21:00', ['facebook']], '2026-11-02T21:00', '2026-11-03T03:00:00Z', ['facebook']],
    'México · pasado mañana' => ['America/Mexico_City', '2026-11-02 18:00:00', 'pasado mañana a las 10', ['2026-11-04T10:00', []], '2026-11-04T10:00', '2026-11-04T16:00:00Z', []],
    'México · el próximo sábado' => ['America/Mexico_City', '2026-11-02 18:00:00', 'el próximo sábado a las 12 en Instagram', ['2026-11-07T12:00:00', ['instagram']], '2026-11-07T12:00', '2026-11-07T18:00:00Z', ['instagram']],
    'Tokio · ya es mañana allí (UTC+9)' => ['Asia/Tokyo', '2026-11-02 23:30:00', 'hoy a las 20', ['2026-11-03T20:00', []], '2026-11-03T20:00', '2026-11-03T11:00:00Z', []],
    'Tokio · en dos semanas' => ['Asia/Tokyo', '2026-11-02 23:30:00', 'en dos semanas a las 7 de la mañana en Bluesky', ['2026-11-17T07:00', ['bluesky']], '2026-11-17T07:00', '2026-11-16T22:00:00Z', ['bluesky']],
    'Tokio · fin de año' => ['Asia/Tokyo', '2026-11-02 23:30:00', 'el 31 de diciembre a las 23:59', ['2026-12-31T23:59', []], '2026-12-31T23:59', '2026-12-31T14:59:00Z', []],
]);

test('CA-16: la propuesta se interpreta en la zona de la marca, se convierte a UTC y conserva las redes conectadas', function (string $zone, string $nowUtc, string $phrase, array $reply, string $local, string $utc, array $networks) {
    Carbon::setTestNow($nowUtc);
    $brand = brandIn($zone, $this->user);
    Http::fake(['api.anthropic.com/*' => Http::response(['content' => [['type' => 'text', 'text' => json_encode(['datetime' => $reply[0], 'networks' => $reply[1]])]]])]);

    $p = app(AiAssistant::class)->proposeSchedule($this->user, $brand, $phrase);

    expect($p['scheduled_at'])->toBe($local)->and($p['utc'])->toBe($utc)->and($p['timezone'])->toBe($zone)->and($p['networks'])->toBe($networks)->and($p['ignored_networks'])->toBe([]);

    // «ahora» y la zona viajan a la IA tal como las ve la marca (no en UTC).
    Http::assertSent(function (Request $r) use ($zone, $nowUtc, $phrase) {
        $payload = json_decode($r->data()['messages'][0]['content'], true);

        return $payload['zona_horaria'] === $zone
            && $payload['ahora'] === Carbon::parse($nowUtc, 'UTC')->setTimezone($zone)->format('Y-m-d\TH:i')
            && $payload['frase'] === $phrase;
    });
})->with('frases y zonas');

test('la misma respuesta es otro instante en otra zona: el servidor no la toma como UTC', function () {
    Carbon::setTestNow('2026-11-02 00:00:00');
    Http::fake(['api.anthropic.com/*' => Http::response(['content' => [['type' => 'text', 'text' => json_encode(['datetime' => '2026-11-06T18:00', 'networks' => []])]]])]);

    $utc = fn (string $zone) => app(AiAssistant::class)->proposeSchedule($this->user, brandIn($zone, $this->user), 'el viernes a las 18')['utc'];

    expect($utc('Europe/Madrid'))->toBe('2026-11-06T17:00:00Z')
        ->and($utc('America/Mexico_City'))->toBe('2026-11-07T00:00:00Z')
        ->and($utc('Asia/Tokyo'))->toBe('2026-11-06T09:00:00Z');
});

test('una fecha que en la zona de la marca ya ha pasado se rechaza aunque en UTC sea futura', function () {
    // En Tokio ya son las 08:30 del 3 de noviembre: las 07:00 de ese día pasó, aunque en UTC (2 de noviembre) aún no llegue.
    Carbon::setTestNow('2026-11-02 23:30:00');
    $brand = brandIn('Asia/Tokyo', $this->user);
    Http::fake(['api.anthropic.com/*' => Http::response(['content' => [['type' => 'text', 'text' => json_encode(['datetime' => '2026-11-03T07:00', 'networks' => []])]]])]);

    expect(fn () => app(AiAssistant::class)->proposeSchedule($this->user, $brand, 'hoy a las 7'))->toThrow(PostValidationException::class, 'ya ha pasado');
});
