<?php

use App\Models\Brand;
use App\Models\MediaAsset;
use App\Models\Studio\Design;
use App\Models\Studio\DesignLink;
use App\Models\Studio\DesignRevision;
use App\Models\User;
use App\Notifications\StudioDesignActivity;
use App\Services\Studio\StudioDocument;
use App\Services\Studio\StudioService;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;
use Tests\Support\McpClient;

uses(McpClient::class);

beforeEach(function () {
    if (! Route::has('studio.index') && is_file(base_path('routes/studio.php'))) {
        Route::middleware('web')->group(base_path('routes/studio.php'));
    }
    if (! Route::has('api.studio.designs.index') && is_file(base_path('routes/studio-api.php'))) {
        Route::middleware('api')->group(base_path('routes/studio-api.php'));
    }
    Route::getRoutes()->refreshNameLookups();
    Route::getRoutes()->refreshActionLookups();
    Storage::fake('local');
    Mail::fake();
    $this->owner = User::factory()->create(['name' => 'Olga Propietaria']);
    $this->brand = Brand::factory()->for($this->owner)->create();
    $this->reader = User::factory()->create(['name' => 'Luis Lector']);
    $this->commenter = User::factory()->create(['name' => 'Carla Comentarista']);
    $this->editor = User::factory()->create(['name' => 'Edgar Editor']);
    $this->stranger = User::factory()->create(['name' => 'Sara Ajena']);
    $this->design = app(StudioService::class)->create($this->owner, $this->brand, ['name' => 'Campaña compartida', 'document' => collabDocument()]);
    $this->base = '/studio/designs/'.$this->design->id;
});

function collabDocument(string $text = 'Hola'): array
{
    $doc = StudioDocument::blank(1080, 1080);
    $doc['pages'][0]['elements'] = [['id' => 'title', 'type' => 'text', 'x' => 20, 'y' => 30, 'width' => 600, 'height' => 100, 'text' => $text, 'fill' => '#111827', 'fontFamily' => 'Arial', 'fontSize' => 64]];

    return $doc;
}

function collabShare(object $test, User $guest, string $role): void
{
    $test->actingAs($test->owner)->postJson($test->base.'/shares', ['email' => $guest->email, 'role' => $role])->assertSuccessful();
}

function collabAs(object $test, User $user): void
{
    app('auth')->forgetGuards();
    $test->actingAs($user);
}

test('Colaboración: el propietario comparte por email, cambia el rol y revoca con efecto inmediato', function () {
    collabShare($this, $this->reader, 'reader');
    $this->getJson($this->base.'/shares')->assertOk()->assertJsonPath('shares.0.email', $this->reader->email)->assertJsonPath('shares.0.role', 'reader');
    collabAs($this, $this->reader);
    $this->getJson($this->base)->assertOk()->assertJsonPath('design.role', 'reader')->assertJsonPath('access.can_edit', false);
    $this->patchJson($this->base, ['name' => 'X', 'document' => collabDocument(), 'revision' => 1])->assertForbidden();
    collabAs($this, $this->owner);
    $share = $this->getJson($this->base.'/shares')->json('shares.0.id');
    $this->patchJson($this->base.'/shares/'.$share, ['role' => 'editor'])->assertOk()->assertJsonPath('share.role', 'editor');
    collabAs($this, $this->reader);
    $this->patchJson($this->base, ['name' => 'Ahora editor', 'document' => collabDocument('Nuevo'), 'revision' => 1])->assertOk()->assertJsonPath('design.revision', 2);
    collabAs($this, $this->owner);
    $this->deleteJson($this->base.'/shares/'.$share)->assertOk();
    collabAs($this, $this->reader);
    $this->getJson($this->base)->assertNotFound();
    $this->patchJson($this->base, ['name' => 'Otra', 'document' => collabDocument(), 'revision' => 2])->assertNotFound();
    $this->getJson($this->base.'/comments')->assertNotFound();
    expect($this->reader->notifications->map(fn ($n) => $n->data['type'])->all())->toContain('studio_design_shared', 'studio_design_role', 'studio_design_revoked');
    Mail::assertNothingSent();
});

