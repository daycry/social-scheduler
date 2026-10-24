<?php

use App\Connectors\ConnectorRegistry;
use App\Connectors\Data\PublishResult;
use App\Connectors\Google\YouTubePublisher;
use App\Connectors\Google\YouTubeQuota;
use App\Connectors\Meta\Facebook\FacebookPublisher;
use App\Connectors\Meta\Instagram\InstagramPublisher;
use App\Connectors\Network;
use App\Enums\FirstCommentStatus;
use App\Enums\PostStatus;
use App\Jobs\PostFirstComment;
use App\Models\Brand;
use App\Models\Post;
use App\Models\PostTarget;
use App\Models\SocialAccount;
use App\Models\User;
use App\Notifications\FirstCommentFailed;
use App\Services\FirstCommentService;
use App\Services\PublishOutcomes;
use App\Services\PublishRecovery;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Tests\Support\FakeGraph;
use Tests\Support\FakeHttp;

// C-01b (CA-04..CA-07): primer comentario de extremo a extremo con los conectores REALES de Instagram, Facebook y YouTube y la red
// simulada (FakeGraph / FakeHttp). Nunca sale a internet.

const FC_YT_THREADS = '#googleapis\.com/youtube/v3/commentThreads#';

beforeEach(function () {
    Carbon::setTestNow('2026-11-01 12:00:00');
    Cache::flush();
    Notification::fake();
    config(['social.meta.graph_version' => 'v23.0', 'publishing.comment_max_attempts' => 3, 'social.youtube.daily_quota' => 10000]);
    $this->user = User::factory()->create();
    $this->brand = Brand::factory()->for($this->user)->create();
    $this->ig = SocialAccount::factory()->for($this->user)->create([
        'brand_id' => $this->brand->id, 'network' => Network::Instagram, 'external_id' => 'IGU1', 'username' => 'mimarca',
        'access_token' => 'tok-ig', 'scopes' => ['instagram_manage_comments'],
    ]);
    $this->fb = SocialAccount::factory()->for($this->user)->create([
        'brand_id' => $this->brand->id, 'network' => Network::Facebook, 'external_id' => 'PAGE1',
        'access_token' => 'tok-fb', 'scopes' => ['pages_manage_engagement', 'pages_read_user_content'],
    ]);
    $this->yt = SocialAccount::factory()->for($this->user)->create([
        'brand_id' => $this->brand->id, 'network' => Network::YouTube, 'external_id' => 'UCmio', 'access_token' => 'tok-yt',
        'scopes' => ['https://www.googleapis.com/auth/youtube', 'https://www.googleapis.com/auth/youtube.force-ssl'],
    ]);
    $this->app->instance(ConnectorRegistry::class, (new ConnectorRegistry)
        ->registerPublisher(Network::Instagram, new InstagramPublisher)
        ->registerPublisher(Network::Facebook, new FacebookPublisher)
        ->registerPublisher(Network::YouTube, new YouTubePublisher));
});

/** Destino en `procesando`/`programada` listo para que `published()` lo cierre, con su primer comentario. */
function flowTarget(SocialAccount $account, string $type, string $comment = 'Mas info en el enlace'): PostTarget
{
    $post = Post::factory()->create(['brand_id' => test()->brand->id, 'user_id' => test()->user->id, 'status' => PostStatus::Publishing]);

    return PostTarget::factory()->create([
        'post_id' => $post->id, 'user_id' => test()->user->id, 'social_account_id' => $account->id, 'network' => $account->network,
        'content_type' => $type, 'status' => PostStatus::Publishing, 'attempts' => 1, 'started_at' => now(), 'first_comment' => $comment,
    ]);
}

function flowPublish(PostTarget $t, string $externalId): PostTarget
{
    return app(PublishOutcomes::class)->published($t, PublishResult::published($externalId, 'https://x.test/p'));
}

function flowRun(PostTarget $t): void
{
    app()->call([new PostFirstComment($t->id), 'handle']);
}

