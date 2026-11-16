<?php

namespace App\Services\Posts;

use App\Connectors\Google\YouTubePublisher;
use App\Connectors\LinkedIn\LinkedInPostOptions;
use App\Connectors\LinkedIn\LinkedInPublisher;
use App\Connectors\Meta\Facebook\FacebookPostOptions;
use App\Connectors\Meta\Instagram\InstagramAudio;
use App\Connectors\Meta\Instagram\InstagramPostOptions;
use App\Connectors\Network;
use App\Connectors\Pinterest\PinterestPostOptions;
use App\Connectors\TikTok\TikTokPublisher;
use App\Enums\ContentType;
use App\Enums\PostStatus;
use App\Exceptions\PostValidationException;
use App\Models\Brand;
use App\Models\Campaign;
use App\Models\MediaAsset;
use App\Models\Post;
use App\Models\PostTarget;
use App\Models\SocialAccount;
use App\Models\User;
use App\Services\FirstCommentService;
use App\Services\MediaValidator;
use App\Support\TargetMedia;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Collection;
use Throwable;

/**
 * Planificacion de una publicacion: valida la entrada de `PostService::create/update` y la convierte en un plan normalizado (sin tocar la
 * BD), y sincroniza media y destinos del post con ese plan. Las reglas de opciones por red, la media propia de cada destino y la
 * campaña viven aqui, las mismas para la web, la API y el MCP.
 */
class PostPlanner
{
    public function __construct(private readonly MediaValidator $validator, private readonly PostTransitions $transitions) {}

    /**
     * Datos de `create/update` que reconstruyen lo que ya hay en el post (texto, media y destinos con sus opciones).
     *
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    public function dataFromPost(Post $post, array $overrides): array
    {
        return $overrides + [
            'text' => $post->text,
            'media' => $post->media()->pluck('media_assets.id')->all(),
            'targets' => $post->targets()->get()->map(fn (PostTarget $t) => [
                'account_id' => $t->social_account_id, 'content_type' => $t->content_type->value, 'options' => $t->options ?? [], 'text' => $t->text, 'first_comment' => $t->first_comment, 'media' => $t->media, 'manual' => $t->manual,
            ])->all(),
        ];
    }

    /** @return array<string, list<string>> */
    public function campaignErrors(User $user, Brand $brand, ?int $campaignId, ?int $currentCampaignId): array
    {
        if ($campaignId === null) {
            return [];
        }

        $campaign = Campaign::withoutUserScope()->where('user_id', $user->id)->where('brand_id', $brand->id)->find($campaignId);
        if ($campaign === null) {
            return ['campaign_id' => ['La campaña no existe en esta marca.']];
        }
        if ($campaign->isArchived() && $campaignId !== $currentCampaignId) {
            return ['campaign_id' => ['La campaña está archivada: restáurala antes de asignarle publicaciones.']];
        }

        return [];
    }

    public static function normalizeNotes(mixed $notes): ?string
    {
        $notes = is_string($notes) ? str_replace(["\r\n", "\r"], "\n", $notes) : null;

        return $notes === null || trim($notes) === '' ? null : $notes;
    }

    /** Opciones propias de YouTube (C-04): solo se aceptan en destinos de YouTube. */
    private const YOUTUBE_OPTIONS = ['category_id', 'tags', 'made_for_kids', 'notify_subscribers', 'contains_synthetic_media'];

    /** Opciones de TikTok (C-04) que actuan solo en publicacion directa (la cuenta con `meta.direct_post`). */
    private const TIKTOK_DIRECT_OPTIONS = ['disable_duet', 'disable_stitch', 'brand_organic_toggle', 'brand_content_toggle', 'is_aigc', 'auto_add_music'];

    /** Opciones booleanas: la regla `boolean` de la web y el MCP admite `1`, `0`, `"1"` y `"0"` sin convertirlas. */
    private const BOOLEAN_OPTIONS = [
        'share_to_feed', 'shorts_hashtag', 'made_for_kids', 'notify_subscribers', 'contains_synthetic_media',
        'disable_comment', 'disable_duet', 'disable_stitch', 'brand_organic_toggle', 'brand_content_toggle', 'is_aigc', 'auto_add_music',
        'linkedin_disable_reshare',
    ];

