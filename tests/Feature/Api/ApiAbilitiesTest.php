<?php

use App\Models\PersonalAccessToken;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Tests\Support\McpClient;

require_once __DIR__.'/../Mcp/OAuth/Helpers.php';

uses(McpClient::class);

test('media read and write are independent from brand and post permissions on REST and MCP', function () {
    Queue::fake();
    config(['services.mcp.per_minute_ip' => 100]);
    $user = User::factory()->create();
    $old = $this->mcpKey($user, ['brands:read', 'posts:write']);
    $read = $this->mcpKey($user, ['media:read']);
    $write = $this->mcpKey($user, ['media:write']);

    $request = function (string $key, string $method, string $uri) {
        app('auth')->forgetGuards();

        return $this->json($method, '/api/v1/'.$uri, [], ['Authorization' => 'Bearer '.$key]);
    };
    $request($old, 'GET', 'media')->assertForbidden();
    $request($read, 'GET', 'media')->assertOk();
    $request($write, 'GET', 'media')->assertForbidden();
    $request($old, 'POST', 'media/import-url')->assertForbidden();
    $request($write, 'POST', 'media/import-url')->assertUnprocessable()->assertJsonValidationErrors('url');

    foreach ([['POST', 'media'], ['POST', 'media/import-url'], ['PUT', 'media/1/upload'], ['POST', 'media/1/complete'], ['DELETE', 'media/1']] as [$method, $uri]) {
        $request($read, $method, $uri)->assertForbidden();
    }

    expect($this->callTool($old, 'list_media')[1])->toContain('media:read')
        ->and($this->callTool($read, 'list_media')[0])->toBeFalse()
        ->and($this->callTool($write, 'list_media')[1])->toContain('media:read')
        ->and($this->callTool($old, 'import_media_from_url')[1])->toContain('media:write')
        ->and($this->callTool($read, 'import_media_from_url')[1])->toContain('media:write')
        ->and($this->callTool($write, 'import_media_from_url')[1])->toContain('url');
});

test('the ability migration preserves existing grants for manual keys OAuth tokens and pending codes', function () {
    // RefreshDatabase instaló el esquema vacío. Simula la primera aplicación con datos históricos.
    DB::table('media_ability_backfill_cutoffs')->delete();
    $oldAi = 'studio'.':ai'; // Nombre almacenado antes de esta migración.
    $user = User::factory()->create();
    $client = oauthClient();
    $man = $user->createToken('manual', ['brands:read', 'stats:read', $oldAi]);
    $oauth = $user->createToken('OAuth', ['posts:write', 'stats:read']);
    $oauth->accessToken->update(['origin' => 'oauth', 'oauth_client_id' => $client->id]);
    $wildcard = $user->createToken('all', ['*']);
    $unrelated = $user->createToken('reports', ['reports:write']);
    $mixed = $user->createToken('all and explicit', ['*', $oldAi, $oldAi, 'brands:read']);
    [$code, $pending, , $pendingClient] = mintCode(['brands:read', 'posts:write', $oldAi], user: $user);

    $migration = require database_path('migrations/2026_11_17_185100_separate_media_and_design_ai_abilities.php');
    $migration->up();
    $migration->up();
    $newKey = $user->createToken('marca y publicaciones', ['brands:read', 'posts:write']);
    [, $newCode] = mintCode(['brands:read', 'posts:write'], user: $user);
    $migration->down();
    expect($man->accessToken->fresh()->abilities)->toEqualCanonicalizing(['brands:read', 'stats:read', 'media:read', $oldAi])
        ->and($pending->fresh()->abilities)->toContain($oldAi)->not->toContain('designs:ai')
        ->and($oauth->accessToken->fresh()->abilities)->toContain('media:write');
    $rollbackKey = $user->createToken('creada tras revertir', ['brands:read', $oldAi]);
    [, $rollbackCode] = mintCode(['posts:write'], user: $user);
    $migration->up();

    expect($man->accessToken->fresh()->abilities)->toEqualCanonicalizing(['brands:read', 'stats:read', 'media:read', 'designs:ai'])
        ->and($oauth->accessToken->fresh()->abilities)->toEqualCanonicalizing(['posts:write', 'stats:read', 'media:write'])
        ->and($wildcard->accessToken->fresh()->abilities)->toBe(['*'])
        ->and($unrelated->accessToken->fresh()->abilities)->toBe(['reports:write'])
        ->and($mixed->accessToken->fresh()->abilities)->toBe(['*', 'designs:ai', 'brands:read'])
        ->and($newKey->accessToken->fresh()->abilities)->toBe(['brands:read', 'posts:write'])
        ->and($newCode->fresh()->abilities)->toBe(['brands:read', 'posts:write'])
        ->and($rollbackKey->accessToken->fresh()->abilities)->toEqualCanonicalizing(['brands:read', 'media:read', 'designs:ai'])
        ->and($rollbackCode->fresh()->abilities)->toEqualCanonicalizing(['posts:write', 'media:write'])
        ->and($pending->fresh()->abilities)->toEqualCanonicalizing(['brands:read', 'posts:write', 'media:read', 'media:write', 'designs:ai']);
    $issued = redeem($code, $pendingClient)->assertOk()->json('access_token');
    expect(PersonalAccessToken::findToken($issued)?->abilities)->toBe($pending->fresh()->abilities);
    expect($this->callTool($issued, 'list_media')[0])->toBeFalse()
        ->and($this->callTool($man->plainTextToken, 'list_media')[0])->toBeFalse()
        ->and($this->callTool($oauth->plainTextToken, 'import_media_from_url')[1])->toContain('url');
});
