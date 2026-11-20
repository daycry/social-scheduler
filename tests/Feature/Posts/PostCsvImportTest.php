<?php

use App\Connectors\Network;
use App\Enums\PostStatus;
use App\Models\Brand;
use App\Models\MediaAsset;
use App\Models\Post;
use App\Models\SocialAccount;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Queue;

// Importacion masiva por CSV (T-83): vista previa fila a fila sin crear nada, y confirmacion de un solo uso que crea solo las filas validas
// con la MISMA validacion que el compositor (PostService::validate + las reglas de PostRequest).

const CSV_HEADER = 'fecha;texto;cuentas;tipo;medios;aprobacion';

beforeEach(function () {
    Carbon::setTestNow('2026-11-01 12:00:00');
    Queue::fake();
    $this->user = User::factory()->create();
    $this->brand = Brand::factory()->for($this->user)->create(['timezone' => 'Europe/Madrid']);
    $this->fb = SocialAccount::factory()->for($this->user)->create(['brand_id' => $this->brand->id, 'network' => Network::Facebook, 'name' => 'Mi Pagina', 'username' => null]);
    $this->ig = SocialAccount::factory()->for($this->user)->create(['brand_id' => $this->brand->id, 'network' => Network::Instagram, 'name' => 'Mi Insta', 'username' => 'miinsta']);
    $this->bsky = SocialAccount::factory()->for($this->user)->create(['brand_id' => $this->brand->id, 'network' => Network::Bluesky, 'name' => 'Mi Bluesky', 'username' => 'mi.bsky.social']);
});

function importCsv(string $content, string $name = 'posts.csv'): UploadedFile
{
    return UploadedFile::fake()->createWithContent($name, $content);
}

function previewImport(string $content, ?User $user = null, ?Brand $brand = null)
{
    return test()->actingAs($user ?? test()->user)->postJson(route('brands.posts.import.preview', $brand ?? test()->brand), ['file' => importCsv($content)]);
}

function confirmImport(string $token, ?User $user = null, ?Brand $brand = null)
{
    return test()->actingAs($user ?? test()->user)->postJson(route('brands.posts.import.confirm', $brand ?? test()->brand), ['token' => $token]);
}

test('la vista previa lista cada fila con sus datos y no crea nada', function () {
    $csv = CSV_HEADER."\n2026-12-01 10:00;Hola mundo;facebook:Mi Pagina;;;\n;Borrador sin fecha;facebook:Mi Pagina;;;\n";

    $res = previewImport($csv)->assertOk();

    $res->assertJsonPath('summary', ['total' => 2, 'valid' => 2, 'invalid' => 0])
        ->assertJsonPath('rows.0.row', 2)->assertJsonPath('rows.0.valid', true)
        ->assertJsonPath('rows.0.date', '2026-12-01 10:00')->assertJsonPath('rows.0.text', 'Hola mundo')
        ->assertJsonPath('rows.1.date', null)
        ->assertJsonPath('rows.0.accounts.0.name', 'Mi Pagina');
    expect($res->json('token'))->toBeString()->and(Post::withoutUserScope()->count())->toBe(0);
    Queue::assertNothingPushed();
});

test('confirmar crea solo las filas validas; las invalidas siguen listadas y no se crean', function () {
    $csv = CSV_HEADER."\n"
        ."2026-12-01 10:00;Valida programada;facebook:Mi Pagina;;;\n"
        .";Valida borrador;facebook:Mi Pagina|bluesky:@mi.bsky.social;;;\n"
        ."2026-12-02 10:00;Instagram sin media;instagram:@miinsta;;;\n"
        ."2026-10-01 10:00;Fecha en el pasado;facebook:Mi Pagina;;;\n";

    $preview = previewImport($csv)->assertOk()->assertJsonPath('summary', ['total' => 4, 'valid' => 2, 'invalid' => 2]);
    $preview->assertJsonPath('rows.2.valid', false)->assertJsonPath('rows.3.valid', false);
    expect($preview->json('rows.2.errors'))->not->toBeEmpty()->and($preview->json('rows.3.errors'))->not->toBeEmpty();

    $res = confirmImport($preview->json('token'))->assertOk();

    $res->assertJsonPath('summary', ['created' => 2, 'skipped' => 2]);
    $res->assertJsonPath('rows.0.post_id', fn ($id) => is_int($id))->assertJsonPath('rows.2.post_id', null);
    $texts = Post::withoutUserScope()->orderBy('id')->pluck('text')->all();
    expect($texts)->toBe(['Valida programada', 'Valida borrador']);
    $scheduled = Post::withoutUserScope()->where('text', 'Valida programada')->first();
    expect($scheduled->status)->toBe(PostStatus::Scheduled)
        ->and($scheduled->scheduled_at->toDateTimeString())->toBe('2026-12-01 09:00:00') // 10:00 en Madrid (UTC+1)
        ->and($scheduled->targets)->toHaveCount(1);
    $draft = Post::withoutUserScope()->where('text', 'Valida borrador')->first();
    expect($draft->status)->toBe(PostStatus::Draft)->and($draft->targets)->toHaveCount(2);
});

