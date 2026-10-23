<?php

use App\Connectors\ConnectorRegistry;
use App\Connectors\Meta\Facebook\FacebookPublisher;
use App\Connectors\Network;
use App\Enums\PostStatus;
use App\Jobs\DispatchDuePosts;
use App\Models\Brand;
use App\Models\Post;
use App\Models\SocialAccount;
use App\Models\User;
use App\Services\PostService;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Carbon;
use Tests\Support\FakeGraph;

// C-03 (T-20, CA-11): un reintento tras un timeout con texto propio por destino NO duplica en Facebook. La red "tiene" lo
// que de verdad se publico (texto fijo en cada escenario, NO calculado por el codigo bajo prueba): si el reintento compara otro
// texto, no lo reconoce y publica por segunda vez.

const TT_FB_PAGE = '1065269806670777';

beforeEach(function () {
    Carbon::setTestNow('2026-11-01 12:00:00');
    config(['social.meta.graph_version' => 'v23.0']);
    $this->user = User::factory()->create();
    $this->brand = Brand::factory()->for($this->user)->create();
    $this->account = SocialAccount::factory()->for($this->user)->create([
        'brand_id' => $this->brand->id, 'network' => Network::Facebook, 'external_id' => TT_FB_PAGE, 'access_token' => 'page-token-xyz',
    ]);
    $this->graph = new FakeGraph;
    $this->app->instance(ConnectorRegistry::class, (new ConnectorRegistry)->registerPublisher(Network::Facebook, new FacebookPublisher));
    $this->service = app(PostService::class);
});

function ttFbData(string $template, ?string $own): array
{
    return ['text' => $template, 'scheduled_at' => now()->addHour(), 'schedule' => true,
        'targets' => [['account_id' => test()->account->id, 'content_type' => 'facebook_text', 'text' => $own]]];
}

test('TargetTextIdempotencyFacebook: el reintento tras un timeout reconoce lo ya publicado y no duplica', function (?string $own, string $template, ?string $editTemplate, ?string $editOwn, bool $edits, string $published) {
    $sentAtFirstAttempt = $published;
    $this->graph
        ->on('POST', '#^'.TT_FB_PAGE.'/feed$#', new ConnectionException('cURL error 28: timeout'))
        // La red SI habia publicado en el primer intento (aunque la respuesta se perdio): aparece en el listado.
        ->on('GET', '#^'.TT_FB_PAGE.'/posts$#', ['data' => [['id' => TT_FB_PAGE.'_901', 'message' => $published, 'permalink_url' => 'https://www.facebook.com/'.TT_FB_PAGE.'/posts/901', 'created_time' => '2026-11-01T13:02:00+0000']]]);

    $post = $this->service->create($this->user, $this->brand, ttFbData($template, $own));
    $target = $post->targets->first();

    Carbon::setTestNow('2026-11-01 13:01:00');
    app(DispatchDuePosts::class)();

    expect($target->fresh()->status)->toBe(PostStatus::Scheduled)->and($target->fresh()->attempts)->toBe(1)
        ->and($this->graph->callsTo('POST', '#/feed$#'))->toHaveCount(1)
        ->and($this->graph->callsTo('POST', '#/feed$#')[0]['params']['message'])->toBe($sentAtFirstAttempt);

    if ($edits) {
        // Entre intentos se edita la plantilla o el texto propio; lo ya publicado conserva el texto VIEJO.
        $this->service->update($this->user, Post::withoutUserScope()->find($post->id), ttFbData($editTemplate ?? $template, $editOwn ?? $own));
    }

    Carbon::setTestNow('2026-11-01 14:30:00');
    app(DispatchDuePosts::class)();

    $t = $target->fresh();
    expect($this->graph->callsTo('POST', '#/feed$#'))->toHaveCount(1) // una sola publicacion
        ->and($t->status)->toBe(PostStatus::Published)->and($t->external_id)->toBe(TT_FB_PAGE.'_901');
})->with([
    'a) destino desvinculado' => ['Propio FB', 'Plantilla', null, null, false, 'Propio FB'],
    'b) plantilla editada entre intentos (destino vinculado)' => [null, 'Plantilla vieja', 'Plantilla NUEVA', null, true, 'Plantilla vieja'],
    'c) texto propio editado entre intentos' => ['Propio viejo', 'Plantilla', null, 'Propio NUEVO', true, 'Propio viejo'],
    'd) destino vinculado' => [null, 'Plantilla', null, null, false, 'Plantilla'],
]);