test('Instagram: al publicar comenta una vez y publicar otra vez el job no duplica aunque la lista de Meta aun no lo muestre', function () {
    $graph = (new FakeGraph)
        ->on('GET', '#^MEDIA1/comments$#', ['data' => []]) // «creado pero no listado»: la lista nunca lo enseña
        ->on('POST', '#^MEDIA1/comments$#', ['id' => 'C1']);
    $t = flowTarget($this->ig, 'instagram_image');

    flowPublish($t, 'MEDIA1'); // published() encola y, con la cola sincrona, comenta
    $t = $t->fresh();
    expect($t->status)->toBe(PostStatus::Published)->and($t->first_comment_status)->toBe(FirstCommentStatus::Published)
        ->and($t->first_comment_external_id)->toBe('C1');

    flowRun($t);
    flowRun($t);

    expect($graph->callsTo('POST', '#/comments$#'))->toHaveCount(1);
});

test('Facebook: el comentario propio ya existente en la red se adopta sin crear otro', function () {
    $graph = (new FakeGraph)
        ->on('GET', '#^PAGE1_9/comments$#', ['data' => [['id' => 'PAGE1_9_5', 'message' => 'Mas info en el enlace', 'from' => ['id' => 'PAGE1', 'name' => 'Mi pagina'], 'created_time' => '2026-11-01T11:59:00+0000']]])
        ->on('POST', '#/comments$#', ['id' => 'NO']);
    $t = flowTarget($this->fb, 'facebook_photo');

    flowPublish($t, 'PAGE1_9');

    expect($t->fresh()->first_comment_external_id)->toBe('PAGE1_9_5')->and($graph->callsTo('POST', '#/comments$#'))->toBe([]);
});

test('Facebook: un comentario ajeno con el mismo texto no cuenta como propio', function () {
    $graph = (new FakeGraph)
        ->on('GET', '#^PAGE1_9/comments$#', ['data' => [['id' => 'X1', 'message' => 'Mas info en el enlace', 'from' => ['id' => 'OTRO', 'name' => 'Otro'], 'created_time' => '2026-11-01T11:59:00+0000']]])
        ->on('POST', '#^PAGE1_9/comments$#', ['id' => 'PAGE1_9_6']);
    $t = flowTarget($this->fb, 'facebook_photo');

    flowPublish($t, 'PAGE1_9');

    expect($t->fresh()->first_comment_external_id)->toBe('PAGE1_9_6')->and($graph->callsTo('POST', '#/comments$#'))->toHaveCount(1);
});

test('YouTube: comenta tras publicar, una sola vez, y gasta 50 + 2 unidades de cuota (insertar + busqueda y pagina reciente)', function () {
    $http = (new FakeHttp)
        ->on('GET', FC_YT_THREADS, ['status' => 200, 'json' => ['items' => []]])
        ->on('POST', FC_YT_THREADS, ['status' => 200, 'json' => ['id' => 'Ugx1', 'snippet' => ['topLevelComment' => ['id' => 'Ugx1']]]]);
    $t = flowTarget($this->yt, 'youtube_video');

    flowPublish($t, 'VID1');
    flowRun($t->fresh());

    $t = $t->fresh();
    expect($t->first_comment_status)->toBe(FirstCommentStatus::Published)->and($t->first_comment_external_id)->toBe('Ugx1')
        ->and($http->callsTo('POST', FC_YT_THREADS))->toHaveCount(1)
        ->and((new YouTubeQuota)->used())->toBe(52);
});

test('error de red: vuelve a pendiente, el destino sigue publicado y el siguiente intento comenta', function () {
    $graph = (new FakeGraph)
        ->on('GET', '#^MEDIA1/comments$#', ['data' => []])
        ->on('POST', '#^MEDIA1/comments$#', FakeGraph::seq(new ConnectionException('cURL error 28: timeout'), ['id' => 'C9']));
    $t = flowTarget($this->ig, 'instagram_image');

    flowPublish($t, 'MEDIA1');
    $t = $t->fresh();
    expect($t->first_comment_status)->toBe(FirstCommentStatus::Pending)->and($t->status)->toBe(PostStatus::Published)->and($t->first_comment_attempts)->toBe(1);

    flowRun($t);

    expect($t->fresh()->first_comment_status)->toBe(FirstCommentStatus::Published)->and($t->fresh()->first_comment_external_id)->toBe('C9')
        ->and($graph->callsTo('POST', '#/comments$#'))->toHaveCount(2);
});

