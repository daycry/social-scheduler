<?php

use App\Connectors\Network;
use App\Models\Brand;
use App\Models\SocialAccount;
use App\Models\User;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\RateLimiter;
use Tests\Support\McpClient;

uses(McpClient::class);

// Revision T-28/T-29 (1): cada limite de ritmo por ruta tiene su PROPIO contador. Un `throttle:N,1` numerico y sin nombre comparte cubo
// (sha1 del usuario) con el grupo y con el resto de rutas, asi que agotar una bloqueaba las demas y el limite propio nunca actuaba.

test('ninguna ruta usa un throttle numerico sin nombre (comparten cubo): todos salen de config/rate_limits.php', function () {
    $offenders = [];
    foreach (glob(base_path('routes/*.php')) as $file) {
        foreach (file($file) as $n => $line) {
            if (preg_match("/throttle:\\d+,\\d+['\"]/", $line)) {
                $offenders[] = basename($file).':'.($n + 1);
            }
        }
    }

    expect($offenders)->toBe([]);
});

test('cada throttle con nombre de las rutas esta declarado en config/rate_limits.php y registrado como limitador', function () {
    $declared = array_keys(config('rate_limits.limits'));
    $used = [];
    foreach (app('router')->getRoutes() as $route) {
        foreach ($route->gatherMiddleware() as $middleware) {
            if (is_string($middleware) && preg_match('/^throttle:([A-Za-z][\w.\-]*)$/', $middleware, $m)) {
                $used[$m[1]] = true;
            }
        }
    }

    foreach (array_keys($used) as $name) {
        expect(RateLimiter::limiter($name))->not->toBeNull("limitador {$name} sin registrar");
    }
    // Todo lo declarado en la config se usa en alguna ruta: nada huerfano.
    expect(array_diff($declared, array_keys($used)))->toBe([]);
});

test('agotar el limite de una ruta de la API no afecta a otra del mismo usuario', function () {
    Queue::fake();
    $user = User::factory()->create();
    $brand = Brand::factory()->for($user)->create(['timezone' => 'UTC']);
    $account = SocialAccount::factory()->for($user)->create(['brand_id' => $brand->id, 'network' => Network::Facebook]);
    $key = $this->mcpKey($user);
    config(['rate_limits.limits' => array_merge(config('rate_limits.limits'), ['api.posts.cancel' => 2, 'api.posts.destroy' => 3])]);

    $call = function (string $method, string $uri, array $body = []) use ($key) {
        app('auth')->forgetGuards();

        return $this->withToken($key)->json($method, '/api/v1/'.$uri, $body);
    };
    $draft = $call('POST', 'brands/'.$brand->id.'/posts', ['text' => 'a', 'schedule' => false, 'targets' => [['account_id' => $account->id, 'content_type' => 'facebook_text']]])->assertCreated()->json('id');

    // cancel: 2 dentro del limite (409 por ser un borrador, pero NO 429), la tercera se corta.
    $call('POST', 'posts/'.$draft.'/cancel')->assertStatus(409);
    $call('POST', 'posts/'.$draft.'/cancel')->assertStatus(409);
    $call('POST', 'posts/'.$draft.'/cancel')->assertStatus(429)->assertJsonPath('message', fn (string $m) => str_contains($m, 'Demasiados intentos'));

    // El contador de cancelar no toca al de borrar, ni al resto de la API (el tope conjunto sigue lejos).
    $call('DELETE', 'posts/'.$draft)->assertOk();
    $call('GET', 'brands')->assertOk();
    $other = $call('POST', 'brands/'.$brand->id.'/posts', ['text' => 'b', 'schedule' => false, 'targets' => [['account_id' => $account->id, 'content_type' => 'facebook_text']]])->assertCreated()->json('id');
    $call('DELETE', 'posts/'.$other)->assertOk();
    $call('DELETE', 'posts/'.$other)->assertNotFound();
});

test('el limite de una ruta de la web tampoco comparte contador con otra', function () {
    $user = User::factory()->create();
    config(['rate_limits.limits' => array_merge(config('rate_limits.limits'), ['exports.analytics' => 1, 'exports.posts' => 1])]);

    $this->actingAs($user)->get(route('exports.analytics'))->assertOk();
    $this->actingAs($user)->get(route('exports.analytics'))->assertStatus(429);
    // La otra ruta conserva su cupo.
    $this->actingAs($user)->get(route('exports.posts'))->assertOk();
});
