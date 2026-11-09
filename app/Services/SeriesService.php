<?php

namespace App\Services;

use App\Enums\PostStatus;
use App\Exceptions\PostValidationException;
use App\Http\Requests\PostRequest;
use App\Models\Brand;
use App\Models\Post;
use App\Models\PostSeries;
use App\Models\PostSeriesOccurrence;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Throwable;

/**
 * Recurrencia (T-19). Una serie (diaria, semanal con dias elegidos o mensual; fin por fecha o por numero de veces) materializa sus
 * ocurrencias como posts reales programados en la zona horaria de la marca. La generacion es idempotente: la clave unica
 * (serie, fecha local) de `post_series_occurrences` se reserva en la MISMA transaccion que crea el post, asi que dos ejecuciones del
 * job (o dos hilos) nunca duplican una ocurrencia. Editar la serie actualiza las ocurrencias futuras pendientes que no se hayan editado a
 * mano; editar una ocurrencia (compositor, API o MCP) la marca como excepcion; cancelar la serie borra las futuras pendientes.
 */
class SeriesService
{
    public const FREQUENCIES = ['diaria', 'semanal', 'mensual'];

    public const HORIZON_DAYS = 45;

    public const MAX_OCCURRENCES = 366;

    public const MAX_ACTIVE_SERIES = 50;

    public function __construct(private readonly PostService $posts) {}

    /** @param array<string, mixed> $data */
    public function create(User $user, Brand $brand, array $data): PostSeries
    {
        abort_unless((int) $brand->user_id === (int) $user->id, 404);
        $rule = $this->validatedRule($data, null);
        $payload = $this->validatedPayload($user, $brand, $data, $rule['time_local']);

        if (PostSeries::withoutUserScope()->where('user_id', $user->id)->where('status', PostSeries::ACTIVE)->count() >= self::MAX_ACTIVE_SERIES) {
            throw new PostValidationException(['series' => ['Has alcanzado el maximo de series activas.']]);
        }

        $series = new PostSeries($rule + ['payload' => $payload, 'status' => PostSeries::ACTIVE]);
        $series->user_id = $user->id;
        $series->brand_id = $brand->id;
        $series->save();

        $this->generate($series);

        return $series->fresh();
    }

    /** @param array<string, mixed> $data */
    public function update(User $user, PostSeries $series, array $data): PostSeries
    {
        $this->assertOwns($user, $series);
        if ($series->status !== PostSeries::ACTIVE) {
            throw new PostValidationException(['series' => ['La serie esta cancelada.']]);
        }
        $brand = Brand::withoutUserScope()->findOrFail($series->brand_id);

        $merged = array_replace($this->ruleInput($series), Arr::only($data, ['frequency', 'weekdays', 'time_local', 'starts_on', 'ends_on', 'max_occurrences']));
        if (array_key_exists('weekdays', $data) === false && array_key_exists('frequency', $data) && $data['frequency'] !== 'semanal') {
            $merged['weekdays'] = null;
        }
        $rule = $this->validatedRule($merged, $series);
        $payloadIn = array_replace($series->payload, Arr::only($data, ['text', 'media', 'targets']));
        $payload = $this->validatedPayload($user, $brand, $payloadIn, $rule['time_local']);

        $series->forceFill($rule + ['payload' => $payload])->save();
        $this->reconcile($user, $series->fresh());
        $this->generate($series->fresh());

        return $series->fresh();
    }

    /** Cancela la serie y borra sus ocurrencias futuras pendientes. Idempotente. */
    public function cancel(User $user, PostSeries $series): PostSeries
    {
        $this->assertOwns($user, $series);
        if ($series->status === PostSeries::CANCELLED) {
            return $series;
        }
        $series->forceFill(['status' => PostSeries::CANCELLED, 'cancelled_at' => now()])->save();

        foreach ($this->pendingPosts($series) as $post) {
            try {
                $this->posts->delete($user, $post);
            } catch (Throwable) {
                // Una ocurrencia en vuelo no se toca: ya no se generaran mas.
            }
        }

        return $series->fresh();
    }

