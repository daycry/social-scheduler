<?php

use App\Connectors\Network;
use App\Enums\PostStatus;
use App\Models\Brand;
use App\Models\MediaAsset;
use App\Models\Post;
use App\Models\SocialAccount;
use App\Models\User;
use App\Services\Posts\PostCsvImport;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
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

function importAcct(SocialAccount $account): string
{
    return PostCsvImport::identifier($account);
}

function importFb(): string
{
    return importAcct(test()->fb);
}

function importIg(): string
{
    return importAcct(test()->ig);
}

function importBsky(): string
{
    return importAcct(test()->bsky);
}

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
    $csv = CSV_HEADER."\n2026-12-01 10:00;Hola mundo;".importFb().";;;\n;Borrador sin fecha;".importFb().";;;\n";

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
        .'2026-12-01 10:00;Valida programada;'.importFb().";;;\n"
        .';Valida borrador;'.importFb().'|'.importBsky().";;;\n"
        .'2026-12-02 10:00;Instagram sin media;'.importIg().";;;\n"
        .'2026-10-01 10:00;Fecha en el pasado;'.importFb().";;;\n";

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
        .'2026-10-01 10:00;Pasado;'.importFb().";;;\n"
        .'2026-12-02 10:00;Sin media;'.importIg().";;;\n"
        ."2026-12-02 10:00;{$long};".importBsky().";;;\n";

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
    $sister = SocialAccount::factory()->for($this->user)->create(['brand_id' => $otherBrand->id, 'network' => Network::Facebook, 'name' => 'Cuenta Hermana']);
    $stranger = User::factory()->create();
    $strangerBrand = Brand::factory()->for($stranger)->create();
    $foreign = SocialAccount::factory()->for($stranger)->create(['brand_id' => $strangerBrand->id, 'network' => Network::Facebook, 'name' => 'Cuenta Foranea']);
    $csv = CSV_HEADER."\n"
        .';Otra marca;'.importAcct($sister).";;;\n"
        .';Otro usuario;'.importAcct($foreign).";;;\n"
        .";No existe;facebook:999999;;;\n";

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
        .'2026-12-02 10:00;Con media ajena;'.importIg().";;{$theirs->id};\n"
        .'2026-12-02 10:00;Con media propia;'.importIg().";;{$mine->id};\n"
        .';Media rara;'.importFb().";;abc;\n";

    $res = previewImport($csv)->assertOk();

    $res->assertJsonPath('rows.0.valid', false)->assertJsonPath('rows.1.valid', true)->assertJsonPath('rows.2.valid', false);
    confirmImport($res->json('token'))->assertOk()->assertJsonPath('summary.created', 1);
    $post = Post::withoutUserScope()->where('text', 'Con media propia')->firstOrFail();
    expect($post->media()->pluck('media_assets.id')->all())->toBe([$mine->id]);
});

test('admite separador coma, BOM y un texto con saltos de linea y comas entre comillas', function () {
    $csv = "\xEF\xBB\xBF"."fecha,texto,cuentas\r\n,\"Linea uno,\r\nlinea \"\"dos\"\"\",".importFb()."\r\n";

    $res = previewImport($csv)->assertOk()->assertJsonPath('summary.valid', 1);
    $res->assertJsonPath('rows.0.text', "Linea uno,\nlinea \"dos\"");
    confirmImport($res->json('token'))->assertOk();
    expect(Post::withoutUserScope()->value('text'))->toBe("Linea uno,\nlinea \"dos\"");
});

test('las filas totalmente vacias se ignoran', function () {
    $csv = CSV_HEADER."\n;;;;;\n\n;Con texto;".importFb().";;;\n;;;;;\n";

    previewImport($csv)->assertOk()->assertJsonPath('summary.total', 1)->assertJsonPath('rows.0.row', 4);
});

test('el texto del CSV no se interpreta: se devuelve tal cual para pintarlo escapado', function () {
    $csv = CSV_HEADER."\n;<script>alert(1)</script> =HYPERLINK(\"x\");".importFb().";;;\n";

    previewImport($csv)->assertOk()->assertJsonPath('rows.0.text', '<script>alert(1)</script> =HYPERLINK("x")');
});