    /**
     * Convierte a `true`/`false` reales las opciones booleanas ANTES de validar y de guardar: asi `optionErrors` no confunde `0` o
     * `"0"` con «pedido» y los publishers (que comparan con `===`) nunca ignoran un valor equivalente (`made_for_kids: 1` es true).
     * Un valor que no es un booleano reconocible se deja tal cual (la validacion de forma ya lo rechaza antes).
     *
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
    public static function normalizeOptions(array $options): array
    {
        $options = LinkedInPostOptions::normalize(InstagramPostOptions::normalize(FacebookPostOptions::normalize($options)));
        foreach (self::BOOLEAN_OPTIONS as $key) {
            if (array_key_exists($key, $options) && $options[$key] !== null) {
                $options[$key] = filter_var($options[$key], FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) ?? $options[$key];
            }
        }

        return $options;
    }

    /**
     * Reglas por cuenta de las opciones de C-04, las mismas para la web y el MCP: una opcion que la red o la cuenta no admite
     * es un error del destino (no se descarta en silencio). Un valor «vacio» (null, false o lista vacia) no cuenta como pedido.
     *
     * @param  array<string, mixed>  $options
     * @return list<string>
     */
    private function optionErrors(ContentType $type, SocialAccount $account, array $options): array
    {
        $asked = fn (string $k) => array_key_exists($k, $options) && ! in_array($options[$k], [null, false, [], ''], true);
        $errors = array_merge(InstagramAudio::selectionErrors($options['instagram_audio'] ?? null, $type, $account), InstagramPostOptions::errors($options, $type), FacebookPostOptions::errors($options, $type), LinkedInPostOptions::errors($options, $type, $account), PinterestPostOptions::errors($options, $type));

        if ($type->network() !== Network::YouTube) {
            foreach (self::YOUTUBE_OPTIONS as $k) {
                if ($asked($k)) {
                    $errors[] = "La opcion $k solo existe en YouTube.";
                }
            }
        }

        if ($type->network() !== Network::TikTok) {
            foreach (self::TIKTOK_DIRECT_OPTIONS as $k) {
                if ($asked($k)) {
                    $errors[] = "La opcion $k solo existe en TikTok.";
                }
            }

            return $errors;
        }

        $direct = TikTokPublisher::directPost($account);
        if ($asked('auto_add_music') && $type !== ContentType::TikTokPhoto) {
            $errors[] = 'La música recomendada de TikTok solo existe en fotos y carruseles, no en vídeos.';
        }
        foreach (self::TIKTOK_DIRECT_OPTIONS as $k) {
            if ($asked($k) && ! $direct) {
                $errors[] = "La opcion $k solo actua con la publicacion directa de TikTok: esta cuenta envia borradores a la bandeja.";
            }
        }

        if ($type === ContentType::TikTokPhoto) {
            foreach (['disable_duet', 'disable_stitch'] as $k) {
                if ($asked($k)) {
                    $errors[] = "La opcion $k solo existe en los videos de TikTok.";
                }
            }
        }

        // Exigencia de TikTok para el contenido de marca (colaboracion de pago): no puede ser privado.
        if ($asked('brand_content_toggle') && $direct && in_array($options['privacy_level'] ?? 'SELF_ONLY', ['SELF_ONLY', null, ''], true)) {
            $errors[] = 'El contenido de marca (colaboracion de pago) no puede ser privado: elige otra visibilidad en TikTok.';
        }

        return $errors;
    }

    private function targetLabel(SocialAccount $account): string
    {
        return "Media propia de {$account->name} ({$account->network->label()})";
    }