test('Colaboración: valida rol y email, y la respuesta no revela si una cuenta existe', function () {
    $this->actingAs($this->owner);
    $message = 'Si existe una cuenta con ese email, tendrá acceso a este diseño.';
    $missing = $this->postJson($this->base.'/shares', ['email' => 'nadie@example.test', 'role' => 'reader'])->assertStatus(202)->assertJsonPath('message', $message)->json();
    $self = $this->postJson($this->base.'/shares', ['email' => $this->owner->email, 'role' => 'reader'])->assertStatus(202)->json();
    expect(DB::table('studio_design_shares')->count())->toBe(0);
    $this->postJson($this->base.'/shares', ['email' => $this->reader->email, 'role' => 'admin'])->assertUnprocessable()->assertJsonValidationErrors('role');
    $this->postJson($this->base.'/shares', ['email' => 'no-es-email', 'role' => 'reader'])->assertUnprocessable();
    $existing = $this->postJson($this->base.'/shares', ['email' => strtoupper($this->reader->email), 'role' => 'commenter'])->assertStatus(202)->json();
    expect($existing)->toBe($missing)->and($self)->toBe($missing);
    expect(DB::table('studio_design_shares')->where('user_id', $this->reader->id)->value('role'))->toBe('commenter');
    $this->postJson($this->base.'/shares', ['email' => $this->reader->email, 'role' => 'editor'])->assertStatus(202);
    expect(DB::table('studio_design_shares')->where('user_id', $this->reader->id)->value('role'))->toBe('editor')->and(DB::table('studio_design_shares')->count())->toBe(1);
});

test('Colaboración: invitar tiene límite por usuario y copiar también', function () {
    $this->actingAs($this->owner);
    foreach (range(1, 10) as $i) {
        $this->postJson($this->base.'/shares', ['email' => "nadie$i@example.test", 'role' => 'reader'])->assertStatus(202);
    }
    $this->postJson($this->base.'/shares', ['email' => 'otro@example.test', 'role' => 'reader'])->assertStatus(429);
    foreach (range(1, 10) as $i) {
        $this->postJson($this->base.'/copies', ['name' => "Copia $i"])->assertCreated();
    }
    $this->postJson($this->base.'/copies', ['name' => 'Una más'])->assertStatus(429);
});

test('Colaboración: tope de comentarios abiertos, lista con los más recientes y avisos agrupados', function () {
    $now = now();
    $rows = fn (int $count, ?object $resolved) => array_map(fn (int $i) => ['design_id' => $this->design->id, 'author_id' => $this->owner->id, 'author_name' => 'Olga', 'revision' => 1,
        'body' => "c$i", 'resolved_at' => $resolved, 'created_at' => $now, 'updated_at' => $now], range(1, $count));
    DB::table('studio_design_comments')->insert($rows(500, null));
    $this->actingAs($this->owner);
    $this->postJson($this->base.'/comments', ['body' => 'Uno más'])->assertUnprocessable()->assertJsonPath('errors.body.0', fn ($m) => str_contains($m, 'máximo de comentarios abiertos'));
    DB::table('studio_design_comments')->update(['resolved_at' => $now]);
    DB::table('studio_design_comments')->insert($rows(600, $now));
    $new = $this->postJson($this->base.'/comments', ['body' => 'El más nuevo'])->assertCreated()->json('comment');
    expect($new)->not->toBeNull()->and($new['body'])->toBe('El más nuevo');
    $list = $this->getJson($this->base.'/comments')->json('comments');
    expect($list)->toHaveCount(1000)->and(end($list)['body'])->toBe('El más nuevo');

    // Avisos al propietario: uno por ventana, no uno por comentario.
    $link = $this->postJson($this->base.'/links', ['role' => 'commenter'])->json('link.token');
    auth()->logout();
    app('auth')->forgetGuards();
    foreach (range(1, 3) as $i) {
        $this->postJson('/d/'.$link.'/comments', ['body' => "Visita $i", 'guest_name' => 'V'])->assertCreated();
    }
    expect($this->owner->notifications->filter(fn ($n) => $n->data['type'] === 'studio_design_comment')->count())->toBe(1);
});

test('Colaboración: los comentarios de un enlace tienen límite propio', function () {
    $this->actingAs($this->owner);
    $link = $this->postJson($this->base.'/links', ['role' => 'commenter'])->json('link.token');
    auth()->logout();
    app('auth')->forgetGuards();
    foreach (range(1, 10) as $i) {
        $this->postJson('/d/'.$link.'/comments', ['body' => "c$i", 'guest_name' => 'V'])->assertCreated();
    }
    $this->postJson('/d/'.$link.'/comments', ['body' => 'c11', 'guest_name' => 'V'])->assertStatus(429);
});

test('Colaboración: un editor invitado no cambia carpeta ni plantilla y no ve carpetas del propietario', function () {
    $folder = $this->actingAs($this->owner)->postJson('/brands/'.$this->brand->id.'/studio/folders', ['name' => 'Secreta'])->json('folder.id');
    $this->patchJson($this->base, ['name' => 'Con carpeta', 'document' => collabDocument(), 'revision' => 1])->assertOk();
    $this->patchJson($this->base.'/folder', ['folder_id' => $folder])->assertOk();
    collabShare($this, $this->editor, 'editor');
    collabAs($this, $this->editor);
    $res = $this->patchJson($this->base, ['name' => 'Del editor', 'document' => collabDocument('E'), 'revision' => 2, 'folder_id' => null, 'is_template' => true])->assertOk();
    $res->assertJsonPath('design.folder_id', null)->assertJsonPath('design.is_template', false);
    $stored = Design::withoutUserScope()->find($this->design->id);
    expect($stored->folder_id)->toBe($folder)->and($stored->is_template)->toBeFalse()->and($stored->name)->toBe('Del editor');
    $this->get($this->base.'/edit')->assertInertia(fn ($page) => $page->where('folders', [])->where('design.folder_id', null));
});

