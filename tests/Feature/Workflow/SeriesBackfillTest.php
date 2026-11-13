<?php

use App\Connectors\Network;
use App\Enums\PostStatus;
use App\Exceptions\PostValidationException;
use App\Models\Brand;
use App\Models\Post;
use App\Models\PostSeries;
use App\Models\PostSeriesOccurrence;
use App\Models\SocialAccount;
use App\Models\User;
use App\Services\SeriesService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

// T-32: «Recuperar fechas perdidas» es opcional y explicito, solo para fechas recientes (social.series.recovery_days) y desde que existe la serie.

beforeEach(function () {
    Http::preventStrayRequests();
    Queue::fake();
    Carbon::setTestNow('2026-11-09 12:00:00');
    config(['social.series.recovery_days' => 2]);
    $this->user = User::factory()->create();
    $this->other = User::factory()->create();
    $this->brand = Brand::factory()->for($this->user)->create(['timezone' => 'UTC']);
    $this->fb = SocialAccount::factory()->for($this->user)->create(['brand_id' => $this->brand->id, 'network' => Network::Facebook]);
    $this->series = app(SeriesService::class)->create($this->user, $this->brand, [
        'brand_id' => $this->brand->id, 'frequency' => 'diaria', 'time_local' => '10:00', 'starts_on' => '2026-11-09', 'max_occurrences' => null,
        'text' => 'Serie', 'media' => [], 'targets' => [['account_id' => $this->fb->id, 'content_type' => 'facebook_text']],
    ]);
    // La serie se creo hace 4 dias y durante ellos no genero nada (cuenta rota): se simula borrando lo generado.
    foreach (Post::withoutUserScope()->where('series_id', $this->series->id)->get() as $p) {
        $p->forceDelete();
    }
    PostSeriesOccurrence::query()->where('series_id', $this->series->id)->delete();
    $this->series->forceFill(['created_at' => '2026-11-05 08:00:00', 'starts_on' => '2026-11-05'])->saveQuietly();
});

test('solo se pueden recuperar las fechas dentro del margen y no las mas antiguas', function () {
    // Lunes 9 a las 12:00: pasaron las 10:00 del 5, 6, 7, 8 y 9. Con margen de 2 dias solo el 8 (26 h) y el 9; el 7 (50 h) no.
    expect(app(SeriesService::class)->recoverableDates($this->series->fresh()))->toBe(['2026-11-08', '2026-11-09']);
});

test('recuperar crea una ocurrencia por fecha, programada en el futuro y escalonada; repetir no duplica', function () {
    expect(app(SeriesService::class)->recover($this->user, $this->series->fresh()))->toBe(2);

    $posts = Post::withoutUserScope()->where('series_id', $this->series->id)->orderBy('scheduled_at')->get();
    expect($posts)->toHaveCount(2)
        ->and($posts->every(fn (Post $p) => $p->status === PostStatus::Scheduled && $p->scheduled_at->isFuture()))->toBeTrue()
        ->and($posts[0]->scheduled_at->lt($posts[1]->scheduled_at))->toBeTrue();

    expect(app(SeriesService::class)->recover($this->user, $this->series->fresh()))->toBe(0)
        ->and(PostSeriesOccurrence::query()->where('series_id', $this->series->id)->count())->toBe(2);
});

test('no se recuperan fechas anteriores a la creacion de la serie', function () {
    $this->series->forceFill(['created_at' => '2026-11-09 09:00:00'])->saveQuietly(); // creada hoy a las 9: el 8 no era suya
    expect(app(SeriesService::class)->recoverableDates($this->series->fresh()))->toBe(['2026-11-09']);
});

test('el generador automatico no recupera por su cuenta', function () {
    app(SeriesService::class)->generate($this->series->fresh());
    expect(PostSeriesOccurrence::query()->where('series_id', $this->series->id)->whereDate('occurrence_date', '<=', '2026-11-09')->count())->toBe(0);
});

test('la API expone el numero recuperable y la accion respeta al propietario y la serie cancelada', function () {
    $this->actingAs($this->user)->getJson(route('workflow.series.show', $this->series->id))->assertOk()->assertJsonPath('series.recoverable', 2);

    $this->actingAs($this->other)->postJson(route('workflow.series.recover', $this->series->id))->assertNotFound();

    $this->actingAs($this->user)->postJson(route('workflow.series.recover', $this->series->id))->assertOk()->assertJsonPath('recovered', 2)->assertJsonPath('series.recoverable', 0);

    $this->series->forceFill(['status' => PostSeries::CANCELLED])->saveQuietly();
    $this->actingAs($this->user)->postJson(route('workflow.series.recover', $this->series->id))->assertStatus(422);
});

test('un margen mayor recupera mas fechas', function () {
    config(['social.series.recovery_days' => 4]);
    expect(app(SeriesService::class)->recoverableDates($this->series->fresh()))->toBe(['2026-11-06', '2026-11-07', '2026-11-08', '2026-11-09']);
});

test('una fecha que solo existe por haber cambiado la regla no es recuperable', function () {
    // La serie (diaria, desde el 5) cambia hoy a las 11:00 su hora: las fechas de ayer con la regla nueva nunca debieron generarse.
    Carbon::setTestNow('2026-11-09 12:30:00');
    app(SeriesService::class)->update($this->user, $this->series->fresh(), ['time_local' => '11:00']);
    $series = $this->series->fresh();
    expect($series->rule_changed_at)->not->toBeNull();

    expect(app(SeriesService::class)->recoverableDates($series))->toBe([]); // ni 8 ni 9 a las 11:00 existian antes del cambio
    PostSeriesOccurrence::query()->where('series_id', $series->id)->whereDate('occurrence_date', '<=', '2026-11-09')->delete();

    // Cambiar solo el texto no es cambiar la regla.
    $before = $series->rule_changed_at;
    Carbon::setTestNow('2026-11-09 12:45:00');
    app(SeriesService::class)->update($this->user, $series, ['text' => 'Nuevo']);
    expect($this->series->fresh()->rule_changed_at->equalTo($before))->toBeTrue();
});

test('dos recuperaciones a la vez no se pisan: la segunda espera al lock de la serie', function () {
    $lock = Cache::lock('series-recover:'.$this->series->id, 30);
    expect($lock->get())->toBeTrue();

    expect(fn () => app(SeriesService::class)->recover($this->user, $this->series->fresh()))->toThrow(PostValidationException::class);
    expect(PostSeriesOccurrence::query()->where('series_id', $this->series->id)->count())->toBe(0);

    $lock->release();
    expect(app(SeriesService::class)->recover($this->user, $this->series->fresh()))->toBe(2);
});

test('el listado de series presenta con un numero constante de consultas', function () {
    foreach (range(1, 6) as $i) {
        $extra = $this->series->replicate();
        $extra->save();
    }
    $count = function (int $expected) {
        DB::flushQueryLog();
        DB::enableQueryLog();
        $this->actingAs($this->user)->getJson(route('workflow.series.index'))->assertOk()->assertJsonCount($expected, 'series');

        return count(DB::getQueryLog());
    };
    $with7 = $count(7);
    PostSeries::withoutUserScope()->where('id', '>', $this->series->id)->take(5)->get()->each->delete();
    $with2 = $count(2);

    expect($with7)->toBeLessThanOrEqual($with2 + 1); // 5 series mas no anaden consultas (antes: varias por serie y por fecha)
});