test('aplica la misma validacion que el compositor: fecha pasada, media exigida y limite de texto de la red', function () {
    $long = str_repeat('a', 301); // Bluesky admite 300
    $csv = CSV_HEADER."\n"
        ."2026-10-01 10:00;Pasado;facebook:Mi Pagina;;;\n"
        ."2026-12-02 10:00;Sin media;instagram:@miinsta;;;\n"
        ."2026-12-02 10:00;{$long};bluesky:@mi.bsky.social;;;\n";

    $expected = collect([
        ['text' => 'Pasado', 'targets' => [['account_id' => $this->fb->id, 'content_type' => 'facebook_text']], 'schedule' => true, 'scheduled_at' => '2026-10-01T10:00'],
        ['text' => 'Sin media', 'targets' => [['account_id' => $this->ig->id, 'content_type' => 'instagram_image']], 'schedule' => true, 'scheduled_at' => '2026-12-02T10:00'],
        ['text' => $long, 'targets' => [['account_id' => $this->bsky->id, 'content_type' => 'bluesky_post']], 'schedule' => true, 'scheduled_at' => '2026-12-02T10:00'],
    ])->map(fn ($payload) => $this->actingAs($this->user)->postJson(route('brands.posts.validate', $this->brand), $payload + ['media' => []])->json('errors'));

    $rows = previewImport($csv)->assertOk()->json('rows');

    foreach ([0, 1, 2] as $i) {
        expect($expected[$i])->not->toBeEmpty();
        $shown = implode(' ', $rows[$i]['errors']);
        foreach (collect($expected[$i])->flatten() as $message) {
            expect($shown)->toContain($message);
        }
    }
});

test('una cuenta de otra marca, de otro usuario o inexistente es un error de fila que no revela nada', function () {
    $otherBrand = Brand::factory()->for($this->user)->create();
    SocialAccount::factory()->for($this->user)->create(['brand_id' => $otherBrand->id, 'network' => Network::Facebook, 'name' => 'Cuenta Hermana']);
    $stranger = User::factory()->create();
    $strangerBrand = Brand::factory()->for($stranger)->create();
    SocialAccount::factory()->for($stranger)->create(['brand_id' => $strangerBrand->id, 'network' => Network::Facebook, 'name' => 'Cuenta Foranea']);
    $csv = CSV_HEADER."\n"
        .";Otra marca;facebook:Cuenta Hermana;;;\n"
        .";Otro usuario;facebook:Cuenta Foranea;;;\n"
        .";No existe;facebook:Nada;;;\n";

    $res = previewImport($csv)->assertOk()->assertJsonPath('summary.valid', 0);

    $messages = collect($res->json('rows'))->map(fn ($r) => $r['errors'][0])->unique();
    expect($messages)->toHaveCount(3)->and(implode(' ', $messages->all()))->not->toContain('otra marca')->not->toContain('otro usuario');
    foreach ($res->json('rows') as $row) {
        expect($row['valid'])->toBeFalse();
    }
    confirmImport($res->json('token'))->assertOk()->assertJsonPath('summary.created', 0);
    expect(Post::withoutUserScope()->count())->toBe(0);
});

