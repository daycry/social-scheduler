<?php

use App\Jobs\BuildFullExport;
use App\Jobs\QueueProbeJob;
use App\Models\Brand;
use App\Models\BrandCollaborator;
use App\Models\Campaign;
use App\Models\DataExport;
use App\Models\MediaAsset;
use App\Models\Post;
use App\Models\PostTarget;
use App\Models\Report;
use App\Models\SavedText;
use App\Models\Site;
use App\Models\SmartLink;
use App\Models\SmartLinkBlock;
use App\Models\SocialAccount;
use App\Models\Studio\Design;
use App\Models\Studio\DesignExport;
use App\Models\User;
use App\Models\Webhook;
use App\Notifications\AsyncJobDone;
use App\Notifications\AsyncJobFailed;
use App\Services\Export\DiskSpace;
use App\Services\Export\FullExport;
use App\Services\Studio\StudioPptxExporter;
use App\Services\Studio\StudioRenderFailure;
use App\Support\Zip\ZipSourceFailed;
use App\Support\Zip\ZipTooLarge;
use App\Support\Zip\ZipWriteFailed;
use App\Support\Zip\ZipWriter;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;

/**
 * T-84 (F-10) · exportación completa en ZIP: solo datos propios, sin secretos, en cola, con aviso, enlace firmado y limpieza.
 * Los marcadores `PROPIO-*` deben aparecer en el ZIP; `AJENO-*` y los secretos `SECRETO-*` no pueden aparecer en NINGÚN byte descomprimido.
 */
beforeEach(function () {
    Storage::fake('local');
    // Directorio temporal propio de la prueba y latido en cada paso, para poder observarlos.
    config(['exports.tmp_dir' => sys_get_temp_dir().DIRECTORY_SEPARATOR.'fullexport-test-'.uniqid(), 'exports.heartbeat_seconds' => 0]);
    $this->user = User::factory()->create(['notify_by_mail' => false]);
    $this->other = User::factory()->create();
});

afterEach(fn () => File::deleteDirectory(config('exports.tmp_dir')));

/** Ficheros que quedan en el directorio temporal de la exportación. @return list<string> */
function exportTemporaries(): array
{
    return glob(config('exports.tmp_dir').DIRECTORY_SEPARATOR.'*') ?: [];
}

/** Lee un ZIP sin ext-zip: directorio central, inflado y comprobación de CRC y tamaños. @return array<string, string> */
function zipEntries(string $zip): array
{
    $end = strrpos($zip, "PK\x05\x06");
    expect($end)->not->toBeFalse();
    $eocd = unpack('vdisk/vcd/ventriesDisk/ventries/Vsize/Voffset/vcomment', substr($zip, $end + 4, 18));
    $position = $eocd['offset'];
    $entries = [];
    for ($i = 0; $i < $eocd['entries']; $i++) {
        $c = unpack('Vsig/vmade/vneed/vflags/vmethod/vtime/vdate/Vcrc/Vcsize/Vsize/vnlen/velen/vclen/vdisk/vint/Vext/Voff', substr($zip, $position, 46));
        expect($c['sig'])->toBe(0x02014B50);
        $name = substr($zip, $position + 46, $c['nlen']);
        $position += 46 + $c['nlen'] + $c['elen'] + $c['clen'];
        $local = unpack('vnlen/velen', substr($zip, $c['off'] + 26, 4));
        $data = substr($zip, $c['off'] + 30 + $local['nlen'] + $local['elen'], $c['csize']);
        $raw = $c['method'] === 8 ? gzinflate($data) : $data;
        expect(strlen($raw))->toBe($c['size'])->and(hexdec(hash('crc32b', $raw)))->toBe($c['crc']);
        $entries[$name] = $raw;
    }

    return $entries;
}

/** Todo el texto del ZIP descomprimido (y los bytes del propio ZIP), para buscar dentro de los bytes. */
function zipHaystack(string $zip): string
{
    return $zip."\n".implode("\n", zipEntries($zip));
}