test('el limite de filas y el de tamano se rechazan a nivel de fichero', function () {
    config(['post_import.max_rows' => 2]);
    $rows = implode('', array_fill(0, 3, ';Fila;'.importFb().";;;\n"));

    previewImport(CSV_HEADER."\n".$rows)->assertUnprocessable()->assertJsonValidationErrors('file');

    config(['post_import.max_kb' => 1]);
    $big = CSV_HEADER."\n;".str_repeat('x', 2000).';'.importFb().";;;\n";
    previewImport($big)->assertUnprocessable()->assertJsonValidationErrors('file');
    expect(Post::withoutUserScope()->count())->toBe(0);
});

test('si falta una columna obligatoria el error es del fichero', function () {
    previewImport("fecha;texto\n;Hola\n")->assertUnprocessable()->assertJsonValidationErrors('file')
        ->assertJsonPath('errors.file.0', fn ($m) => str_contains($m, 'cuentas'));
    previewImport("fecha;texto;cuentas;rareza\n;Hola;".importFb().";x\n")->assertUnprocessable()->assertJsonValidationErrors('file');
    previewImport("fecha;texto;cuentas\n")->assertUnprocessable()->assertJsonValidationErrors('file');
    previewImport("fecha;texto;cuentas\n;\xFF\xFE;".importFb()."\n")->assertUnprocessable()->assertJsonValidationErrors('file');
});

test('una segunda confirmacion con el mismo token no duplica posts', function () {
    $token = previewImport(CSV_HEADER."\n;Una sola vez;".importFb().";;;\n")->assertOk()->json('token');

    confirmImport($token)->assertOk()->assertJsonPath('summary.created', 1);
    confirmImport($token)->assertOk()->assertJsonPath('repeated', true);

    expect(Post::withoutUserScope()->count())->toBe(1);
});

test('el token no sirve para otro usuario ni para otra marca y caduca', function () {
    $token = previewImport(CSV_HEADER."\n;Mio;".importFb().";;;\n")->json('token');
    $otherBrand = Brand::factory()->for($this->user)->create();

    confirmImport($token, null, $otherBrand)->assertStatus(410);
    confirmImport($token, User::factory()->create(), $this->brand)->assertNotFound();
    expect(Post::withoutUserScope()->count())->toBe(0);

    Carbon::setTestNow(now()->addMinutes(config('post_import.ttl_minutes') + 1));
    confirmImport($token)->assertStatus(410);
    expect(Post::withoutUserScope()->count())->toBe(0);
});

test('al confirmar se vuelve a validar: una fecha que ya paso entre la vista previa y la confirmacion no se crea', function () {
    $token = previewImport(CSV_HEADER."\n2026-11-01 13:20;Pronto;".importFb().";;;\n")->assertJsonPath('summary.valid', 1)->json('token');

    Carbon::setTestNow('2026-11-01 12:25:00');
    confirmImport($token)->assertOk()->assertJsonPath('summary', ['created' => 0, 'skipped' => 1]);

    expect(Post::withoutUserScope()->count())->toBe(0);
});

test('un usuario ajeno a la marca recibe 404 en todas las rutas', function () {
    $stranger = User::factory()->create();

    $this->actingAs($stranger)->get(route('brands.posts.import.show', $this->brand))->assertNotFound();
    $this->actingAs($stranger)->get(route('brands.posts.import.sample', $this->brand))->assertNotFound();
    previewImport(CSV_HEADER."\n;Hola;".importFb().";;;\n", $stranger)->assertNotFound();
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
});

test('la aprobacion pedida deja el post como borrador pendiente', function () {
    $csv = CSV_HEADER."\n2026-12-01 10:00;Con aprobacion;".importFb().";;;si\n";

    $token = previewImport($csv)->assertJsonPath('rows.0.valid', true)->assertJsonPath('rows.0.approval', true)->json('token');
    confirmImport($token)->assertOk();

    $post = Post::withoutUserScope()->firstOrFail();
    expect($post->approval_status)->toBe(Post::APPROVAL_PENDING)->and($post->status)->toBe(PostStatus::Draft);
});

