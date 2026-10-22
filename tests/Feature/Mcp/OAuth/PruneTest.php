<?php

use App\Models\OAuthAuthCode;
use App\Models\OAuthClient;
use App\Models\User;
use Illuminate\Console\Scheduling\Schedule;

function pruneClient(int $createdDaysAgo): OAuthClient
{
    $c = OAuthClient::create(['name' => 'c', 'redirect_uris' => ['http://localhost:1/cb']]);
    $c->forceFill(['created_at' => now()->subDays($createdDaysAgo)])->save();

    return $c;
}

function pruneToken(User $u, OAuthClient $c, ?int $expiresInDays)
{
    $t = $u->createToken('oauth', ['posts:read'], $expiresInDays === null ? null : now()->addDays($expiresInDays))->accessToken;
    $t->forceFill(['oauth_client_id' => $c->id])->save();

    return $t;
}

test('OAuthPrune CA-31: borra solo clientes sin token vivo hace mas de 30 dias', function () {
    $user = User::factory()->create();

    $viejoSinToken = pruneClient(60);
    $nuevoSinToken = pruneClient(5);
    $viejoConTokenVivo = pruneClient(60);
    pruneToken($user, $viejoConTokenVivo, 10);
    $viejoConTokenCaducadoHaceMucho = pruneClient(90);
    pruneToken($user, $viejoConTokenCaducadoHaceMucho, -45);
    $viejoConTokenCaducadoReciente = pruneClient(90);
    pruneToken($user, $viejoConTokenCaducadoReciente, -3);

    $this->artisan('mcp:oauth-prune')->assertSuccessful();

    expect(OAuthClient::pluck('id')->all())->toEqualCanonicalizing([
        $nuevoSinToken->id, $viejoConTokenVivo->id, $viejoConTokenCaducadoReciente->id,
    ]);
    expect($viejoSinToken->exists)->toBeTrue()
        ->and(OAuthClient::find($viejoSinToken->id))->toBeNull()
        ->and(OAuthClient::find($viejoConTokenCaducadoHaceMucho->id))->toBeNull();
});

test('OAuthPrune: borra los codigos caducados y deja los vigentes', function () {
    $user = User::factory()->create();
    $client = pruneClient(1);
    $mk = fn (string $h, $exp) => OAuthAuthCode::create([
        'code_hash' => hash('sha256', $h), 'client_id' => $client->id, 'user_id' => $user->id,
        'redirect_uri' => 'http://localhost:1/cb', 'code_challenge' => 'x', 'abilities' => ['posts:read'], 'expires_at' => $exp,
    ]);
    $mk('viejo', now()->subMinute());
    $vigente = $mk('vigente', now()->addMinutes(5));

    $this->artisan('mcp:oauth-prune')->assertSuccessful();

    expect(OAuthAuthCode::pluck('id')->all())->toBe([$vigente->id]);
});

test('OAuthPrune: no toca los tokens de un cliente borrado ni a los usuarios', function () {
    $user = User::factory()->create();
    $c = pruneClient(60);
    $t = pruneToken($user, $c, -40);

    $this->artisan('mcp:oauth-prune')->assertSuccessful();

    expect($t->fresh()->oauth_client_id)->toBeNull()->and(User::count())->toBe(1);
});

test('OAuthPrune: el comando esta programado', function () {
    $names = collect(app(Schedule::class)->events())->map(fn ($e) => $e->description)->all();

    expect($names)->toContain('mcp-oauth-prune');
});
