<?php

use App\Connectors\Network;
use App\Enums\PostStatus;
use App\Jobs\GenerateSeriesOccurrences;
use App\Models\Brand;
use App\Models\Post;
use App\Models\PostSeries;
use App\Models\PostSeriesOccurrence;
use App\Models\SocialAccount;
use App\Models\User;
use App\Services\PostService;
use App\Services\SeriesService;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

// T-19 recurrencia: reglas diaria/semanal/mensual, fin por fecha o numero de veces, idempotencia por (serie, fecha) y zona de la marca.

beforeEach(function () {
    Http::preventStrayRequests();
    Queue::fake();
    Carbon::setTestNow('2026-11-09 12:00:00'); // lunes
    $this->user = User::factory()->create();
    $this->other = User::factory()->create();
    $this->brand = Brand::factory()->for($this->user)->create(['timezone' => 'Europe/Madrid']);
    $this->fb = SocialAccount::factory()->for($this->user)->create(['brand_id' => $this->brand->id, 'network' => Network::Facebook]);
});

function seriesInput(array $over = []): array
{
    return array_replace([
        'brand_id' => test()->brand->id, 'frequency' => 'semanal', 'weekdays' => [1, 3], 'time_local' => '10:00', 'starts_on' => '2026-11-10', 'max_occurrences' => 4,
        'text' => 'Serie', 'media' => [], 'targets' => [['account_id' => test()->fb->id, 'content_type' => 'facebook_text']],
    ], $over);
}

function seriesDates(PostSeries $s): array
{
    return PostSeriesOccurrence::query()->where('series_id', $s->id)->orderBy('occurrence_date')->get()->map(fn ($o) => $o->occurrence_date->format('Y-m-d'))->all();
}

test('semanal con dias elegidos y fin por numero de veces genera exactamente esas ocurrencias a la hora de la marca', function () {
    $res = $this->actingAs($this->user)->postJson(route('workflow.series.store'), seriesInput())->assertCreated();

    expect($res->json('series.generated'))->toBe(4);
    $series = PostSeries::withoutUserScope()->first();
    expect(seriesDates($series))->toBe(['2026-11-11', '2026-11-16', '2026-11-18', '2026-11-23']); // mi 11, lu 16, mi 18, lu 23 (starts_on = 10 martes)
    $post = Post::withoutUserScope()->where('series_id', $series->id)->orderBy('scheduled_at')->first();
    expect($post->status)->toBe(PostStatus::Scheduled)
        ->and($post->scheduled_at->utc()->format('Y-m-d H:i'))->toBe('2026-11-11 09:00') // 10:00 Madrid = 09:00 UTC en noviembre
        ->and($post->scheduledAtLocal()->format('H:i'))->toBe('10:00');
});

test('diaria con fin por fecha y mensual con dia 31 recortado al fin de mes', function () {
    $daily = app(SeriesService::class)->create($this->user, $this->brand, seriesInput(['frequency' => 'diaria', 'weekdays' => null, 'starts_on' => '2026-11-10', 'ends_on' => '2026-11-13', 'max_occurrences' => null]));
    expect(seriesDates($daily))->toBe(['2026-11-10', '2026-11-11', '2026-11-12', '2026-11-13']);

    $far = CarbonImmutable::parse('2027-06-01');
    $monthly = app(SeriesService::class)->create($this->user, $this->brand, seriesInput(['frequency' => 'mensual', 'weekdays' => null, 'starts_on' => '2026-11-30', 'max_occurrences' => 3]));
    expect(app(SeriesService::class)->dates($monthly, $far))->toBe(['2026-11-30', '2026-12-30', '2027-01-30']);
    $m = app(SeriesService::class)->create($this->user, $this->brand, seriesInput(['frequency' => 'mensual', 'weekdays' => null, 'starts_on' => '2026-12-31', 'max_occurrences' => 3]));
    expect(app(SeriesService::class)->dates($m, $far))->toBe(['2026-12-31', '2027-01-31', '2027-02-28']);
});

test('la generacion es idempotente: ejecutar el job dos veces no duplica y la clave unica lo impide aunque se fuerce', function () {
    $series = app(SeriesService::class)->create($this->user, $this->brand, seriesInput(['frequency' => 'diaria', 'weekdays' => null, 'max_occurrences' => 5]));
    $before = Post::withoutUserScope()->where('series_id', $series->id)->count();

    $job = app(GenerateSeriesOccurrences::class);
    $job();
    $job();
    expect(Post::withoutUserScope()->where('series_id', $series->id)->count())->toBe($before)->toBe(5);

    expect(fn () => PostSeriesOccurrence::query()->create(['series_id' => $series->id, 'occurrence_date' => '2026-11-10']))->toThrow(QueryException::class);
    // Una ocurrencia borrada no se regenera (la reserva sigue).
    $first = Post::withoutUserScope()->where('series_id', $series->id)->orderBy('scheduled_at')->first();
    app(PostService::class)->delete($this->user, $first);
    $job();
    expect(Post::withoutUserScope()->where('series_id', $series->id)->count())->toBe(4);
});