test('el tipo de contenido se aplica a la cuenta de su red y un tipo sin cuenta es un error', function () {
    $csv = CSV_HEADER."\n"
        .';Con tipo;'.importFb().";facebook_text;;\n"
        .';Tipo ajeno a la fila;'.importFb().";instagram_reel;;\n"
        .';Tipo inventado;'.importFb().";inventado;;\n";

    $res = previewImport($csv)->assertOk();

    $res->assertJsonPath('rows.0.valid', true)->assertJsonPath('rows.1.valid', false)->assertJsonPath('rows.2.valid', false);
});

function sampleCsv(?Brand $brand = null): string
{
    return test()->actingAs(test()->user)->get(route('brands.posts.import.sample', $brand ?? test()->brand))->assertOk()->streamedContent();
}

test('el CSV de ejemplo se descarga y se importa limpio en la marca', function () {
    $res = $this->actingAs($this->user)->get(route('brands.posts.import.sample', $this->brand))->assertOk();
    expect($res->headers->get('content-disposition'))->toContain('importar-publicaciones.csv');
    $content = $res->streamedContent();
    expect(str_starts_with($content, "\xEF\xBB\xBF"))->toBeTrue()->and($content)->toContain(importFb());

    $preview = previewImport($content)->assertOk();

    expect($preview->json('summary.invalid'))->toBe(0)->and($preview->json('summary.total'))->toBeGreaterThanOrEqual(2);
    confirmImport($preview->json('token'))->assertOk()->assertJsonPath('summary.skipped', 0);
});

test('el CSV de ejemplo se importa limpio con una sola cuenta y con una marca que solo tiene redes que exigen media', function () {
    $single = Brand::factory()->for($this->user)->create(['timezone' => 'Europe/Madrid']);
    $only = SocialAccount::factory()->for($this->user)->create(['brand_id' => $single->id, 'network' => Network::Facebook, 'name' => 'Unica']);
    $content = sampleCsv($single);
    expect($content)->toContain(importAcct($only));
    $preview = previewImport($content, null, $single)->assertOk();
    expect($preview->json('summary.invalid'))->toBe(0)->and($preview->json('summary.total'))->toBeGreaterThanOrEqual(1);

    $igBrand = Brand::factory()->for($this->user)->create(['timezone' => 'Europe/Madrid']);
    SocialAccount::factory()->for($this->user)->create(['brand_id' => $igBrand->id, 'network' => Network::Instagram, 'name' => 'Solo Insta']);
    $igContent = sampleCsv($igBrand);
    expect($igContent)->toContain('Las redes de esta marca exigen archivos');
    previewImport($igContent, null, $igBrand)->assertUnprocessable()->assertJsonValidationErrors('file');
});

test('el CSV de ejemplo de una marca sin cuentas no inventa cuentas y explica que hay que conectar una', function () {
    $empty = Brand::factory()->for($this->user)->create();

    $content = sampleCsv($empty);

    expect($content)->toContain('"fecha";"texto";"cuentas"')->and($content)->toContain('# ')->not->toContain('facebook:');
});

test('el CSV de ejemplo respeta el separador de la configuracion comun y nunca produce una celda que empiece por una formula', function () {
    config(['reports.csv_locale' => 'en']);
    $this->fb->forceFill(['name' => '=cmd|calc'])->save();

    $content = sampleCsv();

    expect($content)->toContain('"fecha","texto","cuentas"');
    foreach (preg_split('/\R/', $content) as $line) {
        $line = ltrim($line, "\xEF\xBB\xBF");
        if ($line === '' || str_starts_with($line, '#')) {
            continue;
        }
        expect($line)->not->toMatch('/(^|,)"?[=+\-@]/');
    }
    expect(previewImport($content)->assertOk()->json('summary.invalid'))->toBe(0);
});