test('una media ajena es un error de fila y una propia se acepta', function () {
    $mine = MediaAsset::factory()->create(['user_id' => $this->user->id]);
    $theirs = MediaAsset::factory()->create(['user_id' => User::factory()->create()->id]);
    $csv = CSV_HEADER."\n"
        ."2026-12-02 10:00;Con media ajena;instagram:@miinsta;;{$theirs->id};\n"
        ."2026-12-02 10:00;Con media propia;instagram:@miinsta;;{$mine->id};\n"
        .";Media rara;facebook:Mi Pagina;;abc;\n";

    $res = previewImport($csv)->assertOk();

    $res->assertJsonPath('rows.0.valid', false)->assertJsonPath('rows.1.valid', true)->assertJsonPath('rows.2.valid', false);
    confirmImport($res->json('token'))->assertOk()->assertJsonPath('summary.created', 1);
    $post = Post::withoutUserScope()->where('text', 'Con media propia')->firstOrFail();
    expect($post->media()->pluck('media_assets.id')->all())->toBe([$mine->id]);
});

test('admite separador coma, BOM y un texto con saltos de linea y comas entre comillas', function () {
    $csv = "\xEF\xBB\xBF"."fecha,texto,cuentas\r\n,\"Linea uno,\r\nlinea \"\"dos\"\"\",facebook:Mi Pagina\r\n";

    $res = previewImport($csv)->assertOk()->assertJsonPath('summary.valid', 1);
    $res->assertJsonPath('rows.0.text', "Linea uno,\nlinea \"dos\"");
    confirmImport($res->json('token'))->assertOk();
    expect(Post::withoutUserScope()->value('text'))->toBe("Linea uno,\nlinea \"dos\"");
});

test('las filas totalmente vacias se ignoran', function () {
    $csv = CSV_HEADER."\n;;;;;\n\n;Con texto;facebook:Mi Pagina;;;\n;;;;;\n";

    previewImport($csv)->assertOk()->assertJsonPath('summary.total', 1)->assertJsonPath('rows.0.row', 4);
});

test('el texto del CSV no se interpreta: se devuelve tal cual para pintarlo escapado', function () {
    $csv = CSV_HEADER."\n;<script>alert(1)</script> =HYPERLINK(\"x\");facebook:Mi Pagina;;;\n";

    previewImport($csv)->assertOk()->assertJsonPath('rows.0.text', '<script>alert(1)</script> =HYPERLINK("x")');
});

test('el limite de filas y el de tamano se rechazan a nivel de fichero', function () {
    config(['post_import.max_rows' => 2]);
    $rows = implode('', array_fill(0, 3, ";Fila;facebook:Mi Pagina;;;\n"));

    previewImport(CSV_HEADER."\n".$rows)->assertUnprocessable()->assertJsonValidationErrors('file');

    config(['post_import.max_kb' => 1]);
    $big = CSV_HEADER."\n;".str_repeat('x', 2000).";facebook:Mi Pagina;;;\n";
    previewImport($big)->assertUnprocessable()->assertJsonValidationErrors('file');
    expect(Post::withoutUserScope()->count())->toBe(0);
});

test('si falta una columna obligatoria el error es del fichero', function () {
    previewImport("fecha;texto\n;Hola\n")->assertUnprocessable()->assertJsonValidationErrors('file')
        ->assertJsonPath('errors.file.0', fn ($m) => str_contains($m, 'cuentas'));
    previewImport("fecha;texto;cuentas;rareza\n;Hola;facebook:Mi Pagina;x\n")->assertUnprocessable()->assertJsonValidationErrors('file');
    previewImport("fecha;texto;cuentas\n")->assertUnprocessable()->assertJsonValidationErrors('file');
    previewImport("fecha;texto;cuentas\n;\xFF\xFE;facebook:Mi Pagina\n")->assertUnprocessable()->assertJsonValidationErrors('file');
});

test('una segunda confirmacion con el mismo token no duplica posts', function () {
    $token = previewImport(CSV_HEADER."\n;Una sola vez;facebook:Mi Pagina;;;\n")->assertOk()->json('token');

    confirmImport($token)->assertOk()->assertJsonPath('summary.created', 1);
    confirmImport($token)->assertStatus(409);

    expect(Post::withoutUserScope()->count())->toBe(1);
});

test('el token no sirve para otro usuario ni para otra marca y caduca', function () {
    $token = previewImport(CSV_HEADER."\n;Mio;facebook:Mi Pagina;;;\n")->json('token');
    $otherBrand = Brand::factory()->for($this->user)->create();

    confirmImport($token, null, $otherBrand)->assertStatus(410);
    confirmImport($token, User::factory()->create(), $this->brand)->assertNotFound();
    expect(Post::withoutUserScope()->count())->toBe(0);

    Carbon::setTestNow(now()->addMinutes(config('post_import.ttl_minutes') + 1));
    confirmImport($token)->assertStatus(410);
    expect(Post::withoutUserScope()->count())->toBe(0);
});

