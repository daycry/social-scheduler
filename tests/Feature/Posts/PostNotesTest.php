<?php

use App\Connectors\Network;
use App\Enums\PostStatus;
use App\Models\AuditLog;
use App\Models\Brand;
use App\Models\Post;
use App\Models\SocialAccount;
use App\Models\User;
use App\Services\PostService;
use App\Services\PublishRequestFactory;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

// C-09 notas internas: PATCH posts/{post}/notes. Nunca se publican ni se auditan.

beforeEach(function () {
    Http::preventStrayRequests();
    Queue::fake();
    Carbon::setTestNow('2026-11-01 12:00:00');
    $this->user = User::factory()->create();
    $this->brand = Brand::factory()->for($this->user)->create(['timezone' => 'Europe/Madrid']);
    $this->fb = SocialAccount::factory()->for($this->user)->create(['brand_id' => $this->brand->id, 'network' => Network::Facebook]);
    $this->service = app(PostService::class);
});

function notesPost(array $over = []): Post
{
    return test()->service->create(test()->user, test()->brand, array_merge([
        'text' => 'Hola', 'schedule' => false, 'media' => [],
        'targets' => [['account_id' => test()->fb->id, 'content_type' => 'facebook_text']],
    ], $over));
}

test('PostNotes: guardar una nota en un post publicado o en vuelo no toca los destinos', function (PostStatus $status) {
    $post = notesPost();
    $post->targets->first()->forceFill(['status' => $status, 'attempts' => 3])->save();
    $before = $post->targets()->first()->only(['status', 'attempts', 'updated_at']);

    $this->actingAs($this->user)->patch(route('posts.notes', $post), ['notes' => "Linea 1\nLinea 2 <b>x</b>"])
        ->assertRedirect()->assertSessionHas('success');

    expect($post->fresh()->notes)->toBe("Linea 1\nLinea 2 <b>x</b>")
        ->and($post->targets()->first()->only(['status', 'attempts', 'updated_at']))->toEqual($before);
})->with([PostStatus::Publishing, PostStatus::Published]);

test('PostNotes: 5.000 caracteres entran y 5.001 se rechazan', function () {
    $post = notesPost();

    $this->actingAs($this->user)->patch(route('posts.notes', $post), ['notes' => str_repeat('a', 5000)])->assertSessionDoesntHaveErrors();
    expect(mb_strlen($post->fresh()->notes))->toBe(5000);

    $this->actingAs($this->user)->patch(route('posts.notes', $post), ['notes' => str_repeat('b', 5001)])->assertSessionHasErrors('notes');
    expect($post->fresh()->notes)->toBe(str_repeat('a', 5000));
});

test('PostNotes: vacio o solo espacios borra la nota', function () {
    $post = notesPost(['notes' => 'algo']);
    expect($post->notes)->toBe('algo');

    $this->actingAs($this->user)->patch(route('posts.notes', $post), ['notes' => '   '])->assertSessionDoesntHaveErrors();

    expect($post->fresh()->notes)->toBeNull();
});

test('PostNotes: un post ajeno es un 404 y no se modifica (IDOR)', function () {
    $post = notesPost(['notes' => 'mia']);
    $other = User::factory()->create();

    $this->actingAs($other)->patch(route('posts.notes', $post), ['notes' => 'hack'])->assertNotFound();

    expect($post->fresh()->notes)->toBe('mia');
});

test('PostNotes: requiere sesion', function () {
    $post = notesPost();

    $this->patch(route('posts.notes', $post), ['notes' => 'x'])->assertRedirect(route('login'));
});

test('PostNotes: las notas no cuentan como cambio de contenido y no reinician intentos', function () {
    $post = notesPost(['schedule' => true, 'scheduled_at' => '2026-11-09 10:00']);
    $post->targets->first()->forceFill(['attempts' => 2, 'error_code' => 'transitorio', 'status' => PostStatus::Scheduled])->save();

    $this->service->update($this->user, $post, [
        'text' => 'Hola', 'schedule' => true, 'scheduled_at' => '2026-11-09 10:00', 'media' => [], 'notes' => 'nueva',
        'targets' => [['account_id' => $this->fb->id, 'content_type' => 'facebook_text']],
    ]);

    expect($post->fresh()->notes)->toBe('nueva')->and((int) $post->targets()->first()->attempts)->toBe(2);

    $this->service->updateNotes($this->user, $post->fresh(), 'otra');
    expect((int) $post->targets()->first()->attempts)->toBe(2);
});

test('PostNotes: si la entrada no trae notas, update las conserva', function () {
    $post = notesPost(['notes' => 'conservar']);

    $this->service->update($this->user, $post, ['text' => 'Hola 2', 'schedule' => false, 'media' => [], 'targets' => [['account_id' => $this->fb->id, 'content_type' => 'facebook_text']]]);

    expect($post->fresh()->notes)->toBe('conservar');
});

test('PostNotes: las notas no llegan nunca al PublishRequest', function () {
    $post = notesPost(['notes' => 'SECRETO-INTERNO <script>']);
    $target = $post->targets()->first();

    $request = app(PublishRequestFactory::class)->make($target);

    expect(json_encode([$request->text, $request->options, $request->reference, $request->progress]))->not->toContain('SECRETO-INTERNO')
        ->and($request->text)->toBe('Hola');
});

test('PostNotes: ningun publisher lee las notas', function () {
    $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(app_path('Connectors')));
    $hits = [];
    foreach ($files as $f) {
        if ($f->isFile() && $f->getExtension() === 'php' && str_contains(file_get_contents($f->getPathname()), 'notes')) {
            $hits[] = $f->getPathname();
        }
    }

    expect($hits)->toBe([]);
});

test('PostNotes: el endpoint web no deja el contenido de las notas en la auditoria', function () {
    $post = notesPost();

    $this->actingAs($this->user)->patch(route('posts.notes', $post), ['notes' => 'CONTENIDO-PRIVADO'])->assertRedirect();

    expect(json_encode(AuditLog::all()->toArray()))->not->toContain('CONTENIDO-PRIVADO');
});

test('PostNotes: CRLF y CR suelto pasan a LF y las lineas en blanco se conservan', function () {
    $post = notesPost();

    $this->actingAs($this->user)->patch(route('posts.notes', $post), ['notes' => "Parrafo 1\r\n\r\nParrafo 2\rTres\n\nCuatro"])->assertSessionDoesntHaveErrors();

    expect($post->fresh()->notes)->toBe("Parrafo 1\n\nParrafo 2\nTres\n\nCuatro");
});

test('PostNotes: al crear el post por servicio tambien se normalizan las notas', function () {
    $post = notesPost(['notes' => "a\r\n\r\nb"]);

    expect($post->notes)->toBe("a\n\nb");
});
