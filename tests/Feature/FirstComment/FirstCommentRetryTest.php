<?php

use App\Connectors\ConnectorRegistry;
use App\Connectors\Exceptions\CommentNotAllowedException;
use App\Connectors\Exceptions\PermanentPublishException;
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
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Tests\Support\FakeCommenter;

// C-01b (CA-05): editar el texto de un comentario fallido, reintentar con el texto vigente, aviso in-app y propiedad.

beforeEach(function () {
    Carbon::setTestNow('2026-11-01 12:00:00');
    $this->user = User::factory()->create();
    $this->brand = Brand::factory()->for($this->user)->create();
    $this->account = SocialAccount::factory()->for($this->user)->create(['brand_id' => $this->brand->id, 'network' => Network::Instagram]);
    $this->net = new FakeCommenter;
    $this->app->instance(ConnectorRegistry::class, (new ConnectorRegistry)->registerPublisher(Network::Instagram, $this->net));
});

function frTarget(User $user, Brand $brand, SocialAccount $account, FirstCommentStatus $status = FirstCommentStatus::Failed, array $attrs = []): PostTarget
{
    $post = Post::factory()->create(['brand_id' => $brand->id, 'user_id' => $user->id, 'status' => PostStatus::Published]);

    return PostTarget::factory()->create(array_merge([
        'post_id' => $post->id, 'user_id' => $user->id, 'social_account_id' => $account->id, 'status' => PostStatus::Published,
        'external_id' => 'MEDIA1', 'text' => 'Texto publicado', 'attempts' => 1, 'first_comment' => 'Texto viejo',
        'first_comment_status' => $status, 'first_comment_attempts' => 3, 'first_comment_error' => 'Comentarios cerrados',
    ], $attrs));
}

function frUrl(PostTarget $t, string $suffix = ''): string
{
    return "/posts/{$t->post_id}/targets/{$t->id}/first-comment".$suffix;
}

test('editar un comentario fallido cambia solo el texto del comentario', function () {
    $t = frTarget($this->user, $this->brand, $this->account);

    $this->actingAs($this->user)->patch(frUrl($t), ['first_comment' => '  Texto nuevo  '])->assertRedirect()->assertSessionHasNoErrors();

    $t = $t->fresh();
    expect($t->first_comment)->toBe('Texto nuevo')->and($t->text)->toBe('Texto publicado')->and($t->attempts)->toBe(1)
        ->and($t->first_comment_status)->toBe(FirstCommentStatus::Failed)->and($t->status)->toBe(PostStatus::Published);
});

test('solo se edita un comentario fallido', function (FirstCommentStatus $status) {
    $t = frTarget($this->user, $this->brand, $this->account, $status);

    $this->actingAs($this->user)->patch(frUrl($t), ['first_comment' => 'Otro'])->assertSessionHasErrors('first_comment');

    expect($t->fresh()->first_comment)->toBe('Texto viejo');
})->with([FirstCommentStatus::Pending, FirstCommentStatus::Commenting, FirstCommentStatus::Published, FirstCommentStatus::NotAllowed]);

test('la edicion respeta el maximo de la red y no admite vacio', function () {
    $t = frTarget($this->user, $this->brand, $this->account);

    $this->actingAs($this->user)->patch(frUrl($t), ['first_comment' => str_repeat('a', 2201)])->assertSessionHasErrors('first_comment');
    $this->actingAs($this->user)->patch(frUrl($t), ['first_comment' => '   '])->assertSessionHasErrors('first_comment');

    expect($t->fresh()->first_comment)->toBe('Texto viejo');
});

test('reintentar reinicia los intentos, despacha el job y comenta con el texto vigente', function () {
    $t = frTarget($this->user, $this->brand, $this->account);
    $this->actingAs($this->user)->patch(frUrl($t), ['first_comment' => 'Texto editado']);

    $this->actingAs($this->user)->post(frUrl($t, '/retry'))->assertRedirect()->assertSessionHasNoErrors();

    $t = $t->fresh();
    expect($t->first_comment_status)->toBe(FirstCommentStatus::Published)->and($t->first_comment_attempts)->toBe(1)
        ->and($t->first_comment_error)->toBeNull()->and($this->net->comments['MEDIA1'][0]['text'])->toBe('Texto editado');
});

test('reintentar encola el job y deja el comentario pendiente', function () {
    Queue::fake();
    $t = frTarget($this->user, $this->brand, $this->account);

    $this->actingAs($this->user)->post(frUrl($t, '/retry'))->assertRedirect();

    Queue::assertPushed(PostFirstComment::class, fn ($job) => $job->targetId === $t->id);
    expect($t->fresh()->first_comment_status)->toBe(FirstCommentStatus::Pending)->and($t->fresh()->first_comment_attempts)->toBe(0);
});

test('no se reintenta lo que no ha fallado', function () {
    Queue::fake();
    $t = frTarget($this->user, $this->brand, $this->account, FirstCommentStatus::Published, ['first_comment_external_id' => 'cm-1']);

    $this->actingAs($this->user)->post(frUrl($t, '/retry'))->assertSessionHasErrors('first_comment');

    Queue::assertNothingPushed();
});

test('un destino ajeno da 403/404 y no cambia', function () {
    $other = User::factory()->create();
    $t = frTarget($this->user, $this->brand, $this->account);

    foreach ([fn () => $this->actingAs($other)->patch(frUrl($t), ['first_comment' => 'x']), fn () => $this->actingAs($other)->post(frUrl($t, '/retry'))] as $call) {
        expect($call()->getStatusCode())->toBeIn([403, 404]);
    }
    expect($t->fresh()->first_comment)->toBe('Texto viejo')->and($t->fresh()->first_comment_status)->toBe(FirstCommentStatus::Failed);
});

test('un destino de otro post no se alcanza por la ruta de este', function () {
    $a = frTarget($this->user, $this->brand, $this->account);
    $b = frTarget($this->user, $this->brand, $this->account);

    $this->actingAs($this->user)->patch("/posts/{$a->post_id}/targets/{$b->id}/first-comment", ['first_comment' => 'x'])->assertNotFound();
});

test('un fallo definitivo del comentario crea el aviso in-app y no el de no_admitido', function () {
    Notification::fake();
    $t = frTarget($this->user, $this->brand, $this->account, FirstCommentStatus::Pending, ['first_comment_attempts' => 0]);
    $this->net->commentScript = [new PermanentPublishException('Faltan permisos para comentar.', 'permisos')];

    app()->call([new PostFirstComment($t->id), 'handle']);

    Notification::assertSentTo($this->user, FirstCommentFailed::class, function ($n) {
        $data = $n->toArray($this->user);

        return $n->via($this->user) === ['database'] && $data['type'] === 'first_comment_failed' && $data['message'] === 'Faltan permisos para comentar.';
    });
});

test('comentarios cerrados (no_admitido) no genera aviso', function () {
    Notification::fake();
    $t = frTarget($this->user, $this->brand, $this->account, FirstCommentStatus::Pending, ['first_comment_attempts' => 0]);
    $this->net->commentScript = [new CommentNotAllowedException('Cerrados.', 'comentarios_cerrados')];

    app()->call([new PostFirstComment($t->id), 'handle']);

    Notification::assertNothingSent();
});
