<?php

namespace App\Services;

use App\Enums\ContentType;
use App\Exceptions\PostValidationException;
use App\Models\Brand;
use App\Models\SocialAccount;
use App\Models\User;
use App\Support\LocalDateTime;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;

/**
 * Plan de contenido en lenguaje natural (T-93, F-22): a partir de un briefing, la IA PROPONE un lote de publicaciones de solo texto con
 * fecha; el usuario las revisa, edita y desmarca, y solo al confirmar se crean (borradores o programadas) en UNA transaccion.
 *
 * Reparto de confianza:
 *  - la respuesta del modelo es un dato NO confiable: esquema estricto (`items`, cada uno con exactamente `datetime`, `accounts` y `text`),
 *    tope de elementos, fecha futura y unica en la zona de la marca, solo cuentas ofrecidas (de ESTA marca, activas y con un tipo de solo
 *    texto) y el tipo de contenido lo decide el servidor, nunca el modelo. Lo que no cumple se descarta y se avisa; un texto que no cabe en
 *    una red NO se recorta: queda marcado para que el usuario lo edite;
 *  - al confirmar tampoco se confia en el cliente: se revalida cada elemento con las mismas reglas y `PostService::create` (la capa unica
 *    de creacion, con la validacion por red de `PostPlanner`/`MediaValidator`). Un solo elemento invalido cancela el lote entero.
 *
 * Una publicacion por elemento, con el mismo texto en todas sus cuentas. Las redes que exigen media (Instagram, TikTok, YouTube, Pinterest)
 * no entran: un plan de texto no puede producir una publicacion valida para ellas y se muestran como excluidas con su motivo.
 * `BatchService` opera sobre publicaciones que ya existen; este servicio respeta su tope (`BatchService::MAX_ITEMS`) y tiene su
 * propio limitador por usuario (`ServiceThrottle`, bucket `content-plan`, no compartido con el lote).
 */
class ContentPlanService
{
    /** Longitud maxima de un texto del plan (caracteres): por encima no es un texto de red social sino un abuso. */
    public const MAX_TEXT = 5000;

    /** Tope duro de publicaciones por plan: por encima, el limite de tokens de salida obligaria a pedir textos demasiado cortos. */
    public const MAX_PLAN_ITEMS = 20;

    public const MODES = ['draft', 'schedule'];

    public function __construct(
        private readonly AiAssistant $ai,
        private readonly PostService $posts,
        private readonly MediaValidator $validator,
        private readonly IdempotencyService $idempotency,
    ) {}

    /** Maximo de publicaciones de un plan (config, acotado por el tope de un lote). */
    public static function maxItems(): int
    {
        return max(1, min(self::MAX_PLAN_ITEMS, BatchService::MAX_ITEMS, (int) config('social.ai.plan_max_items', 10)));
    }

    /**
     * Cuentas de la marca partidas en las que admiten un plan de solo texto y las que no.
     *
     * @return array{accounts: list<array{account: SocialAccount, type: ContentType, limit: array<string, mixed>}>, excluded: list<array{account: SocialAccount, reason: string}>}
     */
    public function accounts(User $user, Brand $brand): array
    {
        $accounts = [];
        $excluded = [];
        /** @var Collection<int, SocialAccount> $all */
        $all = SocialAccount::ownedBy($user->id)->where('brand_id', $brand->id)
            ->where('status', '!=', SocialAccount::STATUS_DISCONNECTED)->orderBy('id')->get();

        foreach ($all as $account) {
            $type = $this->textType($account);
            if (! $account->isActive()) {
                $excluded[] = ['account' => $account, 'reason' => 'Necesita reconectarse antes de programar.'];
            } elseif ($type === null) {
                $excluded[] = ['account' => $account, 'reason' => $account->network->label().' necesita una imagen o un vídeo: un plan de solo texto no puede crear publicaciones para esta cuenta.'];
            } else {
                $accounts[] = ['account' => $account, 'type' => $type, 'limit' => Arr::only($this->validator->spec($type, null, $account)['text'], ['max', 'count', 'url_length', 'max_bytes', 'max_hashtags'])];
            }
        }

        return ['accounts' => $accounts, 'excluded' => $excluded];
    }