test('una serie abierta materializa solo el horizonte y avanza con los dias', function () {
    $series = app(SeriesService::class)->create($this->user, $this->brand, seriesInput(['frequency' => 'diaria', 'weekdays' => null, 'max_occurrences' => null]));
    $n = count(seriesDates($series));
    expect($n)->toBeBetween(40, 47);

    Carbon::setTestNow('2026-11-19 12:00:00');
    app(GenerateSeriesOccurrences::class)();
    expect(count(seriesDates($series)))->toBeGreaterThan($n);
});

test('editar la serie cambia las futuras sin editar y respeta las editadas a mano; cancelar borra las futuras', function () {
    $series = app(SeriesService::class)->create($this->user, $this->brand, seriesInput(['frequency' => 'diaria', 'weekdays' => null, 'max_occurrences' => 4, 'starts_on' => '2026-11-10']));
    $posts = Post::withoutUserScope()->where('series_id', $series->id)->orderBy('scheduled_at')->get();

    // Edicion de UNA ocurrencia por el camino normal: queda como excepcion.
    app(PostService::class)->update($this->user, $posts[1], ['text' => 'Solo esta', 'schedule' => true, 'scheduled_at' => '2026-11-11T10:00', 'media' => [], 'targets' => [['account_id' => $this->fb->id, 'content_type' => 'facebook_text']]]);
    expect($posts[1]->fresh()->series_exception)->toBeTrue();

    $this->actingAs($this->user)->patchJson(route('workflow.series.update', $series->id), ['text' => 'Nuevo texto', 'time_local' => '18:30'])->assertOk();

    $fresh = Post::withoutUserScope()->where('series_id', $series->id)->orderBy('scheduled_at')->get();
    expect($fresh[0]->text)->toBe('Nuevo texto')->and($fresh[0]->scheduledAtLocal()->format('H:i'))->toBe('18:30')
        ->and($fresh[1]->text)->toBe('Solo esta')->and($fresh[1]->scheduledAtLocal()->format('H:i'))->toBe('10:00')
        ->and($fresh[2]->text)->toBe('Nuevo texto');

    $this->actingAs($this->user)->deleteJson(route('workflow.series.cancel', $series->id))->assertOk()->assertJsonPath('series.status', 'cancelada');
    $this->actingAs($this->user)->deleteJson(route('workflow.series.cancel', $series->id))->assertOk(); // idempotente
    expect(Post::withoutUserScope()->where('series_id', $series->id)->count())->toBe(0);
    app(GenerateSeriesOccurrences::class)();
    expect(Post::withoutUserScope()->where('series_id', $series->id)->count())->toBe(0);
    $this->actingAs($this->user)->patchJson(route('workflow.series.update', $series->id), ['text' => 'x'])->assertStatus(422);
});

test('cambiar a menos veces borra las ocurrencias sobrantes y ya publicadas no se tocan', function () {
    $series = app(SeriesService::class)->create($this->user, $this->brand, seriesInput(['frequency' => 'diaria', 'weekdays' => null, 'max_occurrences' => 5]));
    $first = Post::withoutUserScope()->where('series_id', $series->id)->orderBy('scheduled_at')->first();
    $first->targets()->update(['status' => 'publicada']);
    $first->syncStatus();

    $this->actingAs($this->user)->patchJson(route('workflow.series.update', $series->id), ['max_occurrences' => 2])->assertOk();

    expect(Post::withoutUserScope()->where('series_id', $series->id)->count())->toBe(2)->and($first->fresh()->status)->toBe(PostStatus::Published);
});

test('validaciones: frecuencia, dias, hora, fin y contenido; un extrano no ve ni toca la serie', function () {
    $post = fn (array $over) => $this->actingAs($this->user)->postJson(route('workflow.series.store'), seriesInput($over));

    $post(['frequency' => 'anual'])->assertStatus(422);
    $post(['weekdays' => [8]])->assertStatus(422);
    $post(['time_local' => '25:00'])->assertStatus(422);
    $post(['ends_on' => '2026-11-01'])->assertStatus(422);
    $post(['max_occurrences' => 0])->assertStatus(422);
    $post(['targets' => []])->assertStatus(422);
    $post(['targets' => [['account_id' => 99999, 'content_type' => 'facebook_text']]])->assertStatus(422);
    $post(['brand_id' => Brand::factory()->for($this->other)->create()->id])->assertNotFound();

    $id = $post([])->assertCreated()->json('series.id');
    $this->actingAs($this->other)->getJson(route('workflow.series.show', $id))->assertNotFound();
    $this->actingAs($this->other)->patchJson(route('workflow.series.update', $id), ['text' => 'x'])->assertNotFound();
    $this->actingAs($this->other)->deleteJson(route('workflow.series.cancel', $id))->assertNotFound();
    $this->actingAs($this->other)->getJson(route('workflow.series.index'))->assertJsonCount(0, 'series');
});

test('semanal sin dias: usa el dia de la semana del inicio y la ocurrencia pasada no se materializa', function () {
    $s = app(SeriesService::class)->create($this->user, $this->brand, seriesInput(['weekdays' => [], 'starts_on' => '2026-11-02', 'max_occurrences' => 3]));

    expect($s->weekdays)->toBe([1])->and(seriesDates($s))->toBe(['2026-11-16']); // 02 y 09 ya pasaron (cuentan); queda la 3.ª
});