/** Datos propios de cada tipo, con un marcador `$tag-*` en cada texto, más los secretos que NUNCA deben salir. @return array<string, mixed> */
function seedExportData(User $user, string $tag, bool $secrets = true): array
{
    $brand = Brand::factory()->for($user)->create(['name' => "$tag-marca"]);
    $account = SocialAccount::factory()->create(['brand_id' => $brand->id, 'name' => "$tag-cuenta", 'access_token' => $secrets ? 'SECRETO-ACCESS-TOKEN' : 'x', 'refresh_token' => $secrets ? 'SECRETO-REFRESH-TOKEN' : 'x', 'credential_secret' => $secrets ? 'SECRETO-APP-PASSWORD' : null]);
    $post = Post::factory()->create(['brand_id' => $brand->id, 'text' => "$tag-publicacion"]);
    DB::table('posts')->where('id', $post->id)->update(['approval_comment' => 'TERCERO-comentario-de-aprobacion']);
    PostTarget::factory()->create(['post_id' => $post->id, 'social_account_id' => $account->id, 'text' => "$tag-texto-destino"]);
    Campaign::factory()->create(['brand_id' => $brand->id, 'name' => "$tag-campana"]);
    SavedText::factory()->create(['user_id' => $user->id, 'title' => "$tag-texto-guardado"]);

    Storage::disk('local')->put("media/$tag/foto.jpg", "$tag-BYTES-DE-MEDIA");
    $media = MediaAsset::factory()->create(['user_id' => $user->id, 'disk' => 'local', 'path' => "media/$tag/foto.jpg", 'original_name' => "$tag-foto.jpg", 'size' => 40]);

    Storage::disk('local')->put("reports/$tag/informe.pdf", "$tag-BYTES-DE-INFORME");
    Report::factory()->create(['user_id' => $user->id, 'brand_id' => $brand->id, 'format' => 'pdf', 'status' => Report::STATUS_READY, 'path' => "reports/$tag/informe.pdf", 'size' => 30]);
    DB::table('report_templates')->insert(['user_id' => $user->id, 'brand_id' => $brand->id, 'name' => "$tag-plantilla-informe", 'blocks' => '[]']);

    $design = Design::unguarded(fn () => Design::create(['user_id' => $user->id, 'brand_id' => $brand->id, 'name' => "$tag-diseno", 'document' => ['pages' => [], 'nota' => "$tag-documento"], 'width' => 10, 'height' => 10, 'page_count' => 1, 'revision' => 1]));
    $designExport = DesignExport::create(['user_id' => $user->id, 'brand_id' => $brand->id, 'design_id' => $design->id, 'revision' => 1, 'format' => 'png', 'pages' => ['p1'], 'media_ids' => [], 'status' => 'ready', 'disk' => 'local', 'path' => "studio-exports/{$user->id}/1/page-001.png"]);
    Storage::disk('local')->put($designExport->path, "$tag-BYTES-DE-EXPORTACION");

    $site = Site::create(['user_id' => $user->id, 'brand_id' => $brand->id, 'slug' => strtolower($tag).'-sitio', 'title' => "$tag-sitio", 'draft' => ['hero' => "$tag-contenido"], 'password_hash' => $secrets ? 'SECRETO-HASH-DEL-SITIO' : null]);
    $link = (new SmartLink)->forceFill(['user_id' => $user->id, 'brand_id' => $brand->id, 'slug' => strtolower($tag).'-link', 'title' => "$tag-smartlink"]);
    $link->save();
    $block = (new SmartLinkBlock)->forceFill(['smart_link_id' => $link->id, 'type' => 'link', 'label' => "$tag-bloque", 'url' => 'https://example.com']);
    $block->save();
    $template = DB::table('post_templates')->insertGetId(['user_id' => $user->id, 'brand_id' => $brand->id, 'name' => "$tag-plantilla"]);
    DB::table('post_template_media')->insert(['post_template_id' => $template, 'media_asset_id' => $media->id, 'position' => 0]);
    DB::table('smart_link_views')->insert(['smart_link_id' => $link->id, 'date' => '2026-11-01', 'count' => 7]);
    DB::table('smart_link_clicks')->insert(['smart_link_id' => $link->id, 'smart_link_block_id' => $block->id, 'date' => '2026-11-01', 'count' => 3]);
    DB::table('site_visits')->insert(['site_id' => $site->id, 'page_key' => 'home', 'date' => '2026-11-01', 'count' => 5]);
    DB::table('goals')->insert(['user_id' => $user->id, 'brand_id' => $brand->id, 'name' => "$tag-objetivo", 'kind' => 'milestone', 'threshold' => 10]);

    if ($secrets) {
        (new Webhook)->forceFill(['user_id' => $user->id, 'brand_id' => $brand->id, 'name' => "$tag-webhook", 'url' => 'https://example.com/SECRETO-URL-DE-WEBHOOK', 'events' => ['post.published'], 'secret' => 'SECRETO-DEL-WEBHOOK'])->save();
        DB::table('passkeys')->insert(['user_id' => $user->id, 'name' => "$tag-passkey", 'credential_id' => "SECRETO-PASSKEY-ID-$tag", 'credential' => 'SECRETO-PASSKEY-CREDENCIAL']);
        DB::table('push_subscriptions')->insert(['user_id' => $user->id, 'endpoint_hash' => sha1("$tag-endpoint"), 'endpoint' => encrypt('https://push.example.com/SECRETO-ENDPOINT'), 'keys' => encrypt('SECRETO-CLAVES-PUSH'), 'device_name' => "$tag-movil", 'last_seen_at' => now()]);
        $user->forceFill(['two_factor_secret' => 'SECRETO-2FA', 'two_factor_recovery_codes' => ['SECRETO-CODIGO-RECUPERACION'], 'two_factor_confirmed_at' => now()])->save();
    }

    return ['brand' => $brand, 'media' => $media, 'design' => $design];
}

/** Pide la exportación por la ruta real (la cola no ejecuta nada) y devuelve la fila creada. */
function requestExport(User $user, bool $includeMedia = true): DataExport
{
    Bus::fake([BuildFullExport::class]);
    test()->actingAs($user)->post(route('profile.export.store'), ['include_media' => $includeMedia ? 1 : 0])->assertRedirect();

    return DataExport::ownedBy($user)->latest('id')->firstOrFail();
}

function runExport(DataExport $export): DataExport
{
    (new BuildFullExport($export->id))->handle(app(FullExport::class));

    return $export->refresh();
}

function exportZip(DataExport $export): string
{
    return Storage::disk('local')->get($export->path);
}

