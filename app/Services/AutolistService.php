<?php

namespace App\Services;

use App\Enums\ContentType;
use App\Enums\PostStatus;
use App\Exceptions\PostValidationException;
use App\Models\Autolist;
use App\Models\AutolistItem;
use App\Models\AutolistSlot;
use App\Models\Brand;
use App\Models\MediaAsset;
use App\Models\Post;
use App\Models\PostTemplate;
use App\Models\SocialAccount;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Autolistas (PA-09): una cola de contenido (plantillas de la Biblioteca o texto + media) y franjas semanales
 * (dia ISO + hora en la zona de la marca). `generate()` crea PUBLICACIONES PROGRAMADAS de los proximos dias con
 * `PostService` (misma validacion de media y de cuentas que el compositor) y es idempotente: cada franja se
 * reserva en `autolist_slots` (unica por lista + instante UTC) antes de crear la publicacion.
 */
class AutolistService
{
    public const MAX_PER_USER = 20;

    public const MAX_SLOTS = 28;

    public const MAX_ITEMS = 200;

    /** Margen minimo (min) entre ahora y una franja generada. */
    private const LEAD_MINUTES = 5;

    public function __construct(private readonly PostService $posts) {}

    /**
     * @param  list<array<string, mixed>>  $targets  [{account_id, content_type}]
     * @param  list<array<string, mixed>>  $slots  [{day, time}]
     */
    public function create(User $user, Brand $brand, string $name, array $targets, array $slots, bool $recycle = false): Autolist
    {
        if ($brand->user_id !== $user->id) {
            throw new PostValidationException(['brand' => ['La marca no pertenece al usuario.']]);
        }
        $name = trim($name);
        if ($name === '' || mb_strlen($name) > 120) {
            throw new PostValidationException(['name' => ['El nombre es obligatorio (maximo 120 caracteres).']]);
        }
        if (Autolist::withoutUserScope()->where('user_id', $user->id)->count() >= self::MAX_PER_USER) {
            throw new PostValidationException(['name' => ['Has alcanzado el maximo de autolistas.']]);
        }

        $autolist = new Autolist([
            'name' => $name, 'active' => true, 'recycle' => $recycle,
            'targets' => $this->normalizeTargets($user, $brand, $targets), 'slots' => $this->normalizeSlots($slots), 'cursor' => 0,
        ]);
        $autolist->user_id = $user->id;
        $autolist->brand_id = $brand->id;
        $autolist->save();

        return $autolist;
    }

    /**
     * Anade a la cola una plantilla de la Biblioteca o un texto con media (del usuario).
     *
     * @param  list<int|string>  $mediaIds
     */
    public function addItem(User $user, Autolist $autolist, ?int $templateId, ?string $text, array $mediaIds = []): AutolistItem
    {
        if ($autolist->user_id !== $user->id) {
            throw new PostValidationException(['autolist' => ['La autolista no pertenece al usuario.']]);
        }
        if (AutolistItem::withoutUserScope()->where('autolist_id', $autolist->id)->count() >= self::MAX_ITEMS) {
            throw new PostValidationException(['item' => ['La cola alcanzo el maximo de elementos.']]);
        }

        $media = [];
        if ($templateId !== null) {
            $exists = PostTemplate::withoutUserScope()->where('user_id', $user->id)->where('brand_id', $autolist->brand_id)->whereKey($templateId)->exists();
            if (! $exists) {
                throw new PostValidationException(['post_template_id' => ['La plantilla no existe en esta marca.']]);
            }
        } else {
            $text = $text !== null ? trim($text) : null;
            $ids = array_values(array_unique(array_map('intval', $mediaIds)));
            $owned = $ids === [] ? [] : MediaAsset::withoutUserScope()->where('user_id', $user->id)->whereIn('id', $ids)->pluck('id')->all();
            if (count($owned) !== count($ids)) {
                throw new PostValidationException(['media' => ['Algun archivo no existe.']]);
            }
            $media = $ids;
            if (($text === null || $text === '') && $media === []) {
                throw new PostValidationException(['text' => ['Escribe un texto, elige archivos o una plantilla.']]);
            }
        }

        $item = new AutolistItem(['post_template_id' => $templateId, 'text' => $templateId ? null : $text, 'media' => $media ?: null]);
        $item->user_id = $user->id;
        $item->autolist_id = $autolist->id;
        $item->position = (int) AutolistItem::withoutUserScope()->where('autolist_id', $autolist->id)->max('position') + 1;
        $item->save();

        return $item;
    }