test('error de red repetido: agota los intentos, queda fallido con aviso y el destino sigue publicado', function () {
    (new FakeGraph)
        ->on('GET', '#^MEDIA1/comments$#', ['data' => []])
        ->on('POST', '#^MEDIA1/comments$#', new ConnectionException('cURL error 28: timeout'));
    $t = flowTarget($this->ig, 'instagram_image');

    flowPublish($t, 'MEDIA1');
    flowRun($t->fresh());
    flowRun($t->fresh());

    $t = $t->fresh();
    expect($t->first_comment_status)->toBe(FirstCommentStatus::Failed)->and($t->first_comment_attempts)->toBe(3)
        ->and($t->first_comment_error)->toContain('3 intentos')->and($t->status)->toBe(PostStatus::Published);
    Notification::assertSentTo($this->user, FirstCommentFailed::class);
});

test('reconexion de YouTube: sin el permiso queda fallido sin llamar a la API y, reconectado, Reintentar comenta', function () {
    $http = (new FakeHttp)
        ->on('GET', FC_YT_THREADS, ['status' => 200, 'json' => ['items' => []]])
        ->on('POST', FC_YT_THREADS, ['status' => 200, 'json' => ['id' => 'Ugx2']]);
    $this->yt->forceFill(['scopes' => ['https://www.googleapis.com/auth/youtube']])->save();
    $t = flowTarget($this->yt, 'youtube_video');

    flowPublish($t, 'VID1');
    $t = $t->fresh();
    expect($t->first_comment_status)->toBe(FirstCommentStatus::Failed)->and($t->first_comment_error)->toContain('Reconecta')
        ->and($http->calls)->toBe([])->and($t->status)->toBe(PostStatus::Published);

    // El usuario reconecta (el permiso llega) y reintenta desde la web.
    $this->yt->forceFill(['scopes' => ['https://www.googleapis.com/auth/youtube', 'https://www.googleapis.com/auth/youtube.force-ssl']])->save();
    $this->actingAs($this->user)->post("/posts/{$t->post_id}/targets/{$t->id}/first-comment/retry")->assertRedirect();

    expect($t->fresh()->first_comment_status)->toBe(FirstCommentStatus::Published)->and($t->fresh()->first_comment_external_id)->toBe('Ugx2');
});

test('comentarios cerrados en Instagram: no_admitido sin aviso y sin reintentos', function () {
    $graph = (new FakeGraph)
        ->on('GET', '#^MEDIA1/comments$#', ['data' => []])
        ->on('POST', '#^MEDIA1/comments$#', FakeGraph::error(100, 'Comments are disabled for this media.'));
    $t = flowTarget($this->ig, 'instagram_image');

    flowPublish($t, 'MEDIA1');

    expect($t->fresh()->first_comment_status)->toBe(FirstCommentStatus::NotAllowed)->and($graph->callsTo('POST', '#/comments$#'))->toHaveCount(1);
    Notification::assertNothingSent();
});

test('editar el comentario fallido y reintentar envia el texto nuevo', function () {
    $graph = (new FakeGraph)
        ->on('GET', '#^MEDIA1/comments$#', ['data' => []])
        ->on('POST', '#^MEDIA1/comments$#', FakeGraph::seq(FakeGraph::error(10, '(#10) Application does not have permission', null, 403), ['id' => 'C2']));
    $t = flowTarget($this->ig, 'instagram_image', 'Texto viejo');

    flowPublish($t, 'MEDIA1');
    expect($t->fresh()->first_comment_status)->toBe(FirstCommentStatus::Failed);

    $this->actingAs($this->user)->patch("/posts/{$t->post_id}/targets/{$t->id}/first-comment", ['first_comment' => 'Texto nuevo'])->assertSessionHasNoErrors();
    $this->actingAs($this->user)->post("/posts/{$t->post_id}/targets/{$t->id}/first-comment/retry")->assertSessionHasNoErrors();

    expect($t->fresh()->first_comment_status)->toBe(FirstCommentStatus::Published)
        ->and($graph->callsTo('POST', '#/comments$#')[1]['params']['message'])->toBe('Texto nuevo');
});