    /**
     * Genera las ocurrencias de los proximos dias de UNA serie. Idempotente. Devuelve cuantas ha creado esta pasada.
     */
    public function generate(PostSeries $series, ?CarbonImmutable $now = null): int
    {
        if ($series->status !== PostSeries::ACTIVE) {
            return 0;
        }
        $brand = Brand::withoutUserScope()->find($series->brand_id);
        $user = User::query()->find($series->user_id);
        if ($brand === null || $user === null) {
            return 0;
        }
        $tz = $brand->timezone ?: 'UTC';
        $now ??= CarbonImmutable::now('UTC');
        $horizon = $now->setTimezone($tz)->startOfDay()->addDays(self::HORIZON_DAYS);

        $created = 0;
        foreach ($this->dates($series, $horizon) as $date) {
            $at = CarbonImmutable::parse($date.' '.$series->time_local, $tz);
            if ($at->utc()->lte($now)) {
                continue; // pasada: no se crea (cuenta para el numero de veces, pero no se publica con retraso)
            }
            if (PostSeriesOccurrence::query()->where('series_id', $series->id)->whereDate('occurrence_date', $date)->exists()) {
                continue;
            }

            try {
                DB::transaction(function () use ($series, $brand, $user, $date, $at, &$created): void {
                    // La reserva primero: si otra pasada ya la tiene, el unique aborta la transaccion y no queda ningun post.
                    $slot = PostSeriesOccurrence::query()->create(['series_id' => $series->id, 'occurrence_date' => $date]);
                    $post = $this->posts->create($user, $brand, $this->occurrenceData($series, $at->setTimezone($brand->timezone ?: 'UTC')));
                    Post::withoutUserScope()->whereKey($post->id)->update(['series_id' => $series->id]);
                    $slot->forceFill(['post_id' => $post->id])->save();
                    $created++;
                });
            } catch (QueryException) {
                continue; // otra ejecucion la creo entre medias
            } catch (PostValidationException) {
                continue; // p. ej. cuenta desconectada: se reintenta en la siguiente pasada, sin duplicar
            }
        }

        return $created;
    }

    /** Fechas locales (Y-m-d) de la serie hasta `$until`, respetando fin por fecha y por numero de veces. @return list<string> */
    public function dates(PostSeries $series, CarbonImmutable $until): array
    {
        $start = CarbonImmutable::parse($series->starts_on->format('Y-m-d'), 'UTC')->startOfDay();
        $end = $series->ends_on ? CarbonImmutable::parse($series->ends_on->format('Y-m-d'), 'UTC')->startOfDay() : null;
        $limitDate = CarbonImmutable::parse($until->format('Y-m-d'), 'UTC')->startOfDay();
        $max = $series->max_occurrences;
        $weekdays = array_map('intval', (array) ($series->weekdays ?: [$start->isoWeekday()]));

        $out = [];
        $n = 0;
        for ($i = 0; $i < 4000; $i++) {
            $day = match ($series->frequency) {
                'diaria' => $start->addDays($i),
                'semanal' => $start->addDays($i),
                default => $start->addMonthsNoOverflow($i),
            };
            if ($day->gt($limitDate) || ($end !== null && $day->gt($end))) {
                break;
            }
            if ($series->frequency === 'semanal' && ! in_array($day->isoWeekday(), $weekdays, true)) {
                continue;
            }
            $n++;
            if ($max !== null && $n > $max) {
                break;
            }
            $out[] = $day->format('Y-m-d');
        }

        return $out;
    }

    /** Genera las ocurrencias de todas las series activas (job programado). */
    public function generateAll(): int
    {
        $total = 0;
        PostSeries::withoutUserScope()->where('status', PostSeries::ACTIVE)->orderBy('id')->each(function (PostSeries $s) use (&$total) {
            $total += $this->generate($s);
        });

        return $total;
    }

