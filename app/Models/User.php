<?php

namespace App\Models;

use Database\Factories\UserFactory;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Facades\DB;
use Laravel\Passkeys\Contracts\PasskeyUser;
use Laravel\Passkeys\PasskeyAuthenticatable;
use Laravel\Sanctum\HasApiTokens;

#[Fillable(['name', 'email', 'password', 'notify_by_mail'])]
#[Hidden(['password', 'remember_token', 'two_factor_secret', 'two_factor_recovery_codes'])]
class User extends Authenticatable implements MustVerifyEmail, PasskeyUser
{
    /** @use HasFactory<UserFactory> */
    use HasApiTokens, HasFactory, Notifiable, PasskeyAuthenticatable;

    protected static function booted(): void
    {
        // Las API keys del MCP no sobreviven al usuario (relacion polimorfica: sin FK).
        static::deleting(function (self $user) {
            $user->tokens()->delete();
            $user->purgeMediaReferences();
        });
    }

    /** Purga de referencias y borrado en una sola transaccion: si el borrado falla, no queda la cuenta a medio vaciar. */
    public function delete()
    {
        return DB::transaction(fn () => parent::delete());
    }

    /**
     * Las FK `restrict` de las referencias a media (`post_media`, `post_target_media`, `post_series_media`, `autolist_item_media`) no
     * dependen del orden de las cascadas de InnoDB al borrar la cuenta: se vacian explicitamente ANTES de que caiga el usuario
     * (y con el, sus posts, destinos, series, autolistas y media).
     */
    private function purgeMediaReferences(): void
    {
        DB::transaction(function () {
            $media = DB::table('media_assets')->where('user_id', $this->id)->select('id');
            foreach (['post_media', 'post_target_media', 'post_series_media', 'autolist_item_media'] as $table) {
                DB::table($table)->whereIn('media_asset_id', $media)->delete();
            }
            DB::table('post_target_media')->whereIn('cover_id', $media)->delete();
        });
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'notify_by_mail' => 'boolean',
            'password' => 'hashed',
            'two_factor_secret' => 'encrypted',
            'two_factor_recovery_codes' => 'array',
            'two_factor_confirmed_at' => 'datetime',
        ];
    }

    public function socialIdentities(): HasMany
    {
        return $this->hasMany(SocialIdentity::class);
    }

    /** El 2FA solo cuenta cuando se ha confirmado con un primer codigo valido. */
    public function hasTwoFactorEnabled(): bool
    {
        return $this->two_factor_secret !== null && $this->two_factor_confirmed_at !== null;
    }
}