test('un comentario que se cuelga una y otra vez no se reintenta sin fin: agotado, solo busca el propio y si no esta queda fallido', function () {
    $graph = (new FakeGraph)->on('GET', '#^MEDIA1/comments$#', ['data' => []])->on('POST', '#^MEDIA1/comments$#', ['id' => 'NO']);
    $t = flowTarget($this->ig, 'instagram_image');
    $t->forceFill(['status' => PostStatus::Published, 'external_id' => 'MEDIA1', 'first_comment_status' => FirstCommentStatus::Commenting, 'first_comment_attempts' => 3, 'first_comment_at' => now()->subMinutes(30)])->save();

    flowRun($t);

    $t = $t->fresh();
    expect($t->first_comment_status)->toBe(FirstCommentStatus::Failed)->and($t->first_comment_error)->toContain('colgado')
        ->and($t->first_comment_attempts)->toBe(3)->and($graph->callsTo('POST', '#/comments$#'))->toBe([]);
    Notification::assertSentTo($this->user, FirstCommentFailed::class);
});

test('un comentario colgado y agotado que la red SI tiene se adopta sin fallar ni duplicar', function () {
    $graph = (new FakeGraph)
        ->on('GET', '#^MEDIA1/comments$#', ['data' => [['id' => 'C7', 'text' => 'Mas info en el enlace', 'username' => 'mimarca', 'timestamp' => '2026-11-01T11:50:00+0000']]])
        ->on('POST', '#^MEDIA1/comments$#', ['id' => 'NO']);
    $t = flowTarget($this->ig, 'instagram_image');
    $t->forceFill(['status' => PostStatus::Published, 'external_id' => 'MEDIA1', 'first_comment_status' => FirstCommentStatus::Commenting, 'first_comment_attempts' => 3, 'first_comment_at' => now()->subMinutes(30)])->save();

    flowRun($t);

    expect($t->fresh()->first_comment_status)->toBe(FirstCommentStatus::Published)->and($t->fresh()->first_comment_external_id)->toBe('C7')
        ->and($graph->callsTo('POST', '#/comments$#'))->toBe([]);
});

test('editar el comentario mientras el job comenta no se admite y no se pisa el texto en vuelo', function () {
    $t = flowTarget($this->ig, 'instagram_image');
    $t->forceFill(['status' => PostStatus::Published, 'external_id' => 'MEDIA1', 'first_comment_status' => FirstCommentStatus::Commenting, 'first_comment_attempts' => 1, 'first_comment_at' => now()])->save();

    $this->actingAs($this->user)->patch("/posts/{$t->post_id}/targets/{$t->id}/first-comment", ['first_comment' => 'Otro texto'])->assertSessionHasErrors('first_comment');
    $this->actingAs($this->user)->post("/posts/{$t->post_id}/targets/{$t->id}/first-comment/retry")->assertSessionHasErrors('first_comment');

    expect($t->fresh()->first_comment)->toBe('Mas info en el enlace')->and($t->fresh()->first_comment_status)->toBe(FirstCommentStatus::Commenting);
});

test('un fallo al guardar el id tras crear el comentario se cierra en la recuperacion sin duplicar', function () {
    $graph = (new FakeGraph)
        ->on('GET', '#^MEDIA1/comments$#', ['data' => [['id' => 'C5', 'text' => 'Mas info en el enlace', 'username' => 'mimarca', 'timestamp' => '2026-11-01T12:00:00+0000']]])
        ->on('POST', '#^MEDIA1/comments$#', ['id' => 'C5']);
    $t = flowTarget($this->ig, 'instagram_image');
    $t->forceFill(['status' => PostStatus::Published, 'external_id' => 'MEDIA1', 'first_comment_status' => FirstCommentStatus::Pending, 'first_comment_attempts' => 0])->save();

    // La red acepta el comentario, pero el worker muere antes de guardar el id: el destino queda `comentando`.
    PostTarget::withoutUserScope()->whereKey($t->id)->update(['first_comment_status' => FirstCommentStatus::Commenting->value, 'first_comment_attempts' => 1, 'first_comment_at' => now()->subMinutes(30)]);

    expect(app(PublishRecovery::class)->run()['comments'])->toBe(1);

    $t = $t->fresh();
    expect($t->first_comment_status)->toBe(FirstCommentStatus::Published)->and($t->first_comment_external_id)->toBe('C5')
        ->and($graph->callsTo('POST', '#/comments$#'))->toBe([]);
});