    /**
     * Media efectiva de un destino (T-17) y los errores de sus metadatos. `$own` null = hereda `$common`; con lista, se resuelven sus
     * ids entre la media del usuario (en ese orden) y se validan alt y portada contra lo que admite el tipo de contenido.
     *
     * @param  ?list<array{id: int, alt: ?string, cover_id: ?int}>  $own
     * @param  list<MediaAsset>  $common
     * @return array{0: list<MediaAsset>, 1: list<string>}
     */
    private function effectiveTargetMedia(User $user, SocialAccount $account, ContentType $type, ?array $own, array $common): array
    {
        if ($own === null) {
            return [$common, []];
        }

        $label = $this->targetLabel($account);
        $errors = [];
        $ids = array_values(array_unique(array_filter(array_merge(array_column($own, 'id'), array_column($own, 'cover_id')))));
        $found = MediaAsset::withoutUserScope()->where('user_id', $user->id)->whereIn('id', $ids)->get()->keyBy('id');

        $assets = [];
        $seen = [];
        foreach ($own as $i => $item) {
            $n = $i + 1;
            $asset = $found->get($item['id']);
            if ($asset === null) {
                $errors[] = "$label: el archivo {$item['id']} no existe.";

                continue;
            }
            if (isset($seen[$asset->id])) {
                $errors[] = "$label: el archivo {$asset->id} esta repetido.";

                continue;
            }
            $seen[$asset->id] = true;
            $assets[] = $asset;

            if ($item['alt'] !== null) {
                if (! TargetMedia::supportsAlt($type)) {
                    $errors[] = "$label: {$account->network->label()} no admite texto alternativo en {$type->label()}.";
                } elseif ($asset->isVideo()) {
                    $errors[] = "$label: el texto alternativo solo se aplica a imagenes (archivo $n).";
                } elseif (mb_strlen($item['alt']) > TargetMedia::ALT_MAX) {
                    $errors[] = "$label: el texto alternativo del archivo $n admite como maximo ".TargetMedia::ALT_MAX.' caracteres (tiene '.mb_strlen($item['alt']).').';
                }
            }

            if ($item['cover_id'] !== null) {
                $cover = $found->get($item['cover_id']);
                if (! TargetMedia::supportsCover($type)) {
                    $errors[] = "$label: {$account->network->label()} no admite portada en {$type->label()}.";
                } elseif (! $asset->isVideo()) {
                    $errors[] = "$label: la portada solo se aplica a videos (archivo $n).";
                } elseif ($cover === null) {
                    $errors[] = "$label: la portada {$item['cover_id']} no existe.";
                } elseif ($cover->kind !== MediaAsset::KIND_IMAGE || ! $cover->isReady()) {
                    $errors[] = "$label: la portada del archivo $n debe ser una imagen lista.";
                } elseif ($account->network === Network::YouTube && (int) ($cover->size ?? $cover->declared_size) > TargetMedia::YOUTUBE_COVER_MAX_BYTES) {
                    $errors[] = "$label: la portada del archivo $n pesa mas de 2 MB, el maximo de las miniaturas de YouTube.";
                }
            }
        }

        return [$assets, $errors];
    }

