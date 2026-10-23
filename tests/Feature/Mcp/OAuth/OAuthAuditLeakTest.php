<?php

use App\Models\AuditLog;
use App\Models\OAuthAuthCode;
use App\Models\OAuthClient;
use App\Models\User;
use Illuminate\Support\Facades\Log;
use Tests\Support\McpClient;

uses(McpClient::class);

require_once __DIR__.'/Helpers.php';

/** CA-27: ningun evento OAuth deja codigos, tokens, verifiers ni secretos en audit_logs ni en el log. */
test('OAuthAuditLeak: el flujo completo, el replay y los errores no filtran secretos', function () {
    $logFile = tempnam(sys_get_temp_dir(), 'oauth-leak');
    Log::swap(Log::build(['driver' => 'single', 'path' => $logFile]));

    $verifier = 'verifier-secreto-de-leak-0123456789-0123456789-abcd';
    $user = User::factory()->create();
    $flow = runOAuthFlow($user, ['brands:read'], $verifier);
    $secretToken = explode('|', $flow['token'], 2)[1];

    // Replay (revoca el token), verifier erroneo y errores de authorize/registro.
    $client = OAuthClient::findOrFail($flow['client_id']);
    $this->post('/oauth/token', [
        'grant_type' => 'authorization_code', 'code' => $flow['code'], 'redirect_uri' => $client->redirect_uris[0],
        'client_id' => $client->id, 'code_verifier' => $verifier,
    ])->assertStatus(400);
    [$code2] = mintCode(client: $client);
    redeem($code2, $client, ['code_verifier' => 'incorrecto-incorrecto-incorrecto-incorrecto-123']);
    $this->actingAs($user)->post('/oauth/authorize', authorizeParams($client) + ['decision' => 'deny']);
    $this->postJson('/oauth/register', ['redirect_uris' => ['https://evil.example/cb']]);

    $secrets = [$flow['code'], $code2, $verifier, 'incorrecto-incorrecto', $secretToken, $flow['token']];
    $haystack = json_encode(AuditLog::all()->toArray()).(string) file_get_contents($logFile);

    foreach ($secrets as $secret) {
        expect($haystack)->not->toContain($secret);
    }
    // Ni el hash del codigo (equivale a conocer el codigo para quien lea la BD).
    foreach (OAuthAuthCode::all()->makeVisible('code_hash') as $row) {
        expect($haystack)->not->toContain($row->code_hash);
    }

    expect(AuditLog::pluck('action')->unique()->all())->toContain('oauth.client.registered', 'oauth.authorized', 'oauth.token.issued', 'oauth.code.replayed', 'oauth.denied');
    @unlink($logFile);
});
