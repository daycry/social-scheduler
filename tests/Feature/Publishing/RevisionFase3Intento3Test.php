<?php

use App\Connectors\ConnectorRegistry;
use App\Connectors\Data\PublishRequest;
use App\Connectors\Data\PublishResult;
use App\Connectors\Meta\Instagram\InstagramPublisher;
use App\Connectors\Network;
use App\Enums\ContentType;
use App\Enums\PostStatus;
use App\Jobs\DispatchDuePosts;
use App\Jobs\PublishPostTarget;
use App\Models\Brand;
use App\Models\MediaAsset;
use App\Models\Post;
use App\Models\PostTarget;
use App\Models\SocialAccount;
use App\Models\User;
use App\Services\PostService;
use App\Services\PublishRecovery;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\Support\FakeGraph;
use Tests\Support\FakePublisher;

// Revision Fase 3 intento 3: R1 (editar tras un intento ambiguo no pierde la prueba de idempotencia), R3 (carrera en la recuperacion), R4 (historia ajena).

const I3_IG = '17841400000000011';

beforeEach(function () {
    Carbon::setTestNow('2026-11-01 12:00:00');
    Notification::fake();
    config(['social.meta.graph_version' => 'v23.0', 'filesystems.media' => 'local']);
    Storage::fake('local');
    $this->user = User::factory()->create();
    $this->brand = Brand::factory()->for($this->user)->create();
    $this->account = SocialAccount::factory()->for($this->user)->create([
        'brand_id' => $this->brand->id, 'network' => Network::Instagram, 'external_id' => I3_IG, 'access_token' => 'page-token-xyz',
    ]);
    $this->graph = new FakeGraph;
    $this->app->instance(ConnectorRegistry::class, (new ConnectorRegistry)->registerPublisher(Network::Instagram, new InstagramPublisher));
    $this->graph->on('GET', '#/content_publishing_limit$#', ['data' => [['quota_usage' => 1, 'config' => ['quota_total' => 25]]]])
        ->on('GET', '#^1\d+$#', ['permalink' => 'https://www.instagram.com/p/ABC/']);
    $this->service = app(PostService::class);
});

/** Post de IG programado a futuro cuyo destino arrastra un intento anterior ambiguo. */
function i3Scheduled(array $residual): array
{
    $asset = MediaAsset::factory()->for(test()->user)->create();
    Storage::disk('local')->put($asset->path, 'x');
    $post = test()->service->create(test()->user, test()->brand, [
        'text' => 'Texto viejo', 'scheduled_at' => now()->addHour(), 'schedule' => true, 'media' => [$asset->id],
        'targets' => [['account_id' => test()->account->id, 'content_type' => 'instagram_image']],
    ]);
    $target = $post->targets->first();
    $target->forceFill($residual + ['next_attempt_at' => now()->addMinutes(30)])->save();

    return [$post->fresh(), $target];
}

function i3Edit(Post $post, string $text): Post
{
    return test()->service->update(test()->user, $post, [
        'text' => $text, 'scheduled_at' => $post->scheduled_at, 'schedule' => true, 'media' => $post->media->pluck('id')->all(),
        'targets' => [['account_id' => test()->account->id, 'content_type' => 'instagram_image']],
    ]);
}

// ------------------------------------------------------------------ R1

test('R1: contenedor viejo YA PUBLICADO + editar el texto: se reconcilia, NO hay segundo media_publish y se avisa en la nota', function () {
    [$post, $target] = i3Scheduled(['external_reference' => 'C_OLD', 'attempts' => 2, 'progress' => ['container' => 'C_OLD'], 'error_code' => 'transitorio']);
    $this->graph->on('GET', '#^C_OLD$#', ['status_code' => 'PUBLISHED'])
        ->on('POST', '#^'.I3_IG.'/media$#', ['id' => 'C_NEW'])->on('GET', '#^C_NEW$#', ['status_code' => 'FINISHED'])
        ->on('POST', '#/media_publish$#', ['id' => '19002'])
        ->on('GET', '#/media$#', ['data' => [['id' => '19001', 'caption' => 'Texto viejo', 'timestamp' => now()->addHour()->toIso8601String()]]]);

    i3Edit($post, 'Texto NUEVO');
    $reset = $target->fresh();
    expect($reset->external_reference)->toBeNull()->and($reset->attempts)->toBe(0)->and($reset->progress['reconcile']['reference'])->toBe('C_OLD');

    Carbon::setTestNow(now()->addHours(2));
    app(DispatchDuePosts::class)();

    $t = $target->fresh();
    expect($this->graph->callsTo('POST', '#/media_publish$#'))->toHaveCount(0)
        ->and($this->graph->callsTo('POST', '#^'.I3_IG.'/media$#'))->toHaveCount(0)
        ->and($t->status)->toBe(PostStatus::Published)->and($t->external_id)->toBe('19001')
        ->and($t->status_note)->toContain('La edicion no se aplico');
});