    /**
     * Valida todo y devuelve el plan normalizado (sin tocar la BD).
     *
     * @param  array<string, mixed>  $data
     * @return array{text: ?string, scheduled_at: ?CarbonImmutable, status: PostStatus, publish_now: bool, media: list<MediaAsset>, targets: list<array{account: SocialAccount, type: ContentType, options: array, text: ?string, first_comment: ?string}>}
     */
    public function plan(User $user, Brand $brand, array $data, ?int $currentCampaignId = null): array
    {
        $errors = [];
        $publishNow = (bool) ($data['publish_now'] ?? false);
        $schedule = (bool) ($data['schedule'] ?? false) || $publishNow;
        $text = isset($data['text']) ? (string) $data['text'] : null;

        $at = null;
        if ($publishNow) {
            // La fecha de «publicar ahora» no sale de la entrada: la pone el servidor, y no se exige que sea futura.
            $at = CarbonImmutable::instance(now())->utc();
        } elseif (($data['scheduled_at'] ?? null) !== null && $data['scheduled_at'] !== '') {
            try {
                $at = $this->parseWhen($data['scheduled_at'], $brand);
            } catch (Throwable) {
                $errors['scheduled_at'][] = 'La fecha no es valida.';
            }
        }

        if ($schedule && $at === null && ! isset($errors['scheduled_at'])) {
            $errors['scheduled_at'][] = 'Indica la fecha y hora de publicacion.';
        } elseif ($schedule && ! $publishNow && $at !== null && $at->lte(now())) {
            $errors['scheduled_at'][] = 'La fecha de publicacion debe ser futura.';
        }

        // Media del usuario, lista, en el orden indicado.
        $ids = array_values(array_map('intval', (array) ($data['media'] ?? [])));
        $found = MediaAsset::withoutUserScope()->where('user_id', $user->id)->whereIn('id', $ids)->get()->keyBy('id');
        $media = [];
        foreach ($ids as $id) {
            if (! $found->has($id)) {
                $errors['media'][] = "El archivo $id no existe.";

                continue;
            }
            $media[] = $found[$id];
        }

        $rawTargets = array_values((array) ($data['targets'] ?? []));
        if ($schedule && $rawTargets === []) {
            $errors['targets'][] = 'Elige al menos una cuenta de destino.';
        }

        $targets = [];
        $seen = [];
        foreach ($rawTargets as $i => $raw) {
            $key = "targets.$i";
            $account = SocialAccount::withoutUserScope()
                ->where('user_id', $user->id)->where('brand_id', $brand->id)
                ->where('status', '!=', SocialAccount::STATUS_DISCONNECTED)
                ->find((int) ($raw['account_id'] ?? 0));
            $type = ContentType::tryFrom((string) ($raw['content_type'] ?? ''));

            if (! $account) {
                $errors[$key][] = 'La cuenta no existe en esta marca o esta desconectada.';

                continue;
            }
            if (! $type || $type->network() !== $account->network) {
                $errors[$key][] = "El tipo de contenido no corresponde a {$account->network->label()}.";

                continue;
            }
            if (! $type->isOffered()) {
                $errors[$key][] = ! $account->network->isAvailable()
                    ? "{$account->network->label()} no esta disponible en esta instalacion."
                    : "{$type->label()} de {$account->network->label()} no esta disponible: necesita un dominio verificado en TikTok.";

                continue;
            }
            if ($schedule && ! $account->isActive()) {
                $errors[$key][] = "La cuenta {$account->name} necesita reconectarse antes de programar.";
            }
            if (isset($seen[$account->id.'|'.$type->value])) {
                $errors[$key][] = 'Esa cuenta y tipo ya estan en la publicacion.';

                continue;
            }
            $seen[$account->id.'|'.$type->value] = true;

            // Texto propio del destino (null = vinculado a la plantilla): se valida el EFECTIVO, que es el que se publica.
            // Un texto propio vacio o solo con espacios es `null` (vinculado): publicaria vacio y `findPublished` no podria reconocerlo.
            // Igual que la web (`TrimStrings` + `ConvertEmptyStringsToNull`), y vale tambien para el MCP.
            $ownText = isset($raw['text']) && trim((string) $raw['text']) !== '' ? (string) $raw['text'] : null;
            // Media del destino (T-17): la propia o, si hereda, la comun. Es la que se valida y la que se publicara.
            $ownMedia = TargetMedia::normalize($raw['media'] ?? null);
            [$effectiveMedia, $mediaErrors] = $this->effectiveTargetMedia($user, $account, $type, $ownMedia, $media);
            foreach ($mediaErrors as $message) {
                $errors[$key][] = $message;
            }
            foreach ($this->validator->validate($type, $effectiveMedia, $ownText ?? $text) as $message) {
                $errors[$key][] = $ownMedia !== null ? $this->targetLabel($account).': '.$message : $message;
            }

            $options = self::normalizeOptions((array) ($raw['options'] ?? []));
            foreach ($this->optionErrors($type, $account, $options) as $message) {
                $errors[$key][] = $message;
            }
            // Directrices de TikTok: la visibilidad la elige el usuario en cada publicacion, sin valor por defecto. Un borrador puede
            // guardarse sin ella; programar o publicar no.
            if ($schedule && $type->network() === Network::TikTok && TikTokPublisher::directPost($account) && blank($options['privacy_level'] ?? null)) {
                $errors[$key][] = 'Elige la visibilidad de TikTok antes de programar o publicar.';
            }

            // Primer comentario (C-01b): vacio = sin comentario. El maximo y los tipos que lo admiten salen de `media_specs`.
            $firstComment = isset($raw['first_comment']) && trim((string) $raw['first_comment']) !== '' ? (string) $raw['first_comment'] : null;
            if ($firstComment !== null) {
                $max = config("media_specs.types.{$type->value}.first_comment.max");
                if ($max === null) {
                    $errors[$key][] = "{$account->network->label()} no admite primer comentario en este tipo de contenido.";
                } elseif ($account->network === Network::LinkedIn && ! LinkedInPublisher::canComment($account)) {
                    $errors[$key][] = (string) LinkedInPublisher::commentNote($account);
                } elseif (($lengthError = FirstCommentService::lengthError($firstComment, (array) config("media_specs.types.{$type->value}.first_comment"), $account->network->label())) !== null) {
                    $errors[$key][] = $lengthError;
                }
            }

            $targets[] = ['account' => $account, 'type' => $type, 'options' => $options, 'text' => $ownText, 'first_comment' => $firstComment, 'media' => $ownMedia, 'manual' => filter_var($raw['manual'] ?? false, FILTER_VALIDATE_BOOLEAN)];
        }

        // Campaña (C-03, CA-12): null = quitarla; solo de esta marca y de este usuario. Omitida = no se toca (`false`).
        $campaignId = false;
        if (array_key_exists('campaign_id', $data)) {
            $campaignId = ($data['campaign_id'] === null || $data['campaign_id'] === '') ? null : (int) $data['campaign_id'];
            $errors += $this->campaignErrors($user, $brand, $campaignId, $currentCampaignId);
        }

        if ($errors !== []) {
            throw new PostValidationException($errors);
        }

        return [
            'campaign_id' => $campaignId,
            'text' => $text,
            // Notas internas: null = la entrada no las trae (no se tocan); '' o solo espacios = borrarlas. Nunca viajan a los publishers.
            'notes' => array_key_exists('notes', $data) ? self::normalizeNotes($data['notes']) : false,
            'scheduled_at' => $at,
            'status' => $schedule ? PostStatus::Scheduled : PostStatus::Draft,
            'publish_now' => $publishNow,
            'media' => $media,
            'targets' => $targets,
        ];
    }