    /**
     * Genera las publicaciones programadas de los proximos `$days` dias. Idempotente.
     *
     * @return array{created: int, skipped: int}
     */
    public function generate(Autolist $autolist, ?int $days = null, ?CarbonImmutable $now = null): array
    {
        $result = ['created' => 0, 'skipped' => 0];
        $days ??= (int) config('social.autolists.horizon_days', 7);
        $now ??= CarbonImmutable::now('UTC');

        $user = User::query()->find($autolist->user_id);
        $brand = Brand::withoutUserScope()->where('user_id', $autolist->user_id)->find($autolist->brand_id);
        if (! $autolist->active || ! $user || ! $brand) {
            return $result;
        }

        foreach ($this->upcomingSlots($autolist, $brand, $days, $now) as $at) {
            $outcome = $this->fillSlot($autolist->id, $user, $brand, $at);
            if ($outcome === null) {
                break; // cola agotada (sin reciclado): no hay mas contenido
            }
            if ($outcome === 'deferred') {
                $result['deferred'] = ($result['deferred'] ?? 0) + 1; // causa temporal: la franja no se registra y se reintenta
            } elseif (isset($result[$outcome])) {
                $result[$outcome]++;
            }
        }

        $autolist->forceFill(['last_generated_at' => $now])->save();

        return $result;
    }

    /**
     * Instantes UTC de las franjas de los proximos `$days` dias (hora local de la marca), ordenados.
     *
     * @return list<CarbonImmutable>
     */
    public function upcomingSlots(Autolist $autolist, Brand $brand, int $days, CarbonImmutable $now): array
    {
        $tz = $brand->timezone ?: 'UTC';
        $start = $now->setTimezone($tz)->startOfDay();
        $min = $now->addMinutes(self::LEAD_MINUTES);
        $limit = $now->addDays($days);
        $out = [];

        for ($i = 0; $i <= $days + 1; $i++) {
            $date = $start->addDays($i);
            foreach ((array) $autolist->slots as $slot) {
                if ((int) $slot['day'] !== $date->dayOfWeekIso) {
                    continue;
                }
                [$h, $m] = array_map('intval', explode(':', (string) $slot['time']));
                $at = $date->setTime($h, $m)->utc();
                if ($at->gte($min) && $at->lte($limit)) {
                    $out[$at->getTimestamp()] = $at;
                }
            }
        }
        ksort($out);

        return array_values($out);
    }