test('el ZIP contiene los datos propios de cada tipo, la media original y un manifiesto con SHA-256', function () {
    seedExportData($this->user, 'PROPIO');
    $export = runExport(requestExport($this->user));

    expect($export->status)->toBe(DataExport::STATUS_READY)->and($export->path)->toStartWith('full-exports/'.$this->user->id.'/')->and($export->size)->toBeGreaterThan(0)
        ->and($export->expires_at->isFuture())->toBeTrue();
    $entries = zipEntries(exportZip($export));
    $text = implode("\n", array_filter($entries, fn ($content, $name) => str_ends_with($name, '.json'), ARRAY_FILTER_USE_BOTH));

    foreach (['marca', 'cuenta', 'publicacion', 'texto-destino', 'campana', 'texto-guardado', 'plantilla-informe', 'diseno', 'documento', 'sitio', 'contenido', 'smartlink', 'bloque', 'plantilla', 'objetivo', 'foto.jpg'] as $marker) {
        expect($text)->toContain("PROPIO-$marker");
    }
    expect(implode('', array_keys($entries)))->toContain('LEEME.txt')->and($entries)->toHaveKey('manifest.json');
    $files = collect($entries)->filter(fn ($c, $n) => preg_match('#^(medios|informes|estudio/exportaciones)/#', $n));
    expect($files->values()->all())->toContain('PROPIO-BYTES-DE-MEDIA')->toContain('PROPIO-BYTES-DE-INFORME')->toContain('PROPIO-BYTES-DE-EXPORTACION');

    $manifest = json_decode($entries['manifest.json'], true, flags: JSON_THROW_ON_ERROR);
    expect($manifest)->toHaveKeys(['generated_at', 'format_version', 'app', 'files'])->and($manifest['files'])->not->toBeEmpty();
    foreach ($manifest['files'] as $file) {
        expect($entries)->toHaveKey($file['path'])->and(hash('sha256', $entries[$file['path']]))->toBe($file['sha256'])->and(strlen($entries[$file['path']]))->toBe($file['size']);
    }
    expect(collect($manifest['files'])->pluck('path'))->not->toContain('manifest.json')->and($manifest['include_media'])->toBeTrue();
    foreach (['medios-de-plantilla', 'visitas-de-smart-links', 'clics-de-smart-links', 'visitas-de-sitios'] as $entity) {
        expect($manifest['entities'][$entity])->toBe(1);
    }
    // El comentario de aprobación lo escribe un colaborador: no va; el estado de aprobación sí.
    expect(zipHaystack(exportZip($export)))->not->toContain('TERCERO-comentario-de-aprobacion')->and($entries['datos/publicaciones.json'])->toContain('approval_status');
});

test('no contiene datos de otro usuario ni de una marca donde solo es colaborador', function () {
    seedExportData($this->user, 'PROPIO');
    $theirs = seedExportData($this->other, 'AJENO');
    BrandCollaborator::create(['brand_id' => $theirs['brand']->id, 'owner_id' => $this->other->id, 'user_id' => $this->user->id, 'role' => BrandCollaborator::ROLE_APPROVER]);
    DB::table('inbox_comments')->insert(['user_id' => $this->other->id, 'brand_id' => $theirs['brand']->id, 'social_account_id' => SocialAccount::withoutUserScope()->where('user_id', $this->other->id)->value('id'),
        'network' => 'instagram', 'external_id' => 'c-ajeno', 'author_name' => 'AJENO-autor', 'text' => 'AJENO-comentario']);

    $haystack = zipHaystack(exportZip(runExport(requestExport($this->user))));

    expect($haystack)->toContain('PROPIO-marca')->not->toContain('AJENO')->not->toContain($this->other->email);
});

test('no contiene ningún secreto ni credencial, ni en claro ni cifrados en bruto', function () {
    $this->user->createToken('clave', ['*']);
    $plainToken = $this->user->createToken('otra', ['*'])->plainTextToken;
    seedExportData($this->user, 'PROPIO');
    $user = $this->user->refresh();
    $raw = fn (string $table, string $column) => (string) DB::table($table)->where('user_id', $user->id)->value($column);

    $haystack = zipHaystack(exportZip(runExport(requestExport($this->user))));

    foreach (['SECRETO-ACCESS-TOKEN', 'SECRETO-REFRESH-TOKEN', 'SECRETO-APP-PASSWORD', 'SECRETO-HASH-DEL-SITIO', 'SECRETO-URL-DE-WEBHOOK', 'SECRETO-DEL-WEBHOOK', 'SECRETO-PASSKEY', 'SECRETO-ENDPOINT', 'SECRETO-CLAVES-PUSH', 'SECRETO-2FA', 'SECRETO-CODIGO-RECUPERACION', $plainToken, explode('|', $plainToken)[1],
        $raw('social_accounts', 'access_token'), $raw('webhooks', 'secret'), $raw('push_subscriptions', 'endpoint'), (string) DB::table('users')->where('id', $user->id)->value('two_factor_secret'),
        (string) DB::table('users')->where('id', $user->id)->value('password'), (string) DB::table('personal_access_tokens')->where('tokenable_id', $user->id)->value('token'), config('app.key')] as $secret) {
        expect($secret)->not->toBe('')->and($haystack)->not->toContain($secret);
    }
    expect($haystack)->toContain('PROPIO-cuenta');
});

test('los comentarios recibidos de terceros no van en el ZIP, sí las respuestas propias', function () {
    $seed = seedExportData($this->user, 'PROPIO');
    $account = SocialAccount::withoutUserScope()->where('user_id', $this->user->id)->value('id');
    foreach ([['c1', 'TERCERO-autor', 'TERCERO-comentario', 0], ['c2', 'PROPIO-autor', 'PROPIO-respuesta', 1]] as [$id, $author, $text, $own]) {
        DB::table('inbox_comments')->insert(['user_id' => $this->user->id, 'brand_id' => $seed['brand']->id, 'social_account_id' => $account, 'network' => 'instagram', 'external_id' => $id, 'author_name' => $author, 'text' => $text, 'is_own' => $own]);
    }

    $haystack = zipHaystack(exportZip(runExport(requestExport($this->user))));

    expect($haystack)->toContain('PROPIO-respuesta')->not->toContain('TERCERO');
});

test('el job avisa al terminar con la notificación de la app y enlaza a la página', function () {
    seedExportData($this->user, 'PROPIO');
    runExport(requestExport($this->user));

    $notice = $this->user->notifications()->where('data->type', 'job_done_full_export')->first();
    expect($notice)->not->toBeNull()->and($notice->data['url'])->toBe(route('profile.export', [], false))->and($notice->data['title'])->not->toBeEmpty();
});

