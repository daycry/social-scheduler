<?php

use App\Connectors\ConnectorRegistry;
use App\Connectors\Meta\Instagram\InstagramPublisher;
use App\Connectors\Network;
use App\Enums\PostStatus;
use App\Jobs\DispatchDuePosts;
use App\Models\Brand;
use App\Models\MediaAsset;
use App\Models\Post;
use App\Models\SocialAccount;
use App\Models\User;
use App\Services\PostService;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Tests\Support\FakeGraph;

// C-03 (T-20, CA-11): un reintento tras un timeout con texto propio por destino NO duplica en Instagram. La red "tiene" lo que
// de verdad se publico (caption fijo en cada escenario, NO calculado por el codigo bajo prueba).

const TT_IG_ID = '17841400000000099';

beforeEach(function () {
    Carbon::setTestNow('2026-11-01 12:00:00');
    config(['social.meta.graph_version' => 'v23.0', 'filesystems.media' => 'local']);
    Storage::fake('local');
    $this->user = User::factory()->create();
    $this->brand = Brand::factory()->for($this->user)->create();
    $this->account = SocialAccount::factory()->for($this->user)->create([
        'brand_id' => $this->brand->id, 'network' => Network::Instagram, 'external_id' => TT_IG_ID, 'access_token' => 'page-token-xyz',
    ]);
    $this->asset = MediaAsset::factory()->for($this->user)->create();
    Storage::disk('local')->put($this->asset->path, 'x');
    $this->graph = new FakeGraph;
    $this->app->instance(ConnectorRegistry::class, (new ConnectorRegistry)->registerPublisher(Network::Instagram, new InstagramPublisher));
    $this->service = app(PostService::class);
});

function ttIgData(string $template, ?string $own): array
{
    return ['text' => $template, 'scheduled_at' => now()->addHour(), 'schedule' => true, 'media' => [test()->asset->id],
        'targets' => [['account_id' => test()->account->id, 'content_type' => 'instagram_image', 'text' => $own]]];
}

test('TargetTextIdempotencyInstagram: el reintento tras un timeout reconoce lo ya publicado y no duplica', function (?string $own, string $template, ?string $editTemplate, ?string $editOwn, bool $edits, string $published) {
    $this->graph
        ->on('GET', '#/content_publishing_limit$#', ['data' => [['quota_usage' => 1, 'config' => ['quota_total' => 25]]]])
        ->on('POST', '#^'.TT_IG_ID.'/media$#', ['id' => '9800'])
        // 1.ª consulta (avance del primer intento): FINISHED; despues, el contenedor consta como PUBLISHED.
        ->on('GET', '#^9800$#', FakeGraph::seq(['status_code' => 'FINISHED'], ['status_code' => 'PUBLISHED']))
        ->on('POST', '#/media_publish$#', new ConnectionException('cURL error 28: timeout'))
        ->on('GET', '#^18\d+$#', ['permalink' => 'https://www.instagram.com/p/ZZ/'])
        // La red SI habia publicado en el primer intento: aparece en la media reciente con el texto que de verdad salio.
        ->on('GET', '#^'.TT_IG_ID.'/media$#', ['data' => [['id' => '18800', 'caption' => $published, 'permalink' => 'https://www.instagram.com/p/ZZ/', 'timestamp' => '2026-11-01T13:02:00+0000']]]);

    $post = $this->service->create($this->user, $this->brand, ttIgData($template, $own));
    $target = $post->targets->first();

    Carbon::setTestNow('2026-11-01 13:01:00');
    app(DispatchDuePosts::class)();

    expect($target->fresh()->status)->toBe(PostStatus::Scheduled)
        ->and($this->graph->callsTo('POST', '#/media_publish$#'))->toHaveCount(1)
        ->and($this->graph->callsTo('POST', '#^'.TT_IG_ID.'/media$#')[0]['params']['caption'])->toBe($published);

    if ($edits) {
        $this->service->update($this->user, Post::withoutUserScope()->find($post->id), ttIgData($editTemplate ?? $template, $editOwn ?? $own));
    }

    Carbon::setTestNow('2026-11-01 14:30:00');
    app(DispatchDuePosts::class)();

    $t = $target->fresh();
    expect($this->graph->callsTo('POST', '#/media_publish$#'))->toHaveCount(1) // una sola publicacion
        ->and($this->graph->callsTo('POST', '#^'.TT_IG_ID.'/media$#'))->toHaveCount(1)
        ->and($t->status)->toBe(PostStatus::Published)->and($t->external_id)->toBe('18800');
})->with([
    'a) destino desvinculado' => ['Propio IG', 'Plantilla', null, null, false, 'Propio IG'],
    'b) plantilla editada entre intentos (destino vinculado)' => [null, 'Plantilla vieja', 'Plantilla NUEVA', null, true, 'Plantilla vieja'],
    'c) texto propio editado entre intentos' => ['Propio viejo', 'Plantilla', null, 'Propio NUEVO', true, 'Propio viejo'],
    'd) destino vinculado' => [null, 'Plantilla', null, null, false, 'Plantilla'],
]);