    /** @return 'created'|'skipped'|'existing'|'deferred'|null null = cola agotada */
    private function fillSlot(int $autolistId, User $user, Brand $brand, CarbonImmutable $at): ?string
    {
        $local = $at->setTimezone($brand->timezone ?: 'UTC');
        $localDate = $local->format('Y-m-d');
        $localTime = $local->format('H:i');

        try {
            return DB::transaction(function () use ($autolistId, $user, $brand, $at, $localDate, $localTime) {
                // Bloquea la lista: dos ejecuciones simultaneas no se pisan el cursor.
                $autolist = Autolist::withoutUserScope()->lockForUpdate()->findOrFail($autolistId);

                // La franja se reconoce por su fecha/hora LOCAL: si la marca cambio de zona, la franja ya generada no se duplica.
                $existing = AutolistSlot::withoutUserScope()->where('autolist_id', $autolistId)
                    ->where(fn ($q) => $q->where('slot_at', $at)->orWhere(fn ($q) => $q->where('local_date', $localDate)->where('local_time', $localTime)))
                    ->first();
                if ($existing) {
                    $this->realign($existing, $user, $at, $localDate, $localTime);

                    return 'existing';
                }

                // Una cuenta pendiente de reconectar es una causa TEMPORAL: no se quema la franja ni se consume el elemento;
                // se reintenta en la siguiente generacion mientras la franja siga siendo futura.
                if ($this->hasTemporarilyUnavailableTarget($autolist)) {
                    return 'deferred';
                }

                $item = $this->nextItem($autolist);
                if ($item === null) {
                    return null;
                }

                $slot = new AutolistSlot(['slot_at' => $at, 'local_date' => $localDate, 'local_time' => $localTime]);
                $slot->user_id = $user->id;
                $slot->autolist_id = $autolistId;

                try {
                    $post = $this->posts->create($user, $brand, $this->postData($item, $autolist, $at));
                    $slot->post_id = $post->id;
                    $outcome = 'created';
                } catch (PostValidationException $e) {
                    // Media borrada, cuenta desconectada...: se omite la franja (queda registrada) y la cola avanza.
                    $slot->skipped_reason = mb_substr(collect($e->errors())->flatten()->implode(' '), 0, 255);
                    $outcome = 'skipped';
                }
                $slot->save();

                $item->forceFill(['times_used' => $item->times_used + 1, 'last_used_at' => now()])->save();
                $autolist->forceFill(['cursor' => $autolist->cursor + 1, 'last_position' => $item->position, 'last_item_id' => $item->id])->save();

                return $outcome;
            });
        } catch (QueryException) {
            // Carrera con otra ejecucion que reservo la misma franja (unique): idempotente.
            Log::notice('autolist.slot_race', ['autolist_id' => $autolistId]);

            return 'existing';
        }
    }

    /** Franja ya generada con otro instante (cambio de zona de la marca): la publicacion futura sin publicar se mueve al instante correcto. */
    private function realign(AutolistSlot $slot, User $user, CarbonImmutable $at, string $localDate, string $localTime): void
    {
        if ($slot->slot_at?->equalTo($at) && $slot->local_date === $localDate) {
            return;
        }

        if ($slot->post_id) {
            $post = Post::withoutUserScope()->find($slot->post_id);
            if ($post && $post->status === PostStatus::Scheduled && $post->scheduled_at?->gt(now())) {
                try {
                    $this->posts->reschedule($user, $post, $at);
                } catch (PostValidationException) {
                    return; // en vuelo o ya no editable: se deja como esta
                }
            } elseif ($post) {
                return; // publicada o fallida: no se toca
            }
        }

        $slot->forceFill(['slot_at' => $at, 'local_date' => $localDate, 'local_time' => $localTime])->save();
    }

    private function hasTemporarilyUnavailableTarget(Autolist $autolist): bool
    {
        $ids = collect((array) $autolist->targets)->pluck('account_id')->map(fn ($id) => (int) $id)->all();

        return $ids !== [] && SocialAccount::withoutUserScope()->whereIn('id', $ids)->where('status', SocialAccount::STATUS_RECONNECT)->exists();
    }