    /** @return array<string, mixed> */
    public function present(PostSeries $series): array
    {
        $rows = PostSeriesOccurrence::query()->where('series_id', $series->id)->orderBy('occurrence_date')->get();
        $posts = Post::withoutUserScope()->withTrashed()->whereIn('id', $rows->pluck('post_id')->filter())->get()->keyBy('id');

        return [
            'id' => $series->id,
            'brand_id' => $series->brand_id,
            'frequency' => $series->frequency,
            'weekdays' => $series->weekdays,
            'time_local' => $series->time_local,
            'starts_on' => $series->starts_on->format('Y-m-d'),
            'ends_on' => $series->ends_on?->format('Y-m-d'),
            'max_occurrences' => $series->max_occurrences,
            'status' => $series->status,
            'text' => $series->payload['text'] ?? null,
            'targets' => collect($series->payload['targets'] ?? [])->map(fn ($t) => Arr::only($t, ['account_id', 'content_type', 'manual']))->all(),
            'generated' => $rows->count(),
            'occurrences' => $rows->take(60)->map(fn ($r) => [
                'date' => $r->occurrence_date->format('Y-m-d'),
                'post_id' => $r->post_id,
                'status' => $posts->get($r->post_id)?->trashed() ? 'eliminada' : $posts->get($r->post_id)?->status->value,
                'edited' => (bool) $posts->get($r->post_id)?->series_exception,
            ])->values()->all(),
        ];
    }

    // ------------------------------------------------------------------ internals

    private function assertOwns(User $user, PostSeries $series): void
    {
        abort_unless((int) $series->user_id === (int) $user->id, 404);
    }