    /**
     * Sincroniza media y destinos con el plan. Los destinos existentes (ya bloqueados y editables) se actualizan;
     * los que sobran se borran; los nuevos se crean.
     *
     * @param  array{status: PostStatus, media: list<MediaAsset>, targets: list<array{account: SocialAccount, type: ContentType, options: array, text: ?string, first_comment: ?string, media: ?list<array{id: int, alt: ?string, cover_id: ?int}>}>}  $plan
     * @param  Collection<string, PostTarget>|null  $existing
     * @param  array{text: ?string, media: list<int>}|null  $before  contenido previo del post (solo en edicion)
     */
    public function fill(Post $post, array $plan, ?Collection $existing = null, ?array $before = null): void
    {
        $existing ??= new Collection;
        // La media comun es la base de los destinos que heredan; texto y media EFECTIVOS se comparan por destino (ADR-005, T-17).
        $commonIds = array_map(fn (MediaAsset $m) => (int) $m->id, $plan['media']);
        $post->media()->sync(collect($plan['media'])->mapWithKeys(fn (MediaAsset $m, int $i) => [$m->id => ['position' => $i]])->all());

        $kept = [];

        foreach ($plan['targets'] as $t) {
            $current = $existing->get($t['account']->id.'|'.$t['type']->value);

            if ($current === null) {
                $target = new PostTarget([
                    'social_account_id' => $t['account']->id,
                    'network' => $t['account']->network,
                    'content_type' => $t['type'],
                    'status' => $plan['status'],
                    'options' => $t['options'] ?: null,
                    'text' => $t['text'],
                    'media' => $t['media'],
                    'manual' => $t['manual'] ?? false,
                    'first_comment' => $t['first_comment'] ?? null,
                ]);
                $target->user_id = $post->user_id;
                $target->post_id = $post->id;
                $target->save();

                continue;
            }

            $kept[$current->id] = true;
            // Un destino con error vuelve a empezar de cero; uno pendiente conserva sus intentos (idempotencia) mientras
            // el contenido sea el mismo: si cambia el texto, la media o las opciones del destino, el estado residual
            // (referencia externa, progreso, intentos) pertenece al contenido viejo y se descarta. Solo la fecha: se conserva.
            // El texto efectivo VIEJO se calcula aqui, antes de escribir `text` en el destino: es el que `freshAttempt` guarda en
            // `reconcile.text` para que el reintento reconozca lo ya publicado con el texto anterior.
            $oldText = $this->transitions->effectiveText($current, $before['text'] ?? null);
            $newText = $t['text'] ?? ($plan['text'] ?? null);
            $textChanged = $before !== null && $oldText !== $newText;
            $optionsChanged = $before !== null && ($current->options ?? []) != ($t['options'] ?? []);
            $mediaChanged = $before !== null && TargetMedia::effective($current, $before['media']) != TargetMedia::effective($t['media'], $commonIds);
            $fresh = $current->status === PostStatus::Failed || $mediaChanged || $textChanged || $optionsChanged;
            // El primer comentario NO cuenta para `$fresh`: se publica despues del destino y no cambia lo que sale en la red.
            $manual = (bool) ($t['manual'] ?? false);
            $rescheduled = $before !== null && ! $this->sameInstant($before['scheduled_at'] ?? null, $plan['scheduled_at'] ?? null);
            // El aviso manual se rearma si cambia la hora, el modo manual o el contenido: el usuario volvera a recibirlo.
            $rearm = ($manual !== (bool) $current->manual || $rescheduled || $fresh) ? ['manual_notified_at' => null, 'manual_reminded_at' => null] : [];
            $attributes = ['options' => $t['options'] ?: null, 'text' => $t['text'], 'media' => $t['media'], 'manual' => $manual, 'first_comment' => $t['first_comment'] ?? null] + $rearm + ($fresh ? $this->transitions->freshAttempt($current, $oldText, $before['scheduled_at'] ?? null) : []);
            if (! $fresh) {
                $attributes += $this->transitions->releaseBackoff($current, (bool) ($plan['publish_now'] ?? false));
            }
            if ($fresh) {
                $attributes = $this->transitions->keepRatePause($current, $attributes);
                YouTubePublisher::forgetUploadSession($current->id);
            }

            if ($current->status === $plan['status']) {
                $current->forceFill($attributes)->save();
            } else {
                $this->transitions->apply($current, $plan['status'], $attributes);
            }
        }

        foreach ($existing as $current) {
            if (! isset($kept[$current->id]) && $current->status->canBeDeleted()) {
                $current->delete();
            }
        }

        $post->syncStatus();
    }

    private function sameInstant(?CarbonInterface $a, ?CarbonInterface $b): bool
    {
        return ($a === null && $b === null) || ($a !== null && $b !== null && $a->getTimestamp() === $b->getTimestamp());
    }

    /** Un string sin zona se interpreta en la zona de la marca; el resultado se guarda en UTC. */
    public function parseWhen(string|CarbonInterface $when, Brand $brand): CarbonImmutable
    {
        if ($when instanceof CarbonInterface) {
            return CarbonImmutable::instance($when)->utc();
        }

        return CarbonImmutable::parse($when, $brand->timezone ?: 'UTC')->utc();
    }
}