test('Colaboración: la página del enlace no filtra el token por Referer, caché ni buscadores', function () {
    $this->actingAs($this->owner);
    $token = $this->postJson($this->base.'/links', ['role' => 'commenter'])->json('link.token');
    auth()->logout();
    app('auth')->forgetGuards();
    foreach (['/d/'.$token, '/d/'.$token.'/comments'] as $uri) {
        $response = $this->get($uri, ['Accept' => 'text/html'])->assertOk();
        expect($response->headers->get('Referrer-Policy'))->toBe('no-referrer')->and($response->headers->get('X-Robots-Tag'))->toBe('noindex')
            ->and($response->headers->get('Cache-Control'))->toContain('no-store')->toContain('private');
    }
});

test('Colaboración: lectores y comentaristas no reciben paleta ni tipografías del kit', function () {
    collabShare($this, $this->reader, 'reader');
    collabShare($this, $this->editor, 'editor');
    collabAs($this, $this->reader);
    $this->get($this->base.'/edit')->assertInertia(fn ($page) => $page->where('kit.palette', [])->where('kit.fonts', [])->where('fonts', []));
    $this->getJson($this->base)->assertOk()->assertJsonPath('kit.palette', []);
    collabAs($this, $this->editor);
    $this->get($this->base.'/edit')->assertInertia(fn ($page) => $page->has('kit.palette', 3));
});

test('Colaboración: quien no es propietario no gestiona accesos ni enlaces', function () {
    collabShare($this, $this->editor, 'editor');
    collabShare($this, $this->commenter, 'commenter');
    foreach ([$this->editor, $this->commenter] as $guest) {
        collabAs($this, $guest);
        $this->postJson($this->base.'/shares', ['email' => $this->stranger->email, 'role' => 'reader'])->assertForbidden();
        $this->getJson($this->base.'/shares')->assertForbidden();
        $this->postJson($this->base.'/links', ['role' => 'reader'])->assertForbidden();
        $this->getJson($this->base.'/links')->assertForbidden();
        $this->deleteJson($this->base)->assertNotFound();
        $this->postJson($this->base.'/duplicate', [])->assertNotFound();
    }
    expect(DB::table('studio_design_shares')->count())->toBe(2);
});

test('Colaboración: un usuario sin acceso recibe 404 en todas las rutas del diseño (sin IDOR)', function () {
    collabAs($this, $this->stranger);
    $checked = 0;
    foreach (Route::getRoutes() as $route) {
        if (! str_contains($route->uri(), '{design}') || str_starts_with($route->uri(), 'api/')) {
            continue;
        }
        $uri = preg_replace('/\{[a-z]+\}/', '1', str_replace('{design}', (string) $this->design->id, $route->uri()));
        foreach (array_diff($route->methods(), ['HEAD']) as $method) {
            $status = $this->json($method, '/'.$uri, [])->status();
            expect($status)->toBeIn([403, 404], "$method /$uri respondió $status a un usuario sin acceso");
            $checked++;
        }
    }
    expect($checked)->toBeGreaterThan(25);
    $design = $this->design->fresh();
    expect($design->revision)->toBe(1)->and($design->name)->toBe('Campaña compartida');
});

test('Colaboración: el editor guarda con su nombre en el historial y el lector no puede restaurar', function () {
    collabShare($this, $this->editor, 'editor');
    collabShare($this, $this->reader, 'reader');
    collabAs($this, $this->editor);
    $this->patchJson($this->base, ['name' => 'Versión de Edgar', 'document' => collabDocument('Edgar'), 'revision' => 1])->assertOk()->assertJsonPath('design.revision', 2);
    $versions = $this->getJson($this->base.'/versions')->assertOk()->json('versions');
    expect($versions[0])->toMatchArray(['revision' => 2, 'author' => 'Edgar Editor', 'current' => true])->and($versions[1])->toMatchArray(['revision' => 1, 'author' => 'Olga Propietaria', 'current' => false]);
    $restored = $this->postJson($this->base.'/versions/1/restore', ['revision' => 2])->assertOk()->json('design');
    expect($restored['revision'])->toBe(3)->and($restored['name'])->toBe('Campaña compartida')->and($restored['document']['pages'][0]['elements'][0]['text'])->toBe('Hola');
    expect(DesignRevision::withoutUserScope()->where('design_id', $this->design->id)->count())->toBe(3);
    collabAs($this, $this->reader);
    $this->getJson($this->base.'/versions')->assertOk()->assertJsonCount(3, 'versions');
    $this->postJson($this->base.'/versions/1/restore', ['revision' => 3])->assertForbidden();
    $this->postJson($this->base.'/versions/99/restore', ['revision' => 3])->assertForbidden();
    collabAs($this, $this->editor);
    $this->postJson($this->base.'/versions/99/restore', ['revision' => 3])->assertUnprocessable();
});