test('YouTube: comentarios desactivados o video no encontrado al buscar el propio dejan no_admitido, sin aviso ni insertar', function (int $status, string $reason) {
    $http = (new FakeHttp)
        ->on('GET', FC_YT_THREADS, ['status' => $status, 'json' => ['error' => ['code' => $status, 'message' => 'x', 'errors' => [['reason' => $reason]]]]])
        ->on('POST', FC_YT_THREADS, ['status' => 200, 'json' => ['id' => 'NO']]);
    $t = flowTarget($this->yt, 'youtube_video');

    flowPublish($t, 'VID1'); // published() encola el job real y, con la cola sincrona, busca y falla en commentThreads.list

    $t = $t->fresh();
    expect($t->status)->toBe(PostStatus::Published)->and($t->first_comment_status)->toBe(FirstCommentStatus::NotAllowed)
        ->and($t->first_comment_error)->toContain('no admite comentarios')->and($t->first_comment_error)->not->toContain('Reconecta')
        ->and($http->callsTo('POST', FC_YT_THREADS))->toBe([])
        ->and((new YouTubeQuota)->used())->toBe(0);
    Notification::assertNothingSent();
})->with([[403, 'commentsDisabled'], [404, 'videoNotFound']]);

/** Destino de Facebook publicado y listo para que el job lo reclame, con `attempts` intentos ya gastados. */
function flowPublishedFb(int $attempts, FirstCommentStatus $status = FirstCommentStatus::Pending, array $extra = []): PostTarget
{
    $t = flowTarget(test()->fb, 'facebook_photo');
    $t->forceFill(array_merge(['status' => PostStatus::Published, 'external_id' => 'PAGE1_9', 'first_comment_status' => $status, 'first_comment_attempts' => $attempts], $extra))->save();

    return $t;
}

function flowBusyPost(FakeGraph $graph): void
{
    $graph->on('GET', '#^PAGE1_9/comments$#', FakeGraph::commentsPage(100, 0)); // siempre hay otra pagina y ninguna es nuestra
}

test('busqueda inconclusa en un REINTENTO: no crea el comentario a ciegas, reintenta y agotado queda fallido', function () {
    $graph = (new FakeGraph)->on('POST', '#^PAGE1_9/comments$#', ['id' => 'NO']);
    flowBusyPost($graph);
    $t = flowPublishedFb(1, extra: ['first_comment_sent_at' => now()->subMinute()]); // un envio previo pudo haber ocurrido

    flowRun($t);

    $t = $t->fresh();
    expect($t->first_comment_status)->toBe(FirstCommentStatus::Pending)->and($t->first_comment_error)->toContain('demasiados comentarios')
        ->and($graph->callsTo('POST', '#/comments$#'))->toBe([]);

    // Agotado (colgado en el ultimo intento): no hay bucle cada 10 min, cierra fallido con aviso y sigue sin crear.
    $t->forceFill(['first_comment_status' => FirstCommentStatus::Commenting, 'first_comment_attempts' => 3, 'first_comment_at' => now()->subMinutes(30)])->save();
    flowRun($t->fresh());

    $t = $t->fresh();
    expect($t->first_comment_status)->toBe(FirstCommentStatus::Failed)->and($graph->callsTo('POST', '#/comments$#'))->toBe([]);
    Notification::assertSentTo($this->user, FirstCommentFailed::class);
});

test('busqueda inconclusa en el PRIMER intento: nada pudo enviarse antes, asi que crea el comentario', function () {
    $graph = (new FakeGraph)->on('POST', '#^PAGE1_9/comments$#', ['id' => 'PAGE1_9_1']);
    flowBusyPost($graph);
    $t = flowPublishedFb(0);

    flowRun($t);

    expect($t->fresh()->first_comment_status)->toBe(FirstCommentStatus::Published)->and($graph->callsTo('POST', '#/comments$#'))->toHaveCount(1);
});