test('el aviso de fin va solo a la app y el de fallo también por correo si el usuario lo activó', function () {
    $this->user->forceFill(['notify_by_mail' => true])->save();
    Notification::fake();
    $done = runExport(requestExport($this->user));
    Notification::assertSentTo($this->user, AsyncJobDone::class, fn (AsyncJobDone $n, array $channels) => $channels === ['database'] && $n->kind === 'full_export');

    config(['exports.max_bytes' => 100]);
    $failed = runExport(requestExport($this->user));
    expect($failed->status)->toBe(DataExport::STATUS_FAILED);
    Notification::assertSentTo($this->user, AsyncJobFailed::class, fn (AsyncJobFailed $n, array $channels) => $channels === ['database', 'mail'] && $n->kind === 'full_export');
    // La copia lista anterior sigue ahí: el fallo de la nueva no la borra.
    expect($done->refresh()->status)->toBe(DataExport::STATUS_READY);
    Storage::disk('local')->assertExists($done->path);
});

test('solo puede haber una exportación en curso por usuario', function () {
    $first = requestExport($this->user);
    expect($first->status)->toBe(DataExport::STATUS_PENDING)->and($first->in_progress)->toBeTrue();
    Bus::assertDispatchedTimes(BuildFullExport::class, 1);

    Bus::fake([BuildFullExport::class]);
    $this->actingAs($this->user)->post(route('profile.export.store'))->assertRedirect()->assertSessionHas('error');
    Bus::assertNothingDispatched();
    expect(DataExport::ownedBy($this->user)->count())->toBe(1);

    // La restricción vive en la base de datos: ni siquiera una carrera puede crear otra en curso.
    expect(fn () => DataExport::create(['user_id' => $this->user->id, 'status' => DataExport::STATUS_PENDING, 'in_progress' => true]))->toThrow(UniqueConstraintViolationException::class);
    // Otro usuario no está bloqueado por el primero.
    expect(requestExport($this->other)->user_id)->toBe($this->other->id);

    // Terminada, se puede pedir otra; la anterior sigue disponible hasta que la nueva se publique bien y entonces se sustituye.
    runExport($first);
    $oldPath = $first->path;
    $second = requestExport($this->user);
    expect($second->id)->not->toBe($first->id)->and(DataExport::ownedBy($this->user)->count())->toBe(2);
    Storage::disk('local')->assertExists($oldPath);
    $this->actingAs($this->user)->get(route('profile.export'))->assertInertia(fn (Assert $page) => $page->where('latest.status', 'pending')->where('download.url', route('profile.export.link', $first)));
    runExport($second);
    Storage::disk('local')->assertMissing($oldPath);
    expect(DataExport::ownedBy($this->user)->count())->toBe(1)->and($second->refresh()->status)->toBe(DataExport::STATUS_READY);
});

test('el enlace firmado descarga el ZIP y caduca', function () {
    seedExportData($this->user, 'PROPIO');
    $export = runExport(requestExport($this->user));

    $signed = $this->actingAs($this->user)->get(route('profile.export.link', $export))->assertRedirect()->headers->get('Location');
    $this->actingAs($this->user)->get($signed)->assertOk()->assertHeader('X-Content-Type-Options', 'nosniff');
    expect($signed)->toContain('signature=')->and($signed)->toContain('expires=');

    $this->travel(config('exports.link_minutes') + 1)->minutes();
    $this->actingAs($this->user)->get($signed)->assertForbidden();
});

test('el enlace de otro usuario no sirve, ni su botón, ni un enlace manipulado', function () {
    $export = runExport(requestExport($this->user));
    $signed = $this->actingAs($this->user)->get(route('profile.export.link', $export))->headers->get('Location');

    $this->actingAs($this->other)->get(route('profile.export.link', $export))->assertNotFound();
    $this->actingAs($this->other)->get($signed)->assertForbidden();
    auth()->logout();
    $this->get($signed)->assertRedirect(route('login'));

    $tampered = str_replace('/download/'.$this->user->id, '/download/'.$this->other->id, $signed);
    expect($tampered)->not->toBe($signed);
    $this->actingAs($this->other)->get($tampered)->assertForbidden();
    $this->actingAs($this->user)->get($signed.'0')->assertForbidden();
});

test('un ZIP caducado o aún en curso no se descarga', function () {
    $export = requestExport($this->user);
    $this->actingAs($this->user)->get(route('profile.export.link', $export))->assertNotFound();

    runExport($export);
    $this->travel(config('exports.retention_hours') + 1)->hours();
    $this->actingAs($this->user)->get(route('profile.export.link', $export))->assertNotFound();
});

test('superar el tope de tamaño deja el estado en error, avisa y no publica ningún ZIP', function () {
    seedExportData($this->user, 'PROPIO');
    Storage::disk('local')->put('media/grande.bin', random_bytes(60_000));
    MediaAsset::factory()->create(['user_id' => $this->user->id, 'disk' => 'local', 'path' => 'media/grande.bin', 'original_name' => 'grande.bin', 'size' => 60_000]);
    config(['exports.max_bytes' => 20_000]);
    $export = requestExport($this->user);

    runExport($export);

    // Con media, el mensaje ofrece exportar sin ella en vez de pedir que se borren archivos.
    expect($export->status)->toBe(DataExport::STATUS_FAILED)->and($export->path)->toBeNull()->and($export->in_progress)->toBeNull()->and($export->error)->toContain('tope')->toContain('sin incluir los archivos de media');
    expect(Storage::disk('local')->allFiles('full-exports'))->toBe([])->and(exportTemporaries())->toBe([]);
    $notice = $this->user->notifications()->where('data->type', 'job_failed_full_export')->first();
    expect($notice)->not->toBeNull()->and($notice->data['url'])->toBe(route('profile.export', [], false));
    // Fallado, se puede volver a pedir.
    expect(requestExport($this->user)->id)->not->toBe($export->id);
});