test('al confirmar se vuelve a validar: una fecha que ya paso entre la vista previa y la confirmacion no se crea', function () {
    $token = previewImport(CSV_HEADER."\n2026-11-01 14:00;Pronto;facebook:Mi Pagina;;;\n")->assertJsonPath('summary.valid', 1)->json('token');

    Carbon::setTestNow('2026-11-01 12:25:00');
    confirmImport($token)->assertOk()->assertJsonPath('summary', ['created' => 0, 'skipped' => 1]);

    expect(Post::withoutUserScope()->count())->toBe(0);
})->skip('T-83 en curso (pausa del 2026-11-20): la revalidación al confirmar aún no rechaza la fecha ya pasada.');

test('un usuario ajeno a la marca recibe 404 en todas las rutas', function () {
    $stranger = User::factory()->create();

    $this->actingAs($stranger)->get(route('brands.posts.import.show', $this->brand))->assertNotFound();
    $this->actingAs($stranger)->get(route('brands.posts.import.sample', $this->brand))->assertNotFound();
    previewImport(CSV_HEADER."\n;Hola;facebook:Mi Pagina;;;\n", $stranger)->assertNotFound();
    confirmImport(str_repeat('a', 40), $stranger)->assertNotFound();
    auth()->logout();
    $this->post(route('brands.posts.import.preview', $this->brand))->assertRedirect(route('login'));
});

test('la pagina carga con la marca, los limites y las cuentas con su identificador', function () {
    $this->actingAs($this->user)->get(route('brands.posts.import.show', $this->brand))->assertOk()
        ->assertInertia(fn ($page) => $page->component('Posts/Import')
            ->where('brand.id', $this->brand->id)
            ->where('limits.max_rows', config('post_import.max_rows'))
            ->where('accounts.0.identifier', fn ($v) => str_contains($v, ':')));
})->skip('T-83 en curso (pausa del 2026-11-20): falta la página Posts/Import.vue.');

test('la aprobacion pedida deja el post como borrador pendiente', function () {
    $csv = CSV_HEADER."\n2026-12-01 10:00;Con aprobacion;facebook:Mi Pagina;;;si\n";

    $token = previewImport($csv)->assertJsonPath('rows.0.valid', true)->assertJsonPath('rows.0.approval', true)->json('token');
    confirmImport($token)->assertOk();

    $post = Post::withoutUserScope()->firstOrFail();
    expect($post->approval_status)->toBe(Post::APPROVAL_PENDING)->and($post->status)->toBe(PostStatus::Draft);
});

test('el tipo de contenido se aplica a la cuenta de su red y un tipo sin cuenta es un error', function () {
    $csv = CSV_HEADER."\n"
        .";Con tipo;facebook:Mi Pagina;facebook_text;;\n"
        .";Tipo ajeno a la fila;facebook:Mi Pagina;instagram_reel;;\n"
        .";Tipo inventado;facebook:Mi Pagina;inventado;;\n";

    $res = previewImport($csv)->assertOk();

    $res->assertJsonPath('rows.0.valid', true)->assertJsonPath('rows.1.valid', false)->assertJsonPath('rows.2.valid', false);
});

test('el CSV de ejemplo se descarga y se importa limpio en la marca', function () {
    $res = $this->actingAs($this->user)->get(route('brands.posts.import.sample', $this->brand))->assertOk();
    expect($res->headers->get('content-disposition'))->toContain('importar-publicaciones.csv');
    $content = $res->streamedContent();
    expect(str_starts_with($content, "\xEF\xBB\xBF"))->toBeTrue()->and($content)->toContain('facebook:Mi Pagina');

    $preview = previewImport($content)->assertOk();

    expect($preview->json('summary.invalid'))->toBe(0)->and($preview->json('summary.total'))->toBeGreaterThanOrEqual(2);
    confirmImport($preview->json('token'))->assertOk()->assertJsonPath('summary.skipped', 0);
});

test('el CSV de ejemplo de una marca sin cuentas usa nombres de muestra y se descarga igual', function () {
    $empty = Brand::factory()->for($this->user)->create();

    $res = $this->actingAs($this->user)->get(route('brands.posts.import.sample', $empty))->assertOk();

    expect($res->streamedContent())->toContain('fecha;texto;cuentas');
});
