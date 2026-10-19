<?php

namespace App\Services;

use App\Connectors\Data\ConnectionCandidate;
use App\Connectors\Data\RefreshResult;
use App\Models\Brand;
use App\Models\SocialAccount;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection;
use InvalidArgumentException;

/**
 * Capa unica para web, jobs y MCP. Exige siempre el usuario/marca explicito:
 * ninguna ruta de entrada consulta cuentas sin identidad ni cruza usuarios.
 */
class SocialAccountService
{
    /**
     * Cuentas visibles de la marca. Las `desconectada` no se listan (CA-03: desconectar la quita de la
     * lista); la fila se conserva solo como historico y `connect()` la reactiva si se vuelve a conectar.
     *
     * @return Collection<int, SocialAccount>
     */
    public function listForBrand(User $user, Brand $brand): Collection
    {
        $this->assertOwns($user, $brand);

        return SocialAccount::withoutUserScope()
            ->where('user_id', $user->id)
            ->where('brand_id', $brand->id)
            ->where('status', '!=', SocialAccount::STATUS_DISCONNECTED)
            ->orderBy('network')->orderBy('name')
            ->get();
    }

    public function find(User $user, int|string $id): ?SocialAccount
    {
        return SocialAccount::withoutUserScope()->where('user_id', $user->id)->find($id);
    }

    /** Crea o actualiza (por marca+red+id externo) y deja la cuenta `activa`. */
    public function connect(User $user, Brand $brand, ConnectionCandidate $candidate): SocialAccount
    {
        $this->assertOwns($user, $brand);

        $account = SocialAccount::withoutUserScope()->firstOrNew([
            'brand_id' => $brand->id,
            'network' => $candidate->network->value,
            'external_id' => $candidate->externalId,
        ]);

        $account->user_id = $user->id;
        $account->brand_id = $brand->id;
        $account->fill([
            'name' => $candidate->name,
            'username' => $candidate->username,
            'status' => SocialAccount::STATUS_ACTIVE,
            'access_token' => $candidate->accessToken,
            'refresh_token' => $candidate->refreshToken,
            'token_expires_at' => $candidate->tokenExpiresAt,
            'refresh_expires_at' => $candidate->refreshExpiresAt,
            'data_access_expires_at' => $candidate->dataAccessExpiresAt,
            'refresh_failures' => 0,
            'scopes' => $candidate->scopes,
            // Conserva opciones por cuenta ya guardadas (p. ej. tiktok.direct_post) al reconectar.
            'meta' => array_merge($account->meta ?? [], $candidate->meta),
            'last_error' => null,
            'connected_at' => now(),
            'last_refreshed_at' => now(),
        ]);
        $account->save();

        return $account;
    }

    /** Desconectar elimina los tokens; la fila se conserva para el historico. */
    public function disconnect(SocialAccount $account): SocialAccount
    {
        $account->forceFill([
            'status' => SocialAccount::STATUS_DISCONNECTED,
            'access_token' => null,
            'refresh_token' => null,
            'token_expires_at' => null,
            'refresh_expires_at' => null,
            'data_access_expires_at' => null,
            'refresh_failures' => 0,
            'last_error' => null,
        ])->save();

        return $account;
    }

    public function applyRefresh(SocialAccount $account, RefreshResult $result): SocialAccount
    {
        $account->forceFill([
            'access_token' => $result->accessToken,
            'refresh_token' => $result->refreshToken ?? $account->refresh_token,
            'token_expires_at' => $result->tokenExpiresAt,
            'refresh_expires_at' => $result->refreshExpiresAt ?? $account->refresh_expires_at,
            'data_access_expires_at' => $result->dataAccessExpiresAt ?? $account->data_access_expires_at,
            'status' => SocialAccount::STATUS_ACTIVE,
            'refresh_failures' => 0,
            'last_error' => null,
            'last_refreshed_at' => now(),
        ])->save();

        return $account;
    }

    /** Anota un fallo transitorio de renovacion y devuelve cuantos van seguidos. */
    public function recordTransientFailure(SocialAccount $account, string $reason): int
    {
        $account->forceFill([
            'refresh_failures' => (int) $account->refresh_failures + 1,
            'last_error' => mb_substr($reason, 0, 500),
        ])->save();

        return (int) $account->refresh_failures;
    }

    /** @return bool true si la cuenta acaba de pasar a `requiere_reconexion` (para notificar una sola vez). */
    public function markRequiresReconnection(SocialAccount $account, string $reason): bool
    {
        $changed = $account->status !== SocialAccount::STATUS_RECONNECT;

        $account->forceFill([
            'status' => SocialAccount::STATUS_RECONNECT,
            'last_error' => mb_substr($reason, 0, 500),
        ])->save();

        return $changed;
    }

    private function assertOwns(User $user, Brand $brand): void
    {
        if ($brand->user_id !== $user->id) {
            throw new InvalidArgumentException('La marca no pertenece al usuario.');
        }
    }
}