test('un fallo del job deja el estado en error, avisa y no deja ficheros huérfanos', function () {
    seedExportData($this->user, 'PROPIO');
    MediaAsset::factory()->create(['user_id' => $this->user->id, 'disk' => 'disco-que-no-existe', 'path' => 'media/x.jpg', 'original_name' => 'x.jpg']);
    $export = requestExport($this->user);

    expect(fn () => (new BuildFullExport($export->id))->handle(app(FullExport::class)))->toThrow(InvalidArgumentException::class);

    $export->refresh();
    expect($export->status)->toBe(DataExport::STATUS_FAILED)->and($export->path)->toBeNull()->and($export->in_progress)->toBeNull()->and($export->error)->not->toContain('disco-que-no-existe');
    expect(Storage::disk('local')->allFiles('full-exports'))->toBe([])->and(exportTemporaries())->toBe([]);
    expect($this->user->notifications()->where('data->type', 'job_failed_full_export')->count())->toBe(1);
});

test('failed() del job cubre un timeout: marca el error, borra restos y avisa una sola vez', function () {
    $export = requestExport($this->user);
    Storage::disk('local')->put("full-exports/{$this->user->id}/{$export->id}.zip", 'a medias');
    File::ensureDirectoryExists(config('exports.tmp_dir'));
    file_put_contents($export->temporaryPath(), 'temporal a medias');

    (new BuildFullExport($export->id))->failed(new RuntimeException('timeout'));
    (new BuildFullExport($export->id))->failed(new RuntimeException('timeout'));

    expect($export->refresh()->status)->toBe(DataExport::STATUS_FAILED)->and(Storage::disk('local')->allFiles('full-exports'))->toBe([])->and(exportTemporaries())->toBe([])
        ->and($this->user->notifications()->where('data->type', 'job_failed_full_export')->count())->toBe(1);
});

test('la limpieza borra los caducados y libera los atascados', function () {
    seedExportData($this->user, 'PROPIO');
    $old = runExport(requestExport($this->user));
    $oldPath = $old->path;
    $old->forceFill(['expires_at' => now()->subMinute()])->save();
    $keep = runExport(requestExport($this->other));
    $stuck = DataExport::create(['user_id' => User::factory()->create()->id, 'status' => DataExport::STATUS_PROCESSING, 'in_progress' => true]);
    $stuck->forceFill(['updated_at' => now()->subMinutes(config('exports.stuck_minutes') + 1)])->saveQuietly();

    $this->artisan('exports:prune')->assertSuccessful();

    Storage::disk('local')->assertMissing($oldPath);
    Storage::disk('local')->assertExists($keep->path);
    expect($old->refresh()->status)->toBe(DataExport::STATUS_EXPIRED)->and($old->path)->toBeNull()
        ->and($keep->refresh()->status)->toBe(DataExport::STATUS_READY)
        ->and($stuck->refresh()->status)->toBe(DataExport::STATUS_FAILED)->and($stuck->in_progress)->toBeNull();
    expect(collect(app(Schedule::class)->events())->contains(fn ($e) => str_contains($e->command, 'exports:prune')))->toBeTrue();
});

test('la página muestra el estado y un enlace de descarga solo cuando hay una copia lista', function () {
    $this->actingAs($this->user)->get(route('profile.export'))->assertInertia(fn (Assert $page) => $page->component('Profile/Export')->where('latest', null)->where('download', null)
        ->where('limits.retention_hours', config('exports.retention_hours'))->has('limits.max_gb'));

    $export = runExport(requestExport($this->user));
    $this->actingAs($this->user)->get(route('profile.export'))->assertInertia(fn (Assert $page) => $page->where('latest.status', 'ready')->where('download.url', route('profile.export.link', $export))
        ->missing('download.path')->missing('latest.path'));
    $this->actingAs($this->other)->get(route('profile.export'))->assertInertia(fn (Assert $page) => $page->where('latest', null)->where('download', null));
});

test('la migración de full_exports se puede deshacer', function () {
    $migration = require database_path('migrations/2026_11_26_090000_create_full_exports_table.php');

    $migration->down();
    expect(Schema::hasTable('full_exports'))->toBeFalse();
    $migration->up();
    expect(Schema::hasTable('full_exports'))->toBeTrue()->and(Schema::hasColumns('full_exports', ['user_id', 'status', 'path', 'size', 'expires_at', 'error', 'in_progress', 'include_media', 'heartbeat_at']))->toBeTrue();
});

test('borrar la cuenta borra sus exportaciones y sus ficheros', function () {
    $export = runExport(requestExport($this->user));
    Storage::disk('local')->assertExists($export->path);

    $this->user->delete();

    expect(DataExport::withoutUserScope()->count())->toBe(0);
    Storage::disk('local')->assertMissing($export->path);
});

/** Segundo lector, independiente del escritor PHP: `zipfile` de Python (scripts/export/zip_check.py). Omite el test si no hay Python. @return array{bad: ?string, zip64: bool, entries: array<string, array{0: int, 1: string}>} */
function pythonZipCheck(string $path): array
{
    foreach (['python3', 'python'] as $python) {
        if (! Process::run([$python, '--version'])->successful()) {
            continue;
        }
        $result = Process::path(base_path())->timeout(120)->run([$python, '-I', base_path('scripts/export/zip_check.py'), $path]);
        expect($result->successful())->toBeTrue($result->errorOutput());

        return json_decode($result->output(), true, flags: JSON_THROW_ON_ERROR);
    }
    test()->markTestSkipped('No hay Python para el lector independiente.');
}