    /** Primer tipo de contenido de la red que se publica sin media (con sus limites propios si los hay), o null si todos la exigen. */
    private function textType(SocialAccount $account): ?ContentType
    {
        foreach (ContentType::forNetwork($account->network) as $type) {
            if ($type->isOffered() && $this->validator->spec($type, null, $account)['media']['min'] === 0) {
                return $type;
            }
        }

        return null;
    }

    /**
     * Pide al asistente el lote y lo normaliza. NO crea nada.
     *
     * @return array{items: list<array{key: string, scheduled_at: string, label: string, account_ids: list<int>, text: string, problems: list<array{account_id: int, message: string}>}>, notices: list<string>}
     *
     * @throws PostValidationException marca sin cuentas de solo texto, briefing no valido, IA desactivada, limite diario, fallo del servicio o respuesta sin elementos validos
     */
    public function propose(User $user, Brand $brand, string $briefing): array
    {
        $offered = $this->accounts($user, $brand)['accounts'];
        if ($offered === []) {
            throw new PostValidationException(['briefing' => ['Esta marca no tiene cuentas activas que admitan publicaciones de solo texto (Bluesky, Mastodon, Threads, LinkedIn, Facebook o Telegram).']]);
        }

        $max = self::maxItems();
        $targets = array_map(fn (array $o) => ['account_id' => $o['account']->id, 'content_type' => $o['type'], 'account' => $o['account']], $offered);
        $reply = $this->ai->planContent($user, $brand, $briefing, $targets, $max);

        $raw = $this->decode($reply);
        if ($raw === null) {
            throw new PostValidationException(['briefing' => ['La respuesta del asistente llegó incompleta o no es válida. Inténtalo de nuevo.']]);
        }

        $zone = $brand->timezone ?: 'UTC';
        $byId = collect($offered)->keyBy(fn (array $o) => (string) $o['account']->id);
        $items = [];
        $discarded = 0;
        $foreign = 0;
        $seen = 0;
        foreach ($raw as $candidate) {
            if (count($items) >= $max) {
                break;
            }
            $seen++;
            $item = $this->normalizeItem($candidate, $byId, $zone, $foreign);
            $item === null ? $discarded++ : $items[] = $item;
        }

        if ($items === []) {
            throw new PostValidationException(['briefing' => ['No he podido proponer publicaciones válidas con ese briefing. Prueba a concretar el tema, las fechas y las redes.']]);
        }

        $notices = [];
        $asked = $this->ai->planTextMax($targets, $max);
        if ($asked < min(array_map(fn (array $t) => (int) $this->validator->spec($t['content_type'], null, $t['account'])['text']['max'], $targets))) {
            $notices[] = "Con $max publicaciones por plan el asistente escribe textos de hasta $asked caracteres: pídele menos publicaciones si necesitas textos más largos.";
        }
        if ($seen < count($raw)) {
            $notices[] = "El asistente propuso más publicaciones que el máximo ($max): se muestran las primeras $max.";
        }
        if ($discarded > 0) {
            $notices[] = $discarded === 1
                ? 'Se ha descartado 1 propuesta del asistente por tener una fecha no válida o pasada, un formato incorrecto o ninguna cuenta de esta marca.'
                : "Se han descartado $discarded propuestas del asistente por tener una fecha no válida o pasada, un formato incorrecto o ninguna cuenta de esta marca.";
        }
        if ($foreign > 0) {
            $notices[] = 'El asistente nombró cuentas que no están disponibles para este plan: se han ignorado.';
        }

        return ['items' => array_map(fn (array $item, int $i) => ['key' => 'p'.($i + 1)] + $item, $items, array_keys($items)), 'notices' => $notices];
    }

