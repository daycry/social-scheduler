<?php

namespace App\Connectors\Meta\Instagram;

use App\Connectors\Exceptions\ConnectorException;
use App\Connectors\Exceptions\PermanentPublishException;
use App\Connectors\Exceptions\QuotaExceededException;
use App\Connectors\Exceptions\ReconnectRequiredException;
use App\Connectors\Exceptions\TransientPublishException;
use App\Connectors\Meta\GraphClient;
use App\Connectors\Network;
use App\Enums\ContentType;
use App\Models\SocialAccount;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Validator;
use InvalidArgumentException;

/** Authorized Meta audio catalog. Callers must resolve the account within their user's brand. */
class InstagramAudio
{
    public function __construct(private readonly GraphClient $graph = new GraphClient) {}

    public static function unavailableReason(SocialAccount $account): ?string
    {
        if ($account->network !== Network::Instagram || ! $account->network->isAvailable()) {
            return 'El catálogo de audio solo está disponible para Instagram Reels.';
        }
        if (! $account->isActive() || blank($account->refresh_token)
            || $account->token_expires_at?->isPast() || $account->data_access_expires_at?->isPast()) {
            return 'Reconecta Instagram mediante Facebook para usar el catálogo de audio.';
        }

        return null;
    }

    public function search(SocialAccount $account, string $query = '', string $type = 'all', ?string $cursor = null): array
    {
        $this->assertAvailable($account);
        if (! in_array($type, ['all', 'music', 'original_sound'], true) || mb_strlen($query) > 200) {
            throw new InvalidArgumentException('La búsqueda de audio no es válida.');
        }
        $query = trim($query);
        $positions = $cursor === null ? null : $this->decodeCursor($cursor, $account, $query, $type);
        $tracks = [];
        $next = [];
        foreach ($type === 'all' ? ['music', 'original_sound'] : [$type] as $kind) {
            if ($positions !== null && ! array_key_exists($kind, $positions)) {
                continue;
            }
            $params = ['audio_type' => $kind, 'user_id' => $account->external_id];
            if ($query !== '') {
                $params['search_query'] = $query;
            }
            if ($positions !== null) {
                $params['after'] = $positions[$kind];
            }
            $data = $this->read($account, 'ig_audio', $params);
            if (! isset($data['audio']) || ! is_array($data['audio'])) {
                throw new InvalidArgumentException('Meta no devolvió un catálogo de audio válido. Inténtalo de nuevo.');
            }
            foreach ($data['audio'] as $row) {
                if (is_array($row) && self::validId($row['audio_id'] ?? null)) {
                    $tracks[(string) $row['audio_id']] = $this->present($row);
                }
            }
            // ig_audio can return cursors without paging.next. Always use our fixed endpoint.
            $after = $data['paging']['cursors']['after'] ?? null;
            if ($data['audio'] !== [] && is_string($after) && $after !== '' && strlen($after) <= 1024
                && $after !== ($positions[$kind] ?? null)) {
                $next[$kind] = $after;
            }
        }

        $nextCursor = $next === [] ? null : Crypt::encryptString(json_encode([
            'account_id' => $account->id, 'external_id' => $account->external_id,
            'query' => $query, 'type' => $type, 'positions' => $next, 'expires' => now()->addMinutes(30)->timestamp,
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

        return ['results' => array_values($tracks), 'next_cursor' => $nextCursor, 'has_more' => $nextCursor !== null];
    }

    private function decodeCursor(string $cursor, SocialAccount $account, string $query, string $type): array
    {
        try {
            if (strlen($cursor) > 8192) {
                throw new InvalidArgumentException;
            }
            $data = json_decode(Crypt::decryptString($cursor), true, 16, JSON_THROW_ON_ERROR);
            if (! is_array($data) || ($data['account_id'] ?? null) !== $account->id
                || ($data['external_id'] ?? null) !== $account->external_id
                || ($data['query'] ?? null) !== $query || ($data['type'] ?? null) !== $type
                || ! is_int($data['expires'] ?? null) || $data['expires'] <= now()->timestamp
                || ! is_array($data['positions'] ?? null) || $data['positions'] === []) {
                throw new InvalidArgumentException;
            }
            foreach ($data['positions'] as $kind => $after) {
                if (! in_array($kind, $type === 'all' ? ['music', 'original_sound'] : [$type], true)
                    || ! is_string($after) || $after === '' || strlen($after) > 1024) {
                    throw new InvalidArgumentException;
                }
            }

            return $data['positions'];
        } catch (DecryptException|\JsonException|InvalidArgumentException) {
            throw new InvalidArgumentException('La página de audio no es válida o ha caducado. Repite la búsqueda.');
        }
    }

    public function get(SocialAccount $account, string $audioId, bool $forPublishing = false): array
    {
        if ($forPublishing && ($reason = self::unavailableReason($account)) !== null) {
            if ($account->network !== Network::Instagram || ! $account->network->isAvailable()) {
                throw new PermanentPublishException($reason, 'audio_no_admitido');
            }
            throw new ReconnectRequiredException($reason);
        }
        $this->assertAvailable($account);
        if (! self::validId($audioId)) {
            throw new InvalidArgumentException('El identificador de audio no es válido. Usa el catálogo de Instagram.');
        }
        $row = $this->read($account, $audioId, ['user_id' => $account->external_id], $forPublishing);
        if ((string) ($row['audio_id'] ?? '') !== $audioId) {
            throw new InvalidArgumentException('La pista de audio ya no está disponible para esta cuenta. Elige otra pista.');
        }

        return $this->present($row);
    }

    public static function validId(mixed $id): bool
    {
        return is_string($id) && preg_match('/^[0-9]{1,40}$/D', $id) === 1;
    }

    /** Same constraints for service callers, web, MCP and workers, including old stored payloads. */
    public static function selectionErrors(mixed $selection, ContentType $type, SocialAccount $account, bool $checkAvailability = true): array
    {
        if ($selection === null) {
            return [];
        }
        if ($type !== ContentType::InstagramReel) {
            return ['La selección de audio nativo solo existe en los Reels de Instagram.'];
        }
        $validator = Validator::make(['audio' => $selection], [
            'audio' => ['required', 'array:audio_id,audio_volume,video_volume'],
            'audio.audio_id' => ['required', 'string', 'regex:/^[0-9]{1,40}$/D'],
            'audio.audio_volume' => ['sometimes', 'integer', 'between:1,100'],
            'audio.video_volume' => ['sometimes', 'integer', 'between:1,100'],
        ]);
        if ($validator->fails()) {
            return ['La selección de audio no es válida: indica audio_id del catálogo y volúmenes enteros entre 1 y 100.'];
        }

        return $checkAvailability && ($reason = self::unavailableReason($account)) !== null ? [$reason] : [];
    }

    private function assertAvailable(SocialAccount $account): void
    {
        if (($reason = self::unavailableReason($account)) !== null) {
            throw new InvalidArgumentException($reason);
        }
    }

    private function read(SocialAccount $account, string $path, array $params, bool $forPublishing = false): array
    {
        try {
            // The catalog requires a USER token, stored encrypted as refresh_token by MetaConnector.
            return $this->graph->get($path, $params, (string) $account->refresh_token, true);
        } catch (ConnectorException $e) {
            if ($forPublishing) {
                // Keep queue classification and retry metadata, but do not persist raw provider text.
                throw match (true) {
                    $e instanceof ReconnectRequiredException => new ReconnectRequiredException('Meta rechazó el acceso al audio. Reconecta Instagram mediante Facebook.'),
                    $e instanceof QuotaExceededException => new QuotaExceededException('Meta limitó temporalmente el catálogo de audio.', $e->retryAt, $e->errorCode),
                    $e instanceof TransientPublishException => new TransientPublishException('El catálogo de audio de Meta no está disponible temporalmente. Se reintentará.', $e->errorCode),
                    $e instanceof PermanentPublishException => new PermanentPublishException('Meta rechazó la pista de audio. Comprueba su disponibilidad y los permisos de la cuenta.', $e->errorCode),
                    default => new PermanentPublishException('No se pudo consultar la pista de audio de Meta.', 'audio_catalogo_error'),
                };
            }
            // Never forward raw provider messages or temporary signed URLs to logs/audit/error clients.
            throw new InvalidArgumentException('No se pudo consultar el audio de Meta. Comprueba la conexión y los permisos de Instagram e inténtalo de nuevo.');
        }
    }

    private function present(array $row): array
    {
        return [
            'audio_id' => (string) $row['audio_id'],
            'title' => mb_substr((string) ($row['title'] ?? ''), 0, 300),
            'artist' => mb_substr((string) ($row['display_artist'] ?? $row['ig_username'] ?? ''), 0, 300),
            'audio_type' => in_array($row['audio_type'] ?? null, ['music', 'original_sound'], true) ? $row['audio_type'] : null,
            'duration_ms' => isset($row['duration_in_ms']) ? max(0, (int) $row['duration_in_ms']) : null,
            'preview_url' => $this->safeUrl($row['download_url'] ?? null),
            'artwork_url' => $this->safeUrl($row['cover_artwork_thumbnail_uri'] ?? $row['cover_artwork_thumbnail_url'] ?? $row['profile_picture_url'] ?? null),
            'platform_url' => $this->safeUrl($row['on_platform_audio_preview_link'] ?? null, true),
        ];
    }

    private function safeUrl(mixed $value, bool $platform = false): ?string
    {
        if (! is_string($value) || strlen($value) > 4096 || ! filter_var($value, FILTER_VALIDATE_URL)) {
            return null;
        }
        $url = parse_url($value);
        if (($url['scheme'] ?? '') !== 'https' || isset($url['user']) || isset($url['pass']) || ($url['port'] ?? 443) !== 443) {
            return null;
        }
        parse_str($url['query'] ?? '', $query);
        if (isset($query['access_token'])) {
            return null;
        }
        $host = strtolower($url['host'] ?? '');
        foreach ($platform ? ['instagram.com'] : ['fbcdn.net', 'cdninstagram.com', 'fbsbx.com'] as $domain) {
            if ($host === $domain || str_ends_with($host, '.'.$domain)) {
                return $value;
            }
        }

        return null;
    }
}