test('Colaboración: edición concurrente de dos clientes devuelve 409 en español sin perder el trabajo guardado', function () {
    collabShare($this, $this->editor, 'editor');
    collabAs($this, $this->owner);
    $this->patchJson($this->base, ['name' => 'De Olga', 'document' => collabDocument('Olga'), 'revision' => 1])->assertOk();
    collabAs($this, $this->editor);
    $stale = $this->patchJson($this->base, ['name' => 'De Edgar', 'document' => collabDocument('Edgar'), 'revision' => 1]);
    $stale->assertStatus(409)->assertJsonPath('current_revision', 2)->assertJsonPath('updated_by', 'Olga Propietaria');
    expect($stale->json('message'))->toContain('El diseño cambió en otra sesión')->toContain('Olga Propietaria')->toContain('guarda tu versión como copia');
    $saved = $this->getJson($this->base)->json('design');
    expect($saved['name'])->toBe('De Olga')->and($saved['revision'])->toBe(2);
    // Guardar como copia conserva el trabajo local del editor sin tocar el original.
    $copy = $this->postJson($this->base.'/copies', ['name' => 'De Edgar (copia)', 'document' => collabDocument('Edgar')])->assertCreated()->json();
    expect($copy['design']['name'])->toBe('De Edgar (copia)')->and($copy['design']['user_id'])->toBe($this->owner->id)->and($copy['design']['document']['pages'][0]['elements'][0]['text'])->toBe('Edgar');
    expect(DB::table('studio_design_shares')->where('design_id', $copy['design']['id'])->where('user_id', $this->editor->id)->value('role'))->toBe('editor');
    $this->getJson('/studio/designs/'.$copy['design']['id'])->assertOk()->assertJsonPath('design.revision', 1);
    collabAs($this, $this->reader);
    $this->postJson($this->base.'/copies', ['name' => 'Intrusa'])->assertNotFound();
    collabAs($this, $this->editor);
    $this->patchJson($this->base, ['name' => 'De Edgar', 'document' => collabDocument('Edgar'), 'revision' => 2])->assertOk()->assertJsonPath('design.revision', 3);
    // La restauración de una versión también respeta el control de revisión.
    $this->postJson($this->base.'/versions/1/restore', ['revision' => 2])->assertStatus(409);
});

test('Colaboración: enlaces con token aleatorio guardado como hash, caducidad y revocación inmediata', function () {
    $this->actingAs($this->owner);
    $created = $this->postJson($this->base.'/links', ['role' => 'reader'])->assertCreated()->json('link');
    $token = $created['token'];
    expect($token)->toMatch('/^[A-Za-z0-9]{48}$/')->and($created['url'])->toContain('/d/'.$token);
    $row = DesignLink::query()->findOrFail($created['id']);
    expect($row->token_hash)->toBe(hash('sha256', $token))->and(json_encode($row->getAttributes()))->not->toContain($token);
    expect($this->getJson($this->base.'/links')->json('links.0'))->not->toHaveKey('token');
    $second = $this->postJson($this->base.'/links', ['role' => 'reader'])->json('link.token');
    expect($second)->not->toBe($token);

    app('auth')->forgetGuards();
    auth()->logout();
    $this->get('/d/'.$token)->assertOk()->assertInertia(fn ($page) => $page->component('Studio/Review')->where('access.role', 'reader')->where('access.is_guest', true)->where('link.token', $token)->where('design.name', 'Campaña compartida'));
    $this->get('/d/'.str_repeat('a', 48))->assertNotFound();
    $this->get('/d/corto')->assertNotFound();

    $this->actingAs($this->owner)->deleteJson($this->base.'/links/'.$created['id'])->assertOk()->assertJsonPath('link.status', 'revoked');
    app('auth')->forgetGuards();
    auth()->logout();
    $this->get('/d/'.$token)->assertNotFound();
    $this->getJson('/d/'.$token.'/comments')->assertNotFound();
    $this->get('/d/'.$second)->assertOk();

    $expiring = $this->actingAs($this->owner)->postJson($this->base.'/links', ['role' => 'commenter', 'expires_at' => now()->addDay()->toIso8601String()])->assertCreated()->json('link.token');
    app('auth')->forgetGuards();
    auth()->logout();
    $this->get('/d/'.$expiring)->assertOk();
    $this->travel(2)->days();
    $this->get('/d/'.$expiring)->assertNotFound();
});