/** Escribe un ZIP a un fichero temporal de la prueba y devuelve su ruta. */
function zipTo(callable $fill, ?int $maxBytes = null, int $zip64At = 0xFFFFFFFF): string
{
    File::ensureDirectoryExists(config('exports.tmp_dir'));
    $path = config('exports.tmp_dir').DIRECTORY_SEPARATOR.uniqid('verify-').'.zip';
    $handle = fopen($path, 'w+b');
    $zip = new ZipWriter($handle, $maxBytes, null, $zip64At);
    $fill($zip);
    $zip->finish();
    fclose($handle);

    return $path;
}

test('un lector independiente (zipfile de Python) abre el ZIP normal y el ZIP64 con el mismo contenido', function (int $zip64At, bool $zip64) {
    $payload = random_bytes(2_500_000).str_repeat('ab', 500_000);
    $local = config('exports.tmp_dir').DIRECTORY_SEPARATOR.'foto.png';
    File::ensureDirectoryExists(config('exports.tmp_dir'));
    file_put_contents($local, str_repeat('PNG', 2000));
    $path = zipTo(function (ZipWriter $zip) use ($payload, $local) {
        $zip->addString('datos/ñandú.json', '{"a":1}');
        $zip->addString('vacio.txt', '');
        $zip->addString('oficio/mimetype', 'application/x-sin-comprimir', false);
        $zip->addFile('estudio/foto.png', $local);
        $stream = fopen('php://temp', 'w+b');
        fwrite($stream, $payload);
        rewind($stream);
        $zip->addStream('medios/grande.bin', $stream);
    }, null, $zip64At);

    $check = pythonZipCheck($path);

    $expected = ['datos/ñandú.json' => '{"a":1}', 'vacio.txt' => '', 'oficio/mimetype' => 'application/x-sin-comprimir', 'estudio/foto.png' => str_repeat('PNG', 2000), 'medios/grande.bin' => $payload];
    expect($check['bad'])->toBeNull()->and(array_keys($check['entries']))->toBe(array_keys($expected))
        ->and(str_contains(file_get_contents($path), "PK\x06\x06"))->toBe($zip64);
    foreach ($expected as $name => $content) {
        expect($check['entries'][$name])->toBe([strlen($content), hash('sha256', $content)]);
    }
    // El método sin comprimir se conserva (PPTX): la cabecera de la entrada dice 0.
    expect(str_contains(file_get_contents($path), "PK\x03\x04\x14\x00\x00\x08\x00\x00"))->toBeTrue();
})->with([
    'ZIP normal' => [0xFFFFFFFF, false],
    'ZIP64 (umbral bajo: tamaños y desplazamientos ≥ 1 KiB usan campos de 64 bits)' => [1024, true],
]);

test('el ZIP admite más de 65 535 entradas con registro y localizador ZIP64', function () {
    $path = zipTo(function (ZipWriter $zip) {
        for ($i = 0; $i < 65_540; $i++) {
            $zip->addString("f/$i.txt", (string) $i);
        }
    });

    $check = pythonZipCheck($path);

    expect($check['bad'])->toBeNull()->and($check['entries'])->toHaveCount(65_540)->and($check['entries']['f/65539.txt'])->toBe([5, hash('sha256', '65539')])
        ->and(str_contains(file_get_contents($path), "PK\x06\x07"))->toBeTrue();
});

test('el escritor corta al superar su tope de bytes', function () {
    expect(fn () => zipTo(fn (ZipWriter $zip) => $zip->addString('a.bin', random_bytes(5000)), 1000))->toThrow(ZipTooLarge::class);
});

test('sin media el ZIP solo trae los datos en JSON y el manifiesto; el tope ya no pide borrar archivos', function () {
    seedExportData($this->user, 'PROPIO');
    $export = runExport(requestExport($this->user, includeMedia: false));

    $entries = zipEntries(exportZip($export));
    $manifest = json_decode($entries['manifest.json'], true, flags: JSON_THROW_ON_ERROR);
    expect($export->include_media)->toBeFalse()->and($manifest['include_media'])->toBeFalse()->and($entries)->toHaveKeys(['datos/marcas.json', 'LEEME.txt'])
        ->and(zipHaystack(exportZip($export)))->not->toContain('PROPIO-BYTES-DE');
    foreach (array_keys($entries) as $name) {
        expect($name)->not->toStartWith('medios/')->not->toStartWith('informes/')->not->toStartWith('estudio/exportaciones/');
    }
    // Los datos del Estudio en JSON sí van.
    expect(collect(array_keys($entries))->contains(fn ($name) => str_starts_with($name, 'estudio/disenos/')))->toBeTrue();

    config(['exports.max_bytes' => 100]);
    $big = runExport(requestExport($this->user, includeMedia: false));
    expect($big->error)->toContain('tope')->not->toContain('sin incluir');
});

test('sin espacio libre para el ZIP la exportación falla antes de empezar, con un mensaje claro y sin restos', function () {
    config(['exports.free_space_margin' => 1_000_000_000_000_000]);
    $export = runExport(requestExport($this->user));

    expect($export->status)->toBe(DataExport::STATUS_FAILED)->and($export->error)->toContain('espacio libre')->and($export->in_progress)->toBeNull()
        ->and(Storage::disk('local')->allFiles('full-exports'))->toBe([])->and(exportTemporaries())->toBe([]);
});

test('el job late mientras trabaja', function () {
    seedExportData($this->user, 'PROPIO');
    $beats = 0;
    DB::listen(function ($query) use (&$beats) {
        $beats += str_contains($query->sql, 'update "full_exports" set "heartbeat_at"') ? 1 : 0;
    });

    $export = runExport(requestExport($this->user));

    expect($export->status)->toBe(DataExport::STATUS_READY)->and($beats)->toBeGreaterThan(5)->and($export->heartbeat_at)->not->toBeNull();
});