test('R1: contenedor viejo que NO salio (FINISHED): la edicion se aplica y se publica lo nuevo una sola vez', function () {
    [$post, $target] = i3Scheduled(['external_reference' => 'C_OLD', 'attempts' => 2, 'progress' => ['container' => 'C_OLD'], 'error_code' => 'transitorio']);
    $this->graph->on('GET', '#^C_OLD$#', ['status_code' => 'FINISHED'])
        ->on('POST', '#^'.I3_IG.'/media$#', ['id' => 'C_NEW'])->on('GET', '#^C_NEW$#', ['status_code' => 'FINISHED'])
        ->on('POST', '#/media_publish$#', ['id' => '19002']);

    i3Edit($post, 'Texto NUEVO');
    Carbon::setTestNow(now()->addHours(2));
    app(DispatchDuePosts::class)();

    $t = $target->fresh();
    expect($this->graph->callsTo('POST', '#/media_publish$#'))->toHaveCount(1)
        ->and($this->graph->callsTo('POST', '#/media_publish$#')[0]['params']['creation_id'] ?? 'C_NEW')->toBe('C_NEW')
        ->and($t->status)->toBe(PostStatus::Published)->and($t->external_id)->toBe('19002')
        ->and($t->progress['reconcile'] ?? null)->toBeNull();
});

test('R1: intento ambiguo SIN referencia (timeout tras enviar, attempts>0) tambien se reconcilia por el texto viejo', function () {
    [$post, $target] = i3Scheduled(['attempts' => 1, 'error_code' => 'transitorio']);
    $this->graph->on('GET', '#/media$#', ['data' => [['id' => '19001', 'caption' => 'Texto viejo', 'timestamp' => now()->addHour()->toIso8601String()]]])
        ->on('POST', '#^'.I3_IG.'/media$#', ['id' => 'C_NEW'])->on('POST', '#/media_publish$#', ['id' => '19002']);

    i3Edit($post, 'Texto NUEVO');
    expect($target->fresh()->progress['reconcile'])->toMatchArray(['text' => 'Texto viejo', 'pending' => true]);

    Carbon::setTestNow(now()->addHours(2));
    app(DispatchDuePosts::class)();

    expect($this->graph->callsTo('POST', '#/media_publish$#'))->toHaveCount(0)
        ->and($target->fresh()->external_id)->toBe('19001');
});

test('R1: un error permanente previo (rechazada) NO deja marcador: es un destino nuevo de verdad', function () {
    [$post, $target] = i3Scheduled(['attempts' => 1, 'error_code' => 'rechazada']);

    i3Edit($post, 'Texto NUEVO'
    );

    expect($target->fresh()->progress)->toBeNull();
});

test('R1: dos ediciones seguidas conservan el marcador del intento original; desprogramar tambien', function () {
    [$post, $target] = i3Scheduled(['external_reference' => 'C_OLD', 'attempts' => 2, 'progress' => ['container' => 'C_OLD'], 'error_code' => 'transitorio']);

    $post = i3Edit($post, 'Texto 2');
    $post = i3Edit($post, 'Texto 3');
    expect($target->fresh()->progress['reconcile'])->toMatchArray(['reference' => 'C_OLD', 'text' => 'Texto viejo']);

    $this->service->unschedule($this->user, $post);
    expect($target->fresh()->status)->toBe(PostStatus::Draft)->and($target->fresh()->progress['reconcile']['reference'])->toBe('C_OLD');
});