test('Colaboración: validación y límite de la creación de enlaces', function () {
    $this->actingAs($this->owner);
    $this->postJson($this->base.'/links', ['role' => 'editor'])->assertUnprocessable()->assertJsonValidationErrors('role');
    $this->postJson($this->base.'/links', ['role' => 'reader', 'expires_at' => now()->subDay()->toIso8601String()])->assertUnprocessable()->assertJsonValidationErrors('expires_at');
    $this->postJson($this->base.'/links', ['role' => 'reader', 'expires_at' => now()->addYears(3)->toIso8601String()])->assertUnprocessable();
    // Las tres peticiones inválidas ya gastaron cupo: quedan 7 de los 10 por minuto.
    for ($i = 0; $i < 7; $i++) {
        $this->postJson($this->base.'/links', ['role' => 'reader'])->assertSuccessful();
    }
    $this->postJson($this->base.'/links', ['role' => 'reader'])->assertStatus(429);
});

test('Colaboración: un enlace de comentario permite comentar con nombre y uno de lectura no', function () {
    $this->actingAs($this->owner);
    $read = $this->postJson($this->base.'/links', ['role' => 'reader'])->json('link.token');
    $comment = $this->postJson($this->base.'/links', ['role' => 'commenter'])->json('link.token');
    app('auth')->forgetGuards();
    auth()->logout();
    $this->postJson('/d/'.$read.'/comments', ['body' => 'Hola', 'guest_name' => 'Visita'])->assertForbidden();
    $this->postJson('/d/'.$comment.'/comments', ['body' => 'Sin nombre'])->assertUnprocessable()->assertJsonValidationErrors('guest_name');
    $this->postJson('/d/'.$comment.'/comments', ['body' => 'Me gusta el título', 'guest_name' => 'Visita', 'page_id' => 'page_1'])->assertCreated()->assertJsonPath('comment.author_name', 'Visita')->assertJsonPath('comment.is_guest', true);
    $this->getJson('/d/'.$read.'/comments')->assertOk()->assertJsonPath('comments.0.body', 'Me gusta el título')->assertJsonPath('comments.0.author_id', null);
    $this->actingAs($this->owner);
    expect($this->owner->notifications->filter(fn ($n) => $n->data['type'] === 'studio_design_comment')->count())->toBe(1);
});

test('Colaboración: comentarios anclados con hilo, resolver, reabrir y permisos por rol', function () {
    collabShare($this, $this->reader, 'reader');
    collabShare($this, $this->commenter, 'commenter');
    collabShare($this, $this->editor, 'editor');
    collabAs($this, $this->reader);
    $this->postJson($this->base.'/comments', ['body' => 'No debería'])->assertForbidden();
    collabAs($this, $this->commenter);
    $this->postJson($this->base.'/comments', ['body' => '   '])->assertUnprocessable();
    $this->postJson($this->base.'/comments', ['body' => 'Ancla mala', 'page_id' => 'otra'])->assertUnprocessable()->assertJsonValidationErrors('page_id');
    $this->postJson($this->base.'/comments', ['body' => 'Elemento malo', 'page_id' => 'page_1', 'element_id' => 'nada'])->assertUnprocessable()->assertJsonValidationErrors('element_id');
    $this->postJson($this->base.'/comments', ['body' => 'Elemento sin página', 'element_id' => 'title'])->assertUnprocessable();
    $root = $this->postJson($this->base.'/comments', ['body' => 'Revisar el título', 'page_id' => 'page_1', 'element_id' => 'title'])->assertCreated()->json('comment');
    expect($root)->toMatchArray(['page_id' => 'page_1', 'element_id' => 'title', 'revision' => 1, 'resolved' => false, 'can_resolve' => true, 'can_delete' => true]);
    $general = $this->postJson($this->base.'/comments', ['body' => 'Comentario general'])->assertCreated()->json('comment');
    expect($general['page_id'])->toBeNull();

    collabAs($this, $this->editor);
    $reply = $this->postJson($this->base.'/comments', ['body' => 'Corregido', 'parent_id' => $root['id']])->assertCreated()->json('comment');
    expect($reply)->toMatchArray(['parent_id' => $root['id'], 'page_id' => 'page_1', 'element_id' => 'title']);
    $nested = $this->postJson($this->base.'/comments', ['body' => 'Respuesta a respuesta', 'parent_id' => $reply['id']])->assertCreated()->json('comment');
    expect($nested['parent_id'])->toBe($root['id']);
    $this->postJson($this->base.'/comments/'.$root['id'].'/resolve')->assertOk();
    expect($this->getJson($this->base.'/comments')->json('comments.0'))->toMatchArray(['resolved' => true, 'resolved_by' => 'Edgar Editor']);
    $this->postJson($this->base.'/comments/'.$root['id'].'/reopen')->assertOk();

    collabAs($this, $this->reader);
    $this->postJson($this->base.'/comments/'.$root['id'].'/resolve')->assertForbidden();
    $this->deleteJson($this->base.'/comments/'.$root['id'])->assertForbidden();
    expect($this->getJson($this->base.'/comments')->assertOk()->json('comments'))->toHaveCount(4);
    collabAs($this, $this->commenter);
    $this->postJson($this->base.'/comments/'.$reply['id'].'/resolve')->assertOk();
    $this->postJson($this->base.'/comments/'.$general['id'].'/reopen')->assertOk();
    $this->deleteJson($this->base.'/comments/'.$reply['id'])->assertForbidden();
    collabAs($this, $this->stranger);
    $this->getJson($this->base.'/comments')->assertNotFound();
    $this->postJson($this->base.'/comments', ['body' => 'Intrusión'])->assertNotFound();
    $this->postJson($this->base.'/comments/'.$root['id'].'/resolve')->assertNotFound();
    collabAs($this, $this->commenter);
    $this->deleteJson($this->base.'/comments/'.$root['id'])->assertOk();
    expect(DB::table('studio_design_comments')->where('design_id', $this->design->id)->count())->toBe(1);
    expect($this->owner->notifications->filter(fn ($n) => $n->data['type'] === 'studio_design_comment')->count())->toBe(1);
    expect($this->commenter->notifications->filter(fn ($n) => $n->data['type'] === 'studio_design_comment')->count())->toBeGreaterThanOrEqual(1);
});