test('timeout del POST que la red si creo + busqueda inconclusa: ni los reintentos ni Reintentar duplican (un solo POST)', function () {
    Queue::fake();
    $graph = (new FakeGraph)->on('POST', '#^PAGE1_9/comments$#', FakeGraph::seq(new ConnectionException('timeout tras crear'), ['id' => 'C2'], ['id' => 'C3']));
    flowBusyPost($graph);
    $t = flowPublishedFb(0);

    flowRun($t); // intento 1: nada pudo enviarse antes, crea; el POST da timeout
    expect($t->fresh()->first_comment_sent_at)->not->toBeNull();
    flowRun($t->fresh());
    flowRun($t->fresh());
    expect($t->fresh()->first_comment_status)->toBe(FirstCommentStatus::Failed)->and($t->fresh()->first_comment_error)->toContain('Publicar igualmente');

    app(FirstCommentService::class)->retry($t->fresh());
    expect($t->fresh()->first_comment_sent_at)->not->toBeNull(); // Reintentar no borra la evidencia
    flowRun($t->fresh());

    expect($graph->callsTo('POST', '#/comments$#'))->toHaveCount(1)
        ->and($t->fresh()->first_comment_status)->not->toBe(FirstCommentStatus::Published);
});

test('«Publicar igualmente» (force) crea el comentario aunque la busqueda siga inconclusa', function () {
    Queue::fake();
    $graph = (new FakeGraph)->on('POST', '#^PAGE1_9/comments$#', ['id' => 'PAGE1_9_7']);
    flowBusyPost($graph);
    $t = flowPublishedFb(3, FirstCommentStatus::Failed, ['first_comment_sent_at' => now()->subHour()]);

    $this->actingAs($this->user)->post("/posts/{$t->post_id}/targets/{$t->id}/first-comment/retry", ['force' => true])->assertRedirect()->assertSessionHasNoErrors();
    Queue::assertPushed(PostFirstComment::class, fn ($j) => $j->targetId === $t->id && $j->force === true);

    app()->call([new PostFirstComment($t->id, true), 'handle']);

    expect($t->fresh()->first_comment_status)->toBe(FirstCommentStatus::Published)->and($t->fresh()->first_comment_external_id)->toBe('PAGE1_9_7')
        ->and($graph->callsTo('POST', '#/comments$#'))->toHaveCount(1);
});

test('«Ya esta publicado» cierra como publicado sin llamar a la red', function () {
    Queue::fake();
    Http::fake();
    $t = flowPublishedFb(3, FirstCommentStatus::Failed, ['first_comment_sent_at' => now()->subHour(), 'first_comment_error' => 'busqueda inconclusa']);

    $this->actingAs($this->user)->post("/posts/{$t->post_id}/targets/{$t->id}/first-comment/published")->assertRedirect()->assertSessionHasNoErrors();

    $t = $t->fresh();
    expect($t->first_comment_status)->toBe(FirstCommentStatus::Published)->and($t->first_comment_error)->toBeNull()->and($t->status)->toBe(PostStatus::Published);
    Http::assertNothingSent();
    Queue::assertNothingPushed();

    // Solo vale sobre un comentario fallido.
    $this->actingAs($this->user)->post("/posts/{$t->post_id}/targets/{$t->id}/first-comment/published")->assertSessionHasErrors('first_comment');
});

test('«Publicar igualmente» solo salta la guarda en el primer envio: tras timeout y release vale la regla normal', function () {
    Queue::fake();
    $graph = (new FakeGraph)->on('POST', '#^PAGE1_9/comments$#', FakeGraph::seq(new ConnectionException('timeout tras crear'), ['id' => 'NO1'], ['id' => 'NO2']));
    flowBusyPost($graph);
    $t = flowPublishedFb(3, FirstCommentStatus::Failed, ['first_comment_sent_at' => now()->subHour()]);
    app(FirstCommentService::class)->retry($t->fresh(), true);
    $job = new PostFirstComment($t->id, true);

    for ($i = 0; $i < 3; $i++) {
        app()->call([unserialize(serialize($job)), 'handle']);
    }

    expect($graph->callsTo('POST', '#/comments$#'))->toHaveCount(1)->and($t->fresh()->first_comment_external_id)->toBeNull();
});