test('R1 (YouTube y TikTok): tras editar se consulta la red con la referencia/ventana VIEJAS antes de publicar y no se duplica', function (Network $network, ContentType $type, ?string $reference, ?string $expectedReference) {
    Queue::fake();
    $account = SocialAccount::factory()->for($this->user)->create(['brand_id' => $this->brand->id, 'network' => $network]);
    $post = Post::factory()->create(['brand_id' => $this->brand->id, 'user_id' => $this->user->id, 'text' => 'Texto viejo', 'scheduled_at' => now()->addHour(), 'status' => PostStatus::Scheduled]);
    $target = PostTarget::factory()->create([
        'post_id' => $post->id, 'user_id' => $this->user->id, 'social_account_id' => $account->id, 'network' => $network, 'content_type' => $type,
        'status' => PostStatus::Scheduled, 'external_reference' => $reference, 'attempts' => 2, 'error_code' => 'transitorio',
    ]);

    $this->service->unschedule($this->user, $post); // el estado residual se resetea
    $reset = $target->fresh();
    expect($reset->external_reference)->toBeNull()->and($reset->progress['reconcile']['reference'] ?? null)->toBe($expectedReference)
        ->and($reset->progress['reconcile']['text'])->toBe('Texto viejo');

    // La red YA tiene el contenido viejo: el siguiente claim lo encuentra y no publica otra vez.
    $fake = new class extends FakePublisher
    {
        public ?PublishRequest $seen = null;

        public function findPublished(SocialAccount $account, PublishRequest $request): ?PublishResult
        {
            $this->seen = $request;

            return parent::findPublished($account, $request);
        }
    };
    $fake->externals[$target->id] = 'EXT-OLD';
    $this->app->instance(ConnectorRegistry::class, (new ConnectorRegistry)->registerPublisher($network, $fake));
    $post->forceFill(['scheduled_at' => now()->subMinute(), 'text' => 'Texto NUEVO', 'status' => PostStatus::Scheduled])->save();
    PostTarget::withoutUserScope()->whereKey($target->id)->update(['status' => PostStatus::Scheduled->value]); // programar de nuevo el borrador

    app()->call([new PublishPostTarget($target->id), 'handle']);

    $t = $target->fresh();
    expect($fake->publishCalls)->toBe(0)->and($fake->findCalls)->toBe(1)
        ->and($fake->seen->reference)->toBe($expectedReference)->and($fake->seen->text)->toBe('Texto viejo')
        ->and($t->status)->toBe(PostStatus::Published)->and($t->external_id)->toBe('EXT-OLD')->and($t->status_note)->toContain('La edicion no se aplico');
})->with([
    'YouTube: subida en curso (se busca por el tag del destino)' => [Network::YouTube, ContentType::YouTubeVideo, 'subiendo', null],
    'YouTube: video ya creado' => [Network::YouTube, ContentType::YouTubeVideo, 'VID_OLD', 'VID_OLD'],
    'TikTok: publish_id' => [Network::TikTok, ContentType::TikTokVideo, 'v_pub_file~v2.123', 'v_pub_file~v2.123'],
]);

// ------------------------------------------------------------------ R3

test('R3: una carrera en failInactiveProcessing (el destino ya no esta procesando) no corta el resto', function () {
    Queue::fake();
    $account = SocialAccount::factory()->for($this->user)->create(['brand_id' => $this->brand->id, 'network' => Network::Instagram, 'status' => SocialAccount::STATUS_DISCONNECTED]);
    $targets = [];
    foreach ([1, 2] as $i) {
        $post = Post::factory()->create(['brand_id' => $this->brand->id, 'user_id' => $this->user->id, 'scheduled_at' => now()->subHour(), 'status' => PostStatus::Processing]);
        $targets[$i] = PostTarget::factory()->create([
            'post_id' => $post->id, 'user_id' => $this->user->id, 'social_account_id' => $account->id, 'content_type' => ContentType::InstagramImage,
            'status' => PostStatus::Processing, 'external_reference' => 'C'.$i, 'started_at' => now()->subHour(),
        ]);
    }
    // El primero se cierra "en paralelo" justo despues de que el recovery lo lea (la fila en memoria sigue `procesando`).
    $raced = false;
    PostTarget::retrieved(function (PostTarget $t) use (&$raced, $targets) {
        if (! $raced && $t->id === $targets[1]->id && $t->status === PostStatus::Processing) {
            $raced = true;
            PostTarget::withoutUserScope()->whereKey($t->id)->update(['status' => PostStatus::Failed->value]);
        }
    });

    $result = app(PublishRecovery::class)->run();

    expect($raced)->toBeTrue()->and($result['abandoned'])->toBe(1)
        ->and($targets[1]->fresh()->status)->toBe(PostStatus::Failed)
        ->and($targets[2]->fresh()->status)->toBe(PostStatus::Failed)->and($targets[2]->fresh()->error_code)->toBe('cuenta_no_disponible');
});

// ------------------------------------------------------------------ R4

test('R4: historia con ventana de busqueda de mas de 24 h: no se adopta la unica historia ajena, se queda con el contenedor', function () {
    $this->graph->on('GET', '#^C_STORY$#', ['status_code' => 'PUBLISHED'])
        ->on('GET', '#/stories$#', ['data' => [['id' => 'AJENA', 'permalink' => 'https://x/ajena', 'timestamp' => now()->subHours(2)->toIso8601String()]]]);
    $request = fn ($since) => new PublishRequest(5, ContentType::InstagramStory, null, [], [], 'C_STORY', $since);

    $old = (new InstagramPublisher)->findPublished($this->account, $request(now()->subHours(30)));
    expect($old->externalId)->toBe('C_STORY')->and($old->progress)->toBe(['external_id_kind' => 'container'])->and($old->note)->not->toBeNull();

    $recent = (new InstagramPublisher)->findPublished($this->account, $request(now()->subHours(3)));
    expect($recent->externalId)->toBe('AJENA'); // dentro de las 24 h la unica candidata sigue valiendo
});