test('Colaboración: los comentarios de otro diseño no se pueden resolver ni leer por id ajeno', function () {
    $other = app(StudioService::class)->create($this->stranger, Brand::factory()->for($this->stranger)->create(), ['name' => 'Ajeno', 'document' => collabDocument()]);
    collabAs($this, $this->stranger);
    $foreign = $this->postJson('/studio/designs/'.$other->id.'/comments', ['body' => 'Mío'])->assertCreated()->json('comment.id');
    collabAs($this, $this->owner);
    $this->postJson($this->base.'/comments/'.$foreign.'/resolve')->assertNotFound();
    $this->deleteJson($this->base.'/comments/'.$foreign)->assertNotFound();
    $this->postJson($this->base.'/comments', ['body' => 'Cruzado', 'parent_id' => $foreign])->assertUnprocessable();
});

test('Colaboración: lectores y enlaces solo reciben los recursos que usa el documento', function () {
    $used = MediaAsset::factory()->for($this->owner)->create(['mime' => 'image/png']);
    $unused = MediaAsset::factory()->for($this->owner)->create(['mime' => 'image/png']);
    Storage::disk('local')->put($used->path, 'imagen-usada');
    Storage::disk('local')->put($unused->path, 'imagen-sin-usar');
    $this->actingAs($this->owner);
    foreach ([$used, $unused] as $media) {
        $this->postJson('/brands/'.$this->brand->id.'/studio/assets', ['media_id' => $media->id])->assertCreated();
    }
    $doc = collabDocument();
    $doc['pages'][0]['elements'][] = ['id' => 'photo', 'type' => 'image', 'asset_id' => $used->id, 'x' => 0, 'y' => 0, 'width' => 100, 'height' => 100];
    $this->patchJson($this->base, ['name' => 'Con imagen', 'document' => $doc, 'revision' => 1])->assertOk();
    collabShare($this, $this->reader, 'reader');
    collabShare($this, $this->editor, 'editor');
    $link = $this->postJson($this->base.'/links', ['role' => 'reader'])->json('link.token');

    collabAs($this, $this->reader);
    $props = $this->get($this->base.'/edit')->assertOk()->assertInertia(fn ($page) => $page->component('Studio/Review')->has('assets', 1))->inertiaProps();
    expect($props['assets'][0]['preview_url'])->toBe($this->base.'/shared/assets/'.$used->id);
    expect($this->get($this->base.'/shared/assets/'.$used->id)->assertOk()->streamedContent())->toBe('imagen-usada');
    $this->get($this->base.'/shared/assets/'.$unused->id)->assertNotFound();
    collabAs($this, $this->editor);
    $this->get($this->base.'/edit')->assertOk()->assertInertia(fn ($page) => $page->component('Studio/Editor')->where('access.role', 'editor')->has('assets', 2));
    $this->get($this->base.'/shared/assets/'.$unused->id)->assertOk();
    collabAs($this, $this->stranger);
    $this->get($this->base.'/shared/assets/'.$used->id)->assertNotFound();

    auth()->logout();
    app('auth')->forgetGuards();
    expect($this->get('/d/'.$link.'/assets/'.$used->id)->assertOk()->streamedContent())->toBe('imagen-usada');
    $this->get('/d/'.$link.'/assets/'.$unused->id)->assertNotFound();
});