    /**
     * Crea el lote confirmado en UNA transaccion e idempotente. Revalida TODO en el servidor: nada de lo que vuelve del cliente se da por bueno.
     *
     * @param  array{mode: string, items: list<array{scheduled_at: string, account_ids: list<int>, text: string}>}  $input  ya validado en forma por el controlador
     * @return array{created: int, mode: string, post_ids: list<int>, replayed: bool}
     *
     * @throws PostValidationException algun elemento no es valido (el lote entero se cancela; los errores van por `items.N`)
     */
    public function confirm(User $user, Brand $brand, string $idempotencyKey, array $input): array
    {
        ServiceThrottle::hit($user, 'content-plan');

        $run = $this->idempotency->run($user, 'content-plan', $idempotencyKey, ['brand_id' => $brand->id] + $input, function () use ($user, $brand, $input): array {
            $offered = collect($this->accounts($user, $brand)['accounts'])->keyBy(fn (array $o) => $o['account']->id);
            $zone = $brand->timezone ?: 'UTC';
            $errors = [];
            $postIds = [];

            DB::transaction(function () use ($user, $brand, $input, $offered, $zone, &$errors, &$postIds) {
                foreach (array_values($input['items']) as $i => $item) {
                    $problems = [];
                    $at = $this->parseDate((string) $item['scheduled_at'], $zone, $problems);
                    $targets = [];
                    foreach (array_values(array_unique(array_map('intval', $item['account_ids']))) as $id) {
                        if (! $offered->has($id)) {
                            $problems[] = "La cuenta $id no está disponible en esta marca para un plan de solo texto.";

                            continue;
                        }
                        $targets[] = ['account_id' => $id, 'content_type' => $offered[$id]['type']->value];
                    }
                    if ($targets === [] && $problems === []) {
                        $problems[] = 'Elige al menos una cuenta.';
                    }
                    $text = trim((string) ($item['text'] ?? ''));
                    if ($text === '') {
                        $problems[] = 'Escribe el texto de la publicación.';
                    } elseif (mb_strlen($text) > self::MAX_TEXT) {
                        $problems[] = 'El texto admite como máximo '.self::MAX_TEXT.' caracteres.';
                    }

                    if ($problems === []) {
                        try {
                            $postIds[] = $this->posts->create($user, $brand, [
                                'text' => $text,
                                'scheduled_at' => $at,
                                'schedule' => $input['mode'] === 'schedule',
                                'targets' => $targets,
                            ])->id;
                        } catch (PostValidationException $e) {
                            $problems = array_values(array_unique(collect($e->errors())->flatten()->all()));
                        }
                    }
                    if ($problems !== []) {
                        $errors["items.$i"] = $problems;
                    }
                }

                // Un solo elemento invalido cancela el lote: ninguna publicacion queda a medias.
                if ($errors !== []) {
                    throw new PostValidationException($errors);
                }
            });

            return ['created' => count($postIds), 'mode' => $input['mode'], 'post_ids' => $postIds];
        }, atomic: true);

        return $run['result'] + ['replayed' => $run['replayed']];
    }

    /**
     * Textos que no caben en cada cuenta (limite de su red y de su cuenta, p. ej. Mastodon): se marcan, nunca se recortan.
     *
     * @param  list<int>  $accountIds
     * @param  \Illuminate\Support\Collection<string, array{account: SocialAccount, type: ContentType}>  $byId
     * @return list<array{account_id: int, message: string}>
     */
    private function problems(string $text, array $accountIds, $byId): array
    {
        $problems = [];
        foreach ($accountIds as $id) {
            $o = $byId[(string) $id];
            if (($errors = $this->validator->textErrors($o['type'], $text, $o['account'])) !== []) {
                $problems[] = ['account_id' => $id, 'message' => implode(' ', $errors)];
            }
        }

        return $problems;
    }