test('acepta las fechas de Excel: DD/MM/AAAA, segundos opcionales y T ISO; rechaza lo ambiguo o imposible', function (string $date, ?string $shown, ?string $error) {
    $res = previewImport(CSV_HEADER."\n{$date};Fecha;".importFb().";;;\n")->assertOk();

    if ($error === null) {
        $res->assertJsonPath('rows.0.valid', true)->assertJsonPath('rows.0.date', $shown);
    } else {
        $res->assertJsonPath('rows.0.valid', false);
        expect(implode(' ', $res->json('rows.0.errors')))->toContain($error);
    }
})->with([
    'ISO' => ['2026-12-01 10:00', '2026-12-01 10:00', null],
    'ISO con segundos' => ['2026-12-01 10:00:00', '2026-12-01 10:00', null],
    'ISO con T' => ['2026-12-01T10:00', '2026-12-01 10:00', null],
    'ISO con T y segundos' => ['2026-12-01T10:00:00', '2026-12-01 10:00', null],
    'Excel espanol' => ['27/11/2026 10:00', '2026-11-27 10:00', null],
    'Excel espanol con segundos' => ['27/11/2026 10:00:00', '2026-11-27 10:00', null],
    'dia y mes de una cifra' => ['1/12/2026 9:05', '2026-12-01 09:05', null],
    'MM/DD no se interpreta' => ['11/27/2026 10:00', null, 'mes'],
    'dia imposible' => ['31/02/2026 10:00', null, 'no existe'],
    'mes imposible ISO' => ['2026-13-01 10:00', null, 'no existe'],
    'hora imposible' => ['27/11/2026 25:00', null, 'no existe'],
    'segundos distintos de cero' => ['2026-12-01 10:00:30', null, 'segundos'],
    'anio de dos cifras' => ['27/11/26 10:00', null, 'formato'],
    'solo fecha' => ['2026-12-01', null, 'formato'],
]);

test('dos cuentas de la misma red con el mismo nombre se distinguen por su identificador y nunca se elige la equivocada', function () {
    $twin = SocialAccount::factory()->for($this->user)->create(['brand_id' => $this->brand->id, 'network' => Network::Facebook, 'name' => $this->fb->name, 'username' => null]);
    expect(importAcct($twin))->not->toBe(importFb());
    $csv = CSV_HEADER."\n;Gemela;".importAcct($twin).";;;\n;Por nombre;facebook:".$this->fb->name.";;;\n;Con espacios;facebook: ".$twin->id.' |'.importFb().";;;\n";

    $res = previewImport($csv)->assertOk();

    $res->assertJsonPath('rows.0.valid', true)->assertJsonPath('rows.0.accounts.0.id', $twin->id)
        ->assertJsonPath('rows.1.valid', false)->assertJsonPath('rows.2.valid', true);
    confirmImport($res->json('token'))->assertOk()->assertJsonPath('summary.created', 2);
});

test('una cuenta cuyo nombre lleva barra o espacios en los extremos se importa por su identificador', function () {
    $odd = SocialAccount::factory()->for($this->user)->create(['brand_id' => $this->brand->id, 'network' => Network::Facebook, 'name' => ' Mi | Cuenta ', 'username' => null]);

    previewImport(CSV_HEADER."\n;Rara;".importAcct($odd).";;;\n")->assertOk()->assertJsonPath('rows.0.valid', true);
    expect(previewImport(sampleCsv())->assertOk()->json('summary.invalid'))->toBe(0);
});

test('un identificador de otra red, con el id mal escrito o inexistente es el mismo error', function () {
    $csv = CSV_HEADER."\n;a;instagram:".$this->fb->id.";;;\n;b;facebook:".$this->fb->id."x;;;\n;c;facebook:0;;;\n;d;rara:".$this->fb->id.";;;\n";

    $res = previewImport($csv)->assertOk()->assertJsonPath('summary.valid', 0);

    foreach ($res->json('rows') as $row) {
        expect($row['errors'])->toHaveCount(1);
    }
});