test('Colaboración: la lista del estudio incluye los diseños compartidos con el usuario y no los revocados', function () {
    collabShare($this, $this->editor, 'editor');
    collabAs($this, $this->editor);
    $this->get('/studio')->assertOk()->assertInertia(fn ($page) => $page->component('Studio/Index')->has('shared', 1)->where('shared.0.id', $this->design->id)->where('shared.0.role', 'editor')->where('shared.0.owner_name', 'Olga Propietaria'));
    collabAs($this, $this->owner);
    $share = $this->getJson($this->base.'/shares')->json('shares.0.id');
    $this->deleteJson($this->base.'/shares/'.$share)->assertOk();
    collabAs($this, $this->editor);
    $this->get('/studio')->assertOk()->assertInertia(fn ($page) => $page->has('shared', 0));
});

test('Colaboración: un diseño archivado deja de ser accesible para los invitados', function () {
    collabShare($this, $this->editor, 'editor');
    $this->deleteJson($this->base)->assertOk();
    collabAs($this, $this->editor);
    $this->getJson($this->base)->assertNotFound();
    $this->get('/d/'.str_repeat('b', 48))->assertNotFound();
});

test('Colaboración API: roles, conflicto y revocación con tokens de dos usuarios', function () {
    $ownerKey = $this->mcpKey($this->owner, ['designs:read', 'designs:write']);
    $editorKey = $this->mcpKey($this->editor, ['designs:read', 'designs:write']);
    $readerKey = $this->mcpKey($this->reader, ['designs:read']);
    $strangerKey = $this->mcpKey($this->stranger, ['designs:read', 'designs:write']);
    $api = '/api/v1/designs/'.$this->design->id;
    $headers = fn (string $key) => ['Authorization' => 'Bearer '.$key] + (app('auth')->forgetGuards() ? [] : []);

    $this->postJson($api.'/shares', ['email' => $this->editor->email, 'role' => 'editor'], $headers($ownerKey))->assertStatus(202);
    $this->postJson($api.'/shares', ['email' => $this->reader->email, 'role' => 'reader'], $headers($ownerKey))->assertStatus(202);
    $this->postJson($api.'/shares', ['email' => $this->reader->email, 'role' => 'reader'], $headers($readerKey))->assertForbidden();
    $this->getJson($api, $headers($strangerKey))->assertNotFound();
    $this->getJson($api.'/collab', $headers($strangerKey))->assertNotFound();
    $this->getJson($api, $headers($readerKey))->assertOk()->assertJsonPath('role', 'reader');
    $this->patchJson($api, ['name' => 'X', 'document' => collabDocument(), 'revision' => 1], $headers($readerKey))->assertForbidden();
    $this->patchJson($api, ['name' => 'Editor', 'document' => collabDocument('Edgar'), 'revision' => 1], $headers($editorKey))->assertOk()->assertJsonPath('revision', 2);
    $this->patchJson($api, ['name' => 'Dueña', 'document' => collabDocument('Olga'), 'revision' => 1], $headers($ownerKey))->assertConflict()->assertJsonPath('current_revision', 2)->assertJsonPath('updated_by', 'Edgar Editor');
    $this->postJson($api.'/comments', ['body' => 'Desde la API', 'page_id' => 'page_1'], $headers($editorKey))->assertCreated();
    $this->postJson($api.'/comments', ['body' => 'Lector'], $headers($readerKey))->assertForbidden();
    $state = $this->getJson($api.'/collab', $headers($readerKey))->assertOk()->json();
    expect($state['comments'])->toHaveCount(1)->and($state['shares'])->toBe([])->and($state['versions'])->toHaveCount(2);
    $this->postJson($api.'/links', ['role' => 'reader'], $headers($readerKey))->assertForbidden();
    $this->postJson($api.'/links', ['role' => 'commenter'], $headers($ownerKey))->assertCreated();
    $share = $this->getJson($api.'/shares', $headers($ownerKey))->json('shares.0.id');
    $this->deleteJson($api.'/shares/'.$share, [], $headers($ownerKey))->assertOk();
    $this->getJson($api, $headers($editorKey))->assertNotFound();
    $this->patchJson($api, ['name' => 'Revocado', 'document' => collabDocument(), 'revision' => 2], $headers($editorKey))->assertNotFound();
});