test('si la fila desaparece mientras el job escribe, no publica el ZIP y borra el temporal', function () {
    seedExportData($this->user, 'PROPIO');
    $export = requestExport($this->user);
    $deleted = false;
    DB::listen(function ($query) use (&$deleted, $export) {
        if (! $deleted && str_contains($query->sql, 'from "media_assets"')) {
            $deleted = true;
            DB::table('full_exports')->where('id', $export->id)->delete();
        }
    });

    (new BuildFullExport($export->id))->handle(app(FullExport::class));

    expect($deleted)->toBeTrue()->and(DataExport::withoutUserScope()->count())->toBe(0)->and(Storage::disk('local')->allFiles('full-exports'))->toBe([])->and(exportTemporaries())->toBe([]);
});

test('el atasco se mide sobre el latido, no sobre updated_at', function () {
    $old = now()->subMinutes(config('exports.stuck_minutes') + 1);
    $row = fn (string $status, ?object $beat, bool $stale = true) => tap(DataExport::create(['user_id' => User::factory()->create()->id, 'status' => $status, 'in_progress' => true, 'heartbeat_at' => $beat]), fn ($e) => $e->forceFill(['updated_at' => $old])->saveQuietly());
    $dead = $row(DataExport::STATUS_PROCESSING, $old);
    $alive = $row(DataExport::STATUS_PROCESSING, now()); // updated_at viejo pero latido reciente: sigue vivo
    $queued = $row(DataExport::STATUS_PENDING, null); // esperando su turno en la cola: updated_at viejo no es un atasco

    $this->artisan('exports:prune')->assertSuccessful();

    expect($dead->refresh()->status)->toBe(DataExport::STATUS_FAILED)->and($dead->in_progress)->toBeNull()->and($dead->error)->toBe(FullExport::STUCK_ERROR)
        ->and($alive->refresh()->status)->toBe(DataExport::STATUS_PROCESSING)->and($alive->in_progress)->toBeTrue()
        ->and($queued->refresh()->status)->toBe(DataExport::STATUS_PENDING)->and($queued->in_progress)->toBeTrue();
});

test('una exportación pendiente solo se da por atascada pasado su propio umbral, medido desde que se pidió', function () {
    $old = fn (int $minutes) => tap(DataExport::create(['user_id' => User::factory()->create()->id, 'status' => DataExport::STATUS_PENDING, 'in_progress' => true]), fn ($e) => $e->forceFill(['created_at' => now()->subMinutes($minutes), 'updated_at' => now()->subMinutes($minutes)])->saveQuietly());
    $waiting = $old(config('exports.pending_minutes') - 30);
    $lost = $old(config('exports.pending_minutes') + 1);

    $this->artisan('exports:prune')->assertSuccessful();

    expect($waiting->refresh()->status)->toBe(DataExport::STATUS_PENDING)->and($lost->refresh()->status)->toBe(DataExport::STATUS_FAILED);
});

test('la limpieza barre los ZIP sin fila y los temporales viejos, sin tocar los vigentes', function () {
    $ready = runExport(requestExport($this->user));
    $running = DataExport::create(['user_id' => $this->other->id, 'status' => DataExport::STATUS_PROCESSING, 'in_progress' => true, 'heartbeat_at' => now()]);
    $failed = DataExport::create(['user_id' => User::factory()->create()->id, 'status' => DataExport::STATUS_FAILED]);
    $disk = Storage::disk('local');
    $disk->put(DataExport::pathFor($this->other->id, $running->id), 'en curso');
    $disk->put(DataExport::pathFor($failed->user_id, $failed->id), 'de una fila fallida');
    $disk->put('full-exports/9/999.zip', 'sin fila');
    $disk->put('full-exports/notas.txt', 'ajeno al formato');
    File::ensureDirectoryExists(config('exports.tmp_dir'));
    $stale = config('exports.tmp_dir').DIRECTORY_SEPARATOR.'1-1.zip.part';
    $fresh = config('exports.tmp_dir').DIRECTORY_SEPARATOR.'1-2.zip.part';
    file_put_contents($stale, 'viejo');
    file_put_contents($fresh, 'reciente');
    touch($stale, time() - (config('exports.stuck_minutes') + 5) * 60);

    $this->artisan('exports:prune')->assertSuccessful();

    expect($disk->allFiles('full-exports'))->toEqualCanonicalizing([$ready->path, DataExport::pathFor($this->other->id, $running->id)])
        ->and(file_exists($stale))->toBeFalse()->and(file_exists($fresh))->toBeTrue();
});

test('el job va por la conexión de cola propia `exports` y el retry_after de `database` no cambia', function () {
    $job = new BuildFullExport(1);

    expect($job->connection)->toBe('exports')->and($job->queue)->toBe('exports')
        ->and(config('queue.connections.exports.driver'))->toBe('database')
        ->and(config('queue.connections.exports.table'))->toBe(config('queue.connections.database.table'))
        ->and(config('queue.connections.exports.queue'))->toBe('exports')
        // El retry_after propio supera el timeout del job (si no, otro worker lo reservaría mientras sigue en curso)...
        ->and(config('queue.connections.exports.retry_after'))->toBeGreaterThan(config('exports.timeout'))
        // ...y el de la conexión compartida con publish/poll sigue siendo el de siempre.
        ->and(config('queue.connections.database.retry_after'))->toBe(180);
});

test('app:queue-probe sondea la cola exports en su propia conexión', function () {
    Queue::fake();

    Artisan::call('app:queue-probe');

    Queue::assertPushed(QueueProbeJob::class, fn (QueueProbeJob $probe) => $probe->queue === 'exports' && $probe->connection === 'exports');
    Queue::assertPushed(QueueProbeJob::class, fn (QueueProbeJob $probe) => $probe->queue === 'media' && $probe->connection === null);
});