    /** @return array<string, mixed> */
    private function ruleInput(PostSeries $series): array
    {
        return [
            'frequency' => $series->frequency, 'weekdays' => $series->weekdays, 'time_local' => $series->time_local,
            'starts_on' => $series->starts_on->format('Y-m-d'), 'ends_on' => $series->ends_on?->format('Y-m-d'), 'max_occurrences' => $series->max_occurrences,
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array{frequency: string, weekdays: ?list<int>, time_local: string, starts_on: string, ends_on: ?string, max_occurrences: ?int}
     */
    private function validatedRule(array $data, ?PostSeries $current): array
    {
        $v = Validator::make($data, [
            'frequency' => ['required', 'in:'.implode(',', self::FREQUENCIES)],
            'weekdays' => ['nullable', 'array', 'max:7'],
            'weekdays.*' => ['integer', 'between:1,7'],
            'time_local' => ['required', 'regex:/^([01]\d|2[0-3]):[0-5]\d$/'],
            'starts_on' => ['required', 'date_format:Y-m-d'],
            'ends_on' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:starts_on'],
            'max_occurrences' => ['nullable', 'integer', 'between:1,'.self::MAX_OCCURRENCES],
        ], [
            'frequency.in' => 'La frecuencia debe ser diaria, semanal o mensual.',
            'time_local.regex' => 'La hora debe tener el formato HH:MM.',
            'ends_on.after_or_equal' => 'El fin no puede ser anterior al inicio.',
        ]);
        $clean = $v->validate();

        $weekdays = null;
        if ($clean['frequency'] === 'semanal') {
            $weekdays = collect($clean['weekdays'] ?? [])->map(fn ($d) => (int) $d)->unique()->sort()->values()->all();
            if ($weekdays === []) {
                $weekdays = [CarbonImmutable::parse($clean['starts_on'])->isoWeekday()];
            }
        }

        // Una serie sin fin ni numero de veces es valida (abierta); el horizonte limita lo que se materializa.
        return [
            'frequency' => $clean['frequency'], 'weekdays' => $weekdays, 'time_local' => $clean['time_local'], 'starts_on' => $clean['starts_on'],
            'ends_on' => ($clean['ends_on'] ?? null) ?: null, 'max_occurrences' => isset($clean['max_occurrences']) ? (int) $clean['max_occurrences'] : null,
        ];
    }

    /**
     * Valida el contenido con las mismas reglas de formato y el mismo planificador que un post normal.
     *
     * @param  array<string, mixed>  $data
     * @return array{text: ?string, media: list<int>, targets: list<array<string, mixed>>}
     */
    private function validatedPayload(User $user, Brand $brand, array $data, string $time): array
    {
        $rules = Arr::only((new PostRequest)->rules(), ['text', 'media', 'media.*', 'targets', 'targets.*.account_id', 'targets.*.content_type', 'targets.*.text', 'targets.*.first_comment', 'targets.*.media', 'targets.*.media.*', 'targets.*.media.*.id', 'targets.*.media.*.alt', 'targets.*.media.*.cover_id', 'targets.*.options']);
        $rules['targets.*.manual'] = ['nullable', 'boolean'];
        $rules['targets'] = ['required', 'array', 'min:1', 'max:40'];
        $clean = Validator::make($data, $rules)->validate();

        $payload = [
            'text' => $clean['text'] ?? null,
            'media' => array_values(array_map('intval', $clean['media'] ?? [])),
            'targets' => array_values(array_map(fn ($t) => Arr::only($t, ['account_id', 'content_type', 'text', 'first_comment', 'media', 'options', 'manual']), $clean['targets'])),
        ];

        $probe = CarbonImmutable::now($brand->timezone ?: 'UTC')->addDay()->format('Y-m-d').'T'.$time;
        $errors = $this->posts->validate($user, $brand, $payload + ['schedule' => true, 'scheduled_at' => $probe]);
        if ($errors !== []) {
            throw new PostValidationException($errors);
        }

        return $payload;
    }

    /** @return array<string, mixed> */
    private function occurrenceData(PostSeries $series, CarbonImmutable $localAt): array
    {
        return $series->payload + ['schedule' => true, 'scheduled_at' => $localAt->format('Y-m-d\TH:i')];
    }

    /** @return list<Post> ocurrencias futuras pendientes (borrador/programada) de la serie */
    private function pendingPosts(PostSeries $series, bool $onlyUnedited = false): array
    {
        return Post::withoutUserScope()->where('series_id', $series->id)
            ->when($onlyUnedited, fn ($q) => $q->where('series_exception', false))
            ->whereIn('status', [PostStatus::Draft->value, PostStatus::Scheduled->value])
            ->where('scheduled_at', '>', now())
            ->orderBy('scheduled_at')->get()->all();
    }

    /** Aplica el cambio de la serie a las ocurrencias futuras pendientes que no se editaron a mano. */
    private function reconcile(User $user, PostSeries $series): void
    {
        $brand = Brand::withoutUserScope()->findOrFail($series->brand_id);
        $tz = $brand->timezone ?: 'UTC';
        $valid = array_flip($this->dates($series, CarbonImmutable::now($tz)->startOfDay()->addDays(self::HORIZON_DAYS)));

        foreach ($this->pendingPosts($series, true) as $post) {
            $row = PostSeriesOccurrence::query()->where('series_id', $series->id)->where('post_id', $post->id)->first();
            $date = $row?->occurrence_date->format('Y-m-d');
            try {
                if ($date === null || ! isset($valid[$date])) {
                    $this->posts->delete($user, $post);
                    $row?->delete(); // la fecha deja de ser de la serie: si vuelve a serlo, se podra regenerar
                } else {
                    $at = CarbonImmutable::parse($date.' '.$series->time_local, $tz);
                    $this->posts->update($user, $post, $series->payload + ['schedule' => true, 'scheduled_at' => $at->format('Y-m-d\TH:i')]);
                    Post::withoutUserScope()->whereKey($post->id)->update(['series_exception' => false]);
                }
            } catch (Throwable) {
                // Una ocurrencia que ya no se puede tocar (en vuelo, fecha pasada) se deja como esta.
            }
        }
    }
}