    /**
     * Siguiente elemento de la cola tras el ultimo usado (por posicion/id, no por indice: borrar elementos no desplaza la cola).
     * Un elemento sin contenido (su plantilla se borro y no tenia texto/media propios) se elimina de la cola.
     */
    private function nextItem(Autolist $autolist): ?AutolistItem
    {
        for ($guard = 0; $guard < self::MAX_ITEMS + 1; $guard++) {
            $query = AutolistItem::withoutUserScope()->where('autolist_id', $autolist->id)->orderBy('position')->orderBy('id');
            $item = (clone $query)
                ->when($autolist->last_position !== null, fn ($q) => $q->where(fn ($q) => $q->where('position', '>', $autolist->last_position)
                    ->orWhere(fn ($q) => $q->where('position', $autolist->last_position)->where('id', '>', (int) $autolist->last_item_id))))
                ->first();

            if ($item === null) {
                if (! $autolist->recycle && $autolist->last_position !== null) {
                    return null; // sin reciclado la cola termino
                }
                $item = $query->first();
                if ($item === null) {
                    return null;
                }
            }

            if ($this->isEmptyItem($item)) {
                $autolist->forceFill(['last_position' => $item->position, 'last_item_id' => $item->id]);
                $item->delete();

                continue;
            }

            return $item;
        }

        return null;
    }

    private function isEmptyItem(AutolistItem $item): bool
    {
        if ($item->post_template_id !== null) {
            return ! PostTemplate::withoutUserScope()->whereKey($item->post_template_id)->exists();
        }

        return trim((string) $item->text) === '' && (array) $item->media === [];
    }

    /** @return array<string, mixed> */
    private function postData(AutolistItem $item, Autolist $autolist, CarbonImmutable $at): array
    {
        $template = $item->post_template_id
            ? PostTemplate::withoutUserScope()->where('user_id', $autolist->user_id)->with('media')->find($item->post_template_id)
            : null;
        $text = $template ? $template->text : $item->text;
        $media = $template ? $template->media->pluck('id')->map(fn ($id) => (int) $id)->all() : array_map('intval', (array) $item->media);

        return [
            'text' => $text, 'media' => $media, 'schedule' => true, 'scheduled_at' => $at,
            'targets' => array_map(fn ($t) => ['account_id' => $t['account_id'], 'content_type' => $t['content_type']], (array) $autolist->targets),
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $targets
     * @return list<array{account_id: int, content_type: string}>
     */
    private function normalizeTargets(User $user, Brand $brand, array $targets): array
    {
        $out = [];
        $seen = [];
        foreach (array_values($targets) as $i => $t) {
            $account = SocialAccount::withoutUserScope()->where('user_id', $user->id)->where('brand_id', $brand->id)
                ->where('status', '!=', SocialAccount::STATUS_DISCONNECTED)->find((int) ($t['account_id'] ?? 0));
            $type = ContentType::tryFrom((string) ($t['content_type'] ?? ''));
            if (! $account || ! $type || $type->network() !== $account->network || ! $type->isOffered()) {
                throw new PostValidationException(["targets.$i" => ['Destino no valido: elige una cuenta de la marca y un tipo de contenido de su red.']]);
            }
            if (isset($seen[$account->id.'|'.$type->value])) {
                continue;
            }
            $seen[$account->id.'|'.$type->value] = true;
            $out[] = ['account_id' => $account->id, 'content_type' => $type->value];
        }
        if ($out === []) {
            throw new PostValidationException(['targets' => ['Elige al menos una cuenta de destino.']]);
        }

        return $out;
    }

    /**
     * @param  list<array<string, mixed>>  $slots
     * @return list<array{day: int, time: string}>
     */
    private function normalizeSlots(array $slots): array
    {
        $out = [];
        foreach ($slots as $s) {
            $day = (int) ($s['day'] ?? 0);
            $time = (string) ($s['time'] ?? '');
            if ($day < 1 || $day > 7 || preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $time) !== 1) {
                throw new PostValidationException(['slots' => ['Cada franja necesita un dia (lunes a domingo) y una hora HH:MM valida.']]);
            }
            $out["$day $time"] = ['day' => $day, 'time' => $time];
        }
        if ($out === []) {
            throw new PostValidationException(['slots' => ['Anade al menos una franja semanal.']]);
        }
        if (count($out) > self::MAX_SLOTS) {
            throw new PostValidationException(['slots' => ['Maximo '.self::MAX_SLOTS.' franjas por autolista.']]);
        }
        ksort($out);

        return array_values($out);
    }
}