test('Colaboración MCP: get/update_design respetan el rol y manage_design_collaboration cubre el flujo', function () {
    $ownerKey = $this->mcpKey($this->owner);
    $editorKey = $this->mcpKey($this->editor);
    $readerKey = $this->mcpKey($this->reader);
    $strangerKey = $this->mcpKey($this->stranger);
    $id = $this->design->id;

    [$error, $data] = $this->callTool($ownerKey, 'manage_design_collaboration', ['design_id' => $id, 'action' => 'share', 'email' => $this->editor->email, 'role' => 'editor']);
    expect($error)->toBeFalse()->and($data['message'])->toContain('Si existe una cuenta');
    $this->callTool($ownerKey, 'manage_design_collaboration', ['design_id' => $id, 'action' => 'share', 'email' => $this->reader->email, 'role' => 'reader']);
    [$error, $data] = $this->callTool($strangerKey, 'get_design', ['design_id' => $id]);
    expect($error)->toBeTrue();
    [$error, $data] = $this->callTool($readerKey, 'get_design', ['design_id' => $id]);
    expect($error)->toBeFalse()->and($data['role'])->toBe('reader')->and($data['revision'])->toBe(1);
    [$error, $message] = $this->callTool($readerKey, 'update_design', ['design_id' => $id, 'revision' => 1, 'name' => 'X', 'document' => collabDocument()]);
    expect($error)->toBeTrue()->and($message)->toContain('403');
    [$error, $data] = $this->callTool($editorKey, 'update_design', ['design_id' => $id, 'revision' => 1, 'name' => 'Editor MCP', 'document' => collabDocument('MCP')]);
    expect($error)->toBeFalse()->and($data['revision'])->toBe(2);
    [$error, $message] = $this->callTool($ownerKey, 'update_design', ['design_id' => $id, 'revision' => 1, 'name' => 'Vieja', 'document' => collabDocument()]);
    expect($error)->toBeTrue()->and($message)->toContain('409')->toContain('El diseño cambió en otra sesión');
    [$error, $data] = $this->callTool($editorKey, 'manage_design_collaboration', ['design_id' => $id, 'action' => 'comment', 'body' => 'Comentario MCP', 'page_id' => 'page_1']);
    expect($error)->toBeFalse()->and($data['comment']['body'])->toBe('Comentario MCP');
    [$error] = $this->callTool($readerKey, 'manage_design_collaboration', ['design_id' => $id, 'action' => 'comment', 'body' => 'No']);
    expect($error)->toBeTrue();
    [$error, $data] = $this->callTool($readerKey, 'manage_design_collaboration', ['design_id' => $id, 'action' => 'list_comments']);
    expect($error)->toBeFalse()->and($data['comments'])->toHaveCount(1);
    [$error] = $this->callTool($readerKey, 'manage_design_collaboration', ['design_id' => $id, 'action' => 'create_link', 'link_role' => 'reader']);
    expect($error)->toBeTrue();
    [$error, $data] = $this->callTool($ownerKey, 'manage_design_collaboration', ['design_id' => $id, 'action' => 'create_link', 'link_role' => 'reader']);
    expect($error)->toBeFalse()->and($data['link']['token'])->toHaveLength(48);
    [$error, $data] = $this->callTool($ownerKey, 'manage_design_collaboration', ['design_id' => $id, 'action' => 'revoke_link', 'link_id' => $data['link']['id']]);
    expect($error)->toBeFalse()->and($data['link']['status'])->toBe('revoked');
    [$error, $data] = $this->callTool($ownerKey, 'manage_design_collaboration', ['design_id' => $id, 'action' => 'restore_version', 'version' => 1, 'revision' => 2]);
    expect($error)->toBeFalse()->and($data['revision'])->toBe(3)->and($data['document']['pages'][0]['elements'][0]['text'])->toBe('Hola');
    [$error, $data] = $this->callTool($ownerKey, 'manage_design_collaboration', ['design_id' => $id, 'action' => 'list_shares']);
    expect($data['shares'])->toHaveCount(2);
    [$error] = $this->callTool($ownerKey, 'manage_design_collaboration', ['design_id' => $id, 'action' => 'revoke_share', 'share_id' => $data['shares'][0]['id']]);
    expect($error)->toBeFalse();
    [$error] = $this->callTool($editorKey, 'get_design', ['design_id' => $id]);
    expect($error)->toBeTrue();
});

test('Colaboración: las notificaciones son del propio usuario y no envían correo', function () {
    collabShare($this, $this->reader, 'reader');
    $notification = $this->reader->notifications()->first();
    expect($notification->data)->toMatchArray(['type' => 'studio_design_shared', 'design_id' => $this->design->id])->and($notification->type)->toBe(StudioDesignActivity::class);
    expect($this->owner->notifications()->count())->toBe(0)->and($this->stranger->notifications()->count())->toBe(0);
    Mail::assertNothingSent();
    expect(Design::withoutUserScope()->find($this->design->id)->user_id)->toBe($this->owner->id);
});