/** Medidor de espacio simulado: `$tmp` y `$dest` son el espacio libre del temporal y del destino. */
function fakeSpace(float|int|false $tmp, float|int|false $dest, bool $sameVolume): DiskSpace
{
    return new class($tmp, $dest, $sameVolume) extends DiskSpace
    {
        public function __construct(private readonly float|int|false $tmp, private readonly float|int|false $dest, private readonly bool $same) {}

        public function free(string $directory): float|int|false
        {
            return $directory === config('exports.tmp_dir') ? $this->tmp : $this->dest;
        }

        public function sameVolume(string $a, string $b): bool
        {
            return $this->same;
        }
    };
}

/** Ejecuta la exportación con un medidor de espacio simulado y 10 000 bytes de media. */
function runWithSpace(DiskSpace $space): DataExport
{
    config(['exports.free_space_margin' => 1000]);
    DB::table('media_assets')->update(['size' => 10_000]);
    $export = requestExport(test()->user);
    app()->instance(DiskSpace::class, $space);

    return runExport($export);
}

test('en el mismo volumen se publica con rename y solo se exige espacio para UNA copia', function () {
    seedExportData($this->user, 'PROPIO');
    // Sobra para una copia (≈ 10 100 + 1000) pero no para dos: con copia haría falta el doble.
    $export = runWithSpace(fakeSpace(11_500, 11_500, true));

    expect($export->status)->toBe(DataExport::STATUS_READY)->and(exportTemporaries())->toBe([]);
    Storage::disk('local')->assertExists($export->path);
    expect(zipEntries(exportZip($export)))->toHaveKey('manifest.json');
});

test('si el destino no comparte volumen con el temporal exige espacio también para la copia en el destino', function () {
    seedExportData($this->user, 'PROPIO');
    $export = runWithSpace(fakeSpace(50_000, 5_000, false));

    expect($export->status)->toBe(DataExport::STATUS_FAILED)->and($export->error)->toContain('espacio libre')->and(exportTemporaries())->toBe([])
        ->and(Storage::disk('local')->allFiles('full-exports'))->toBe([]);

    $ok = runWithSpace(fakeSpace(50_000, 50_000, false));
    expect($ok->status)->toBe(DataExport::STATUS_READY);
    Storage::disk('local')->assertExists($ok->path);
});

test('si no se puede medir el espacio libre falla de forma cerrada con un mensaje claro', function () {
    seedExportData($this->user, 'PROPIO');
    $export = runWithSpace(fakeSpace(false, 50_000, true));

    expect($export->status)->toBe(DataExport::STATUS_FAILED)->and($export->error)->toContain('comprobar el espacio')->and(exportTemporaries())->toBe([]);
});

test('la estimación cuenta las exportaciones del Estudio (MP4/GIF) y los JSON de diseños y tablas', function () {
    seedExportData($this->user, 'PROPIO');
    Storage::disk('local')->put("studio-exports/{$this->user->id}/1/page-001.png", random_bytes(30_000));
    DB::table('media_assets')->update(['size' => 100]);
    config(['exports.free_space_margin' => 1000]);
    $export = requestExport($this->user);
    app()->instance(DiskSpace::class, fakeSpace(20_000, 20_000, true)); // < 30 000 de la exportación del Estudio + 1000

    $export = runExport($export);

    expect($export->status)->toBe(DataExport::STATUS_FAILED)->and($export->error)->toContain('espacio libre');
});

test('ZIP64 solo cuando hace falta: tamaño conocido pequeño va sin ZIP64 y el desconocido o grande con él', function () {
    $stream = function (string $content) {
        $handle = fopen('php://temp', 'w+b');
        fwrite($handle, $content);
        rewind($handle);

        return $handle;
    };
    $path = zipTo(function (ZipWriter $zip) use ($stream) {
        $zip->addStream('conocido.txt', $stream('hola'), 4);
        $zip->addString('cadena.txt', 'abc');
        $zip->addStream('desconocido.txt', $stream('sin tamaño'));
    });

    $check = pythonZipCheck($path);

    expect($check['bad'])->toBeNull()->and($check['versions'])->toBe(['conocido.txt' => 20, 'cadena.txt' => 20, 'desconocido.txt' => 45])->and($check['zip64'])->toBeTrue()
        ->and($check['entries']['conocido.txt'])->toBe([4, hash('sha256', 'hola')])->and($check['entries']['desconocido.txt'])->toBe([strlen('sin tamaño'), hash('sha256', 'sin tamaño')])
        ->and(str_contains(file_get_contents($path), "PK\x06\x06"))->toBeFalse();

    // Con tamaño conocido por encima del umbral de 32 bits (aquí 1 KiB) la entrada sí lleva ZIP64.
    $grande = str_repeat('z', 5000);
    $path = zipTo(fn (ZipWriter $zip) => $zip->addStream('grande.txt', $stream($grande), 5000), null, 1024);
    $check = pythonZipCheck($path);
    expect($check['bad'])->toBeNull()->and($check['versions'])->toBe(['grande.txt' => 45])->and($check['entries']['grande.txt'])->toBe([5000, hash('sha256', $grande)]);
});

test('el escritor distingue un fallo de escritura de uno de lectura, y el PPTX lo cuenta como es', function () {
    expect(fn () => (new ZipWriter(fopen('php://memory', 'rb')))->addString('a.txt', 'a'))->toThrow(ZipWriteFailed::class);
    expect(fn () => (new ZipWriter(fopen('php://memory', 'w+b')))->addFile('a.png', '/no/existe.png'))->toThrow(ZipSourceFailed::class);

    File::ensureDirectoryExists(config('exports.tmp_dir'));
    $build = fn () => (new StudioPptxExporter)->build(config('exports.tmp_dir').DIRECTORY_SEPARATOR.'x.pptx', [['path' => '/no/existe.png', 'name' => 'a']], 100, 100, 't');
    expect($build)->toThrow(StudioRenderFailure::class, 'No se pudo leer una imagen');
});