test('un reintento tras confirmar devuelve el mismo resumen y los mismos enlaces sin crear nada', function () {
    $token = previewImport(CSV_HEADER."\n;Una vez;".importFb().";;;\n;Falla;facebook:999999;;;\n")->assertOk()->json('token');

    $first = confirmImport($token)->assertOk();
    $again = confirmImport($token)->assertOk();

    expect($again->json('summary'))->toBe($first->json('summary'))->and($again->json('rows'))->toBe($first->json('rows'))->and($again->json('repeated'))->toBeTrue();
    expect($first->json('repeated'))->toBeFalse()->and(Post::withoutUserScope()->count())->toBe(1);
});

test('si la confirmacion se interrumpio a medias, el reintento no crea nada y dice que filas ya se procesaron', function () {
    $token = previewImport(CSV_HEADER."\n;Primera;".importFb().";;;\n;Segunda;".importFb().";;;\n")->assertOk()->json('token');
    $key = 'post-import:'.$this->user->id.':'.$this->brand->id.':'.$token;
    Cache::put($key.':progress', ['results' => [['row' => 2, 'post_id' => 77]], 'done' => false], now()->addMinutes(10));
    Cache::put($key.':claimed', true, now()->addMinutes(10));

    $res = confirmImport($token)->assertStatus(409);

    $res->assertJsonPath('rows.0.post_id', 77);
    expect(Post::withoutUserScope()->count())->toBe(0);
});

test('subir otra vista previa mientras una confirmacion sigue en curso no la resucita ni permite duplicarla', function () {
    $a = previewImport(CSV_HEADER."\n;A uno;".importFb().";;;\n;A dos;".importFb().";;;\n")->assertOk()->json('token');
    $keyA = 'post-import:'.$this->user->id.':'.$this->brand->id.':'.$a;
    $b = null;
    $fired = false;
    Post::created(function () use (&$b, &$fired) {
        if (! $fired) {
            $fired = true;
            $b = previewImport(CSV_HEADER."\n;B uno;".importFb().";;;\n")->json('token'); // otra pestana sube B mientras A crea filas
        }
    });

    confirmImport($a)->assertOk()->assertJsonPath('summary.created', 2);

    expect(Cache::has($keyA.':claimed'))->toBeTrue()->and(Cache::has($keyA))->toBeFalse();
    confirmImport($a)->assertOk()->assertJsonPath('repeated', true)->assertJsonPath('summary.created', 2);
    expect(Post::withoutUserScope()->count())->toBe(2);
    confirmImport($b)->assertOk()->assertJsonPath('summary.created', 1);
    expect(Post::withoutUserScope()->count())->toBe(3);
});

test('si la confirmacion A murio a mitad y se sube B, reintentar A sigue sin crear nada', function () {
    $a = previewImport(CSV_HEADER."\n;A uno;".importFb().";;;\n")->assertOk()->json('token');
    $keyA = 'post-import:'.$this->user->id.':'.$this->brand->id.':'.$a;
    Cache::put($keyA.':claimed', true, now()->addMinutes(10));
    Cache::put($keyA.':progress', ['results' => [], 'done' => false], now()->addMinutes(10));

    previewImport(CSV_HEADER."\n;B uno;".importFb().";;;\n")->assertOk();

    confirmImport($a)->assertStatus(409);
    expect(Post::withoutUserScope()->count())->toBe(0)->and(Cache::has($keyA.':claimed'))->toBeTrue();
});

test('una vista previa nueva sustituye a la anterior de la misma marca: solo hay una viva por usuario y marca', function () {
    $old = previewImport(CSV_HEADER."\n;Vieja;".importFb().";;;\n")->json('token');
    $new = previewImport(CSV_HEADER."\n;Nueva;".importFb().";;;\n")->json('token');

    confirmImport($old)->assertStatus(410);
    confirmImport($new)->assertOk()->assertJsonPath('summary.created', 1);
    expect(Post::withoutUserScope()->pluck('text')->all())->toBe(['Nueva']);
});

test('una cabecera con miles de columnas da un error corto', function () {
    $header = implode(';', array_map(fn ($i) => 'columna_rara_numero_'.$i, range(1, 3000)));

    $res = previewImport($header."\n;a;b\n")->assertUnprocessable()->assertJsonValidationErrors('file');

    expect(strlen($res->json('errors.file.0')))->toBeLessThan(600);
});
