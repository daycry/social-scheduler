<?php

use App\Connectors\ConnectorRegistry;
use App\Connectors\Exceptions\CommentNotAllowedException;
use App\Connectors\Exceptions\PermanentPublishException;
use App\Connectors\Exceptions\ReconnectRequiredException;
use App\Connectors\Exceptions\TransientPublishException;
use App\Connectors\Network;
use App\Enums\FirstCommentStatus;
use App\Enums\PostStatus;
use App\Jobs\DispatchDuePosts;
use App\Jobs\PostFirstComment;
use App\Models\Brand;
use App\Models\Post;
use App\Models\PostTarget;
use App\Models\SocialAccount;
use App\Models\User;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Notification;
use Tests\Support\FakeCommenter;

// C-01b (CA-04, CA-05): el job comenta tras publicar, es idempotente y un fallo nunca degrada el destino.

beforeEach(function () {
    Carbon::setTestNow('2026-11-01 12:00:00');
    Notification::fake();
    config(['publishing.comment_max_attempts' => 3]);

    $this->user = User::factory()->create();
    $this->brand = Brand::factory()->for($this->user)->create();
    $this->account = SocialAccount::factory()->for($this->user)->create(['brand_id' => $this->brand->id, 'network' => Network::Instagram]);
    $this->net = new FakeCommenter;
    $this->app->instance(ConnectorRegistry::class, (new ConnectorRegistry)->registerPublisher(Network::Instagram, $this->net));
});

/** Destino ya publicado con primer comentario pendiente. */
function fcTarget(array $attrs = []): PostTarget
{
    $post = Post::factory()->create(['brand_id' => test()->brand->id, 'user_id' => test()->user->id, 'status' => PostStatus::Published]);

    return PostTarget::factory()->create(array_merge([
        'post_id' => $post->id, 'user_id' => test()->user->id, 'social_account_id' => test()->account->id,
        'status' => PostStatus::Published, 'external_id' => 'MEDIA1', 'first_comment' => 'Mas info en el enlace',
        'first_comment_status' => FirstCommentStatus::Pending, 'first_comment_attempts' => 0,
    ], $attrs));
}

function runFc(PostTarget $t): void
{
    app()->call([new PostFirstComment($t->id), 'handle']);
}

test('PostFirstComment es unico por destino y va en la cola de comentarios', function () {
    $job = new PostFirstComment(7);

    expect($job)->toBeInstanceOf(ShouldBeUnique::class)->and($job->uniqueId())->toBe('fc:7')
        ->and($job->queue)->toBe(config('publishing.comment_queue'));
});

test('comenta, guarda el id y deja el destino publicado', function () {
    $t = fcTarget();

    runFc($t);

    $t = $t->fresh();
    expect($t->first_comment_status)->toBe(FirstCommentStatus::Published)
        ->and($t->first_comment_external_id)->toBe('cm-MEDIA1-1')->and($t->first_comment_attempts)->toBe(1)
        ->and($t->first_comment_error)->toBeNull()->and($t->status)->toBe(PostStatus::Published)
        ->and($this->net->commentCalls)->toBe(1);
});

test('repetir el job no crea un segundo comentario (id guardado)', function () {
    $t = fcTarget();

    runFc($t);
    runFc($t);

    expect($this->net->commentCalls)->toBe(1)->and($t->fresh()->first_comment_attempts)->toBe(1);
});

test('si la red ya tiene el comentario propio, lo adopta sin crear otro', function () {
    $t = fcTarget();
    $this->net->comments['MEDIA1'][] = ['id' => 'cm-existente', 'text' => 'Mas info en el enlace'];

    runFc($t);

    expect($t->fresh()->first_comment_external_id)->toBe('cm-existente')->and($this->net->commentCalls)->toBe(0);
});

test('la ventana de «la red acepto y el worker murio» se cierra en el siguiente intento', function () {
    $t = fcTarget();
    $this->net->commentScript = ['sent-then-fail'];

    try {
        runFc($t); // la red crea el comentario, pero el job muere sin guardar el id
    } catch (RuntimeException) {
    }
    expect($t->fresh()->first_comment_status)->toBe(FirstCommentStatus::Commenting);

    Carbon::setTestNow(now()->addMinutes(15)); // colgado: pasa el plazo
    runFc($t);

    expect($t->fresh()->first_comment_status)->toBe(FirstCommentStatus::Published)
        ->and($this->net->commentCalls)->toBe(1)
        ->and($this->net->comments['MEDIA1'])->toHaveCount(1);
});