    /**
     * @param  \Illuminate\Support\Collection<string, array{account: SocialAccount, type: ContentType}>  $byId
     * @return array{scheduled_at: string, label: string, account_ids: list<int>, text: string, problems: list<array{account_id: int, message: string}>}|null null = descartado
     */
    private function normalizeItem(mixed $candidate, $byId, string $zone, int &$foreign): ?array
    {
        // Esquema estricto: exactamente `datetime`, `accounts` y `text`. Una clave de mas (p. ej. `content_type`) descarta el elemento.
        if (! is_array($candidate) || array_is_list($candidate) || count($candidate) !== 3 || ! is_string($candidate['datetime'] ?? null) || ! is_array($candidate['accounts'] ?? null)
            || ! array_is_list($candidate['accounts']) || ! is_string($candidate['text'] ?? null)) {
            return null;
        }

        $ignored = [];
        $at = $this->parseDate($candidate['datetime'], $zone, $ignored);
        if ($at === null) {
            return null;
        }

        $ids = [];
        foreach ($candidate['accounts'] as $id) {
            if ((is_string($id) || is_int($id)) && $byId->has((string) $id)) {
                $ids[(int) $id] = (int) $id;
            } else {
                $foreign++;
            }
        }
        $text = $this->cleanText($candidate['text']);
        if ($ids === [] || $text === '' || mb_strlen($text) > self::MAX_TEXT) {
            return null;
        }
        $ids = array_values($ids);

        return [
            'scheduled_at' => $at->format('Y-m-d\TH:i'),
            'label' => $at->locale('es')->isoFormat('dddd, D [de] MMMM [de] YYYY, HH:mm'),
            'account_ids' => $ids,
            'text' => $text,
            'problems' => $this->problems($text, $ids, $byId),
        ];
    }

    /**
     * Fecha local `YYYY-MM-DDTHH:MM` de la marca: debe existir una sola vez (el cambio de hora la hace ambigua o inexistente), ser futura y
     * quedar como mucho a `social.ai.schedule_max_days`. Devuelve null y añade el motivo a `$problems` si no.
     *
     * @param  list<string>  $problems
     */
    private function parseDate(string $value, string $zone, array &$problems): ?CarbonImmutable
    {
        $at = preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}$/', $value) === 1 ? LocalDateTime::parseUnique($value, $zone) : null;
        if ($at === null) {
            $problems[] = 'La fecha no es válida (formato AAAA-MM-DDTHH:MM, y la hora debe existir una sola vez en la zona de la marca).';
        } elseif ($at->lte(now())) {
            $problems[] = 'La fecha de publicación debe ser futura.';
            $at = null;
        } elseif ($at->gt(now()->addDays((int) config('social.ai.schedule_max_days', 365)))) {
            $problems[] = 'La fecha de publicación queda a más de un año: elige una más cercana.';
            $at = null;
        }

        return $at;
    }

    /**
     * Quita los controles (salvo el salto de linea), los invisibles que ocultan texto (espacio de ancho cero, unión de palabras, BOM) y los
     * overrides bidireccionales. Conserva la unión de ancho cero (ZWJ), los selectores de variación y las etiquetas: sin ellos se rompen los
     * emojis compuestos (👨‍👩‍👧) y las banderas por subdivisión (Escocia).
     */
    private function cleanText(string $text): string
    {
        $text = str_replace(["\r\n", "\r"], "\n", $text);

        return trim((string) preg_replace('/[\x00-\x09\x0B-\x1F\x7F-\x9F\x{200B}\x{2060}\x{FEFF}\x{202A}-\x{202E}\x{2066}-\x{2069}]/u', '', $text));
    }

    /**
     * Los elementos de la respuesta (`{"items": [...]}`, con o sin bloque de codigo) o null si no cumple el esquema.
     *
     * @return list<mixed>|null
     */
    private function decode(string $reply): ?array
    {
        $json = preg_replace('/^```(?:json)?\s*(.*?)\s*```$/s', '$1', trim($reply)) ?? $reply;
        $data = json_decode($json, true);

        return is_array($data) && array_keys($data) === ['items'] && is_array($data['items']) && array_is_list($data['items']) ? $data['items'] : null;
    }
}
