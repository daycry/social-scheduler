<?php

use App\Connectors\ConnectorRegistry;
use App\Connectors\Contracts\FirstCommenter;
use App\Connectors\Exceptions\CommentNotAllowedException;
use App\Connectors\Exceptions\CommentSearchInconclusiveException;
use App\Connectors\Exceptions\PermanentPublishException;
use App\Connectors\Exceptions\TransientPublishException;
use App\Connectors\Meta\Facebook\FacebookPublisher;
use App\Connectors\Meta\Instagram\InstagramPublisher;
use App\Connectors\Network;
use App\Models\Brand;
use App\Models\SocialAccount;
use App\Models\User;
use Tests\Support\FakeGraph;

// C-01b (CA-04): contrato FirstCommenter sobre Meta/Comments, con Graph simulado.

beforeEach(function () {
    config(['social.meta.graph_version' => 'v23.0']);
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
    $this->graph = new FakeGraph;
});

test('Instagram comenta y devuelve el id del comentario', function () {
    $this->graph->on('POST', '#^MEDIA1/comments$#', ['id' => 'C1']);

    $id = (new InstagramPublisher)->comment($this->ig, 'MEDIA1', 'Mas info en el enlace');

    expect($id)->toBe('C1')
        ->and($this->graph->callsTo('POST', '#/comments$#')[0]['params']['message'])->toBe('Mas info en el enlace')
        ->and($this->graph->callsTo('POST', '#/comments$#')[0]['authorization'])->toBe('Bearer tok-ig');
});

test('Facebook comenta sobre el id de la publicacion', function () {
    $this->graph->on('POST', '#^PAGE1_9/comments$#', ['id' => 'PAGE1_9_77']);

    expect((new FacebookPublisher)->comment($this->fb, 'PAGE1_9', 'Hola'))->toBe('PAGE1_9_77');
});

test('findOwnComment encuentra el propio por is_own y texto normalizado, y no el ajeno ni una respuesta', function () {
    $this->graph->on('GET', '#^MEDIA1/comments$#', ['data' => [
        ['id' => 'A', 'text' => 'Mas info en el enlace', 'username' => 'otro', 'timestamp' => '2026-11-01T10:00:00+0000'],
        ['id' => 'B', 'text' => "Mas  info\nen el enlace ", 'username' => 'MiMarca', 'timestamp' => '2026-11-01T10:01:00+0000'],
    ]]);

    expect((new InstagramPublisher)->findOwnComment($this->ig, 'MEDIA1', 'Mas info en el enlace'))->toBe('B')
        ->and((new InstagramPublisher)->findOwnComment($this->ig, 'MEDIA1', 'Otro texto'))->toBeNull();
});

test('findOwnComment devuelve null cuando la lista de Meta aun no muestra el comentario', function () {
    $this->graph->on('GET', '#^PAGE1_9/comments$#', ['data' => []]);

    expect((new FacebookPublisher)->findOwnComment($this->fb, 'PAGE1_9', 'Hola'))->toBeNull();
});

test('comentarios cerrados se mapea a CommentNotAllowedException', function () {
    $this->graph->on('POST', '#^MEDIA1/comments$#', FakeGraph::error(100, 'Comments are disabled for this media.', null, 400));

    expect(fn () => (new InstagramPublisher)->comment($this->ig, 'MEDIA1', 'x'))->toThrow(CommentNotAllowedException::class);
});

test('un error de red es transitorio y uno de permisos permanente, sin mapear a no_admitido', function () {
    $this->graph->on('POST', '#^MEDIA1/comments$#', FakeGraph::error(1, 'Unknown', null, 500));
    expect(fn () => (new InstagramPublisher)->comment($this->ig, 'MEDIA1', 'x'))->toThrow(TransientPublishException::class);

    $this->graph->on('POST', '#^MEDIA2/comments$#', FakeGraph::error(10, '(#10) Application does not have permission', null, 403));
    try {
        (new InstagramPublisher)->comment($this->ig, 'MEDIA2', 'x');
        $this->fail('debia lanzar');
    } catch (PermanentPublishException $e) {
        expect($e)->not->toBeInstanceOf(CommentNotAllowedException::class)->and($e->errorCode)->toBe('permisos');
    }
});

test('sin el permiso de comentar no se llama a Graph', function () {
    $this->ig->forceFill(['scopes' => []])->save();

    expect(fn () => (new InstagramPublisher)->comment($this->ig->fresh(), 'MEDIA1', 'x'))->toThrow(PermanentPublishException::class, 'permiso');
    expect($this->graph->calls)->toBe([]);
});

test('commenterFor devuelve IG y FB pero no TikTok', function () {
    $registry = app(ConnectorRegistry::class);

    expect($registry->commenterFor(Network::Instagram))->toBeInstanceOf(FirstCommenter::class)
        ->and($registry->commenterFor(Network::Facebook))->toBeInstanceOf(FirstCommenter::class)
        ->and($registry->commenterFor(Network::TikTok))->toBeNull();
});

test('findOwn es una busqueda acotada: solo nivel superior, sin campos anidados, ni mas paginas que el tope', function () {
    config(['social.first_comment.find_pages' => 3]);
    $this->graph->on('GET', '#^PAGE1_9/comments$#', FakeGraph::commentsPage(100, 0));

    expect(fn () => (new FacebookPublisher)->findOwnComment($this->fb, 'PAGE1_9', 'Hola'))->toThrow(CommentSearchInconclusiveException::class);

    $calls = $this->graph->callsTo('GET', '#comments$#');
    expect($calls)->toHaveCount(3) // tope de paginas, no 5 como el inbox
        ->and($calls[0]['params']['filter'])->toBe('toplevel')
        ->and($calls[0]['params']['fields'])->not->toContain('comments')->and($calls[0]['params']['limit'])->toBe('100');
    expect($this->graph->callsTo('GET', '#^(?!PAGE1_9/comments$)#'))->toBe([]); // ninguna pagina de respuestas
});

test('findOwn respeta el deadline: sin presupuesto para otra llamada corta tras la primera pagina', function () {
    config(['social.first_comment.find_budget_seconds' => 0, 'social.http_timeout' => 15]);
    $this->graph->on('GET', '#^MEDIA1/comments$#', FakeGraph::commentsPage(100, 0));

    expect(fn () => (new InstagramPublisher)->findOwnComment($this->ig, 'MEDIA1', 'Hola'))->toThrow(CommentSearchInconclusiveException::class);
    expect($this->graph->callsTo('GET', '#comments$#'))->toHaveCount(1);
});

test('findOwn devuelve null (concluyente) si la lista termina sin el comentario y no lanza', function () {
    $this->graph->on('GET', '#^PAGE1_9/comments$#', FakeGraph::commentsPage(5, 0, false));

    expect((new FacebookPublisher)->findOwnComment($this->fb, 'PAGE1_9', 'Hola'))->toBeNull();
});