test('un comentario en curso reciente no se pisa', function () {
    $t = fcTarget(['first_comment_status' => FirstCommentStatus::Commenting, 'first_comment_at' => now()->subMinute()]);

    runFc($t);

    expect($this->net->commentCalls)->toBe(0)->and($this->net->findCommentCalls)->toBe(0);
});

test('un error transitorio vuelve a pendiente y el destino sigue publicado', function () {
    $t = fcTarget();
    $this->net->commentScript = [new TransientPublishException('Meta no disponible', 'meta')];

    runFc($t);

    $t = $t->fresh();
    expect($t->first_comment_status)->toBe(FirstCommentStatus::Pending)->and($t->first_comment_attempts)->toBe(1)
        ->and($t->status)->toBe(PostStatus::Published);
});

test('agotados los intentos queda fallido con motivo y el destino sigue publicado', function () {
    $t = fcTarget(['first_comment_attempts' => 2]);
    $this->net->commentScript = [new TransientPublishException('Meta no disponible', 'meta')];

    runFc($t);

    $t = $t->fresh();
    expect($t->first_comment_status)->toBe(FirstCommentStatus::Failed)->and($t->first_comment_error)->toContain('Meta no disponible')
        ->and($t->status)->toBe(PostStatus::Published)->and($t->error_code)->toBeNull();
});

test('un error permanente o de reconexion deja fallido sin tocar el destino', function (Throwable $e) {
    $t = fcTarget();
    $this->net->commentScript = [$e];

    runFc($t);

    $t = $t->fresh();
    expect($t->first_comment_status)->toBe(FirstCommentStatus::Failed)->and($t->first_comment_error)->toBe($e->getMessage())
        ->and($t->status)->toBe(PostStatus::Published);
})->with([
    'permanente' => fn () => new PermanentPublishException('Faltan permisos para comentar.', 'permisos'),
    'reconexion' => fn () => new ReconnectRequiredException('Reconecta el canal para comentar.'),
]);

test('comentarios cerrados deja no_admitido', function () {
    $t = fcTarget();
    $this->net->commentScript = [new CommentNotAllowedException('La publicacion tiene los comentarios cerrados.', 'comentarios_cerrados')];

    runFc($t);

    expect($t->fresh()->first_comment_status)->toBe(FirstCommentStatus::NotAllowed);
});

test('una red sin FirstCommenter (TikTok) deja no_admitido', function () {
    $account = SocialAccount::factory()->for($this->user)->create(['brand_id' => $this->brand->id, 'network' => Network::TikTok]);
    $t = fcTarget(['social_account_id' => $account->id, 'network' => Network::TikTok]);

    runFc($t);

    expect($t->fresh()->first_comment_status)->toBe(FirstCommentStatus::NotAllowed)->and($this->net->commentCalls)->toBe(0);
});

test('el destino que publica con comentario lo deja pendiente y lo encola desde published()', function () {
    $post = Post::factory()->create(['brand_id' => $this->brand->id, 'user_id' => $this->user->id, 'scheduled_at' => now()->subMinute(), 'status' => PostStatus::Scheduled]);
    $t = PostTarget::factory()->create(['post_id' => $post->id, 'user_id' => $this->user->id, 'social_account_id' => $this->account->id, 'first_comment' => 'Hola']);

    app(DispatchDuePosts::class)();

    $t = $t->fresh();
    expect($t->status)->toBe(PostStatus::Published)->and($t->first_comment_status)->toBe(FirstCommentStatus::Published)
        ->and($this->net->commentCalls)->toBe(1)->and($this->net->comments[$t->external_id][0]['text'])->toBe('Hola');
});

test('un destino sin primer comentario no genera estado ni job', function () {
    $post = Post::factory()->create(['brand_id' => $this->brand->id, 'user_id' => $this->user->id, 'scheduled_at' => now()->subMinute(), 'status' => PostStatus::Scheduled]);
    $t = PostTarget::factory()->create(['post_id' => $post->id, 'user_id' => $this->user->id, 'social_account_id' => $this->account->id]);

    app(DispatchDuePosts::class)();

    expect($t->fresh()->first_comment_status)->toBeNull()->and($this->net->commentCalls)->toBe(0);
});
