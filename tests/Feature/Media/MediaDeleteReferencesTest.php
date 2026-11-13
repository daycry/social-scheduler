<?php

use App\Enums\PostStatus;
use App\Exceptions\PostValidationException;
use App\Models\Autolist;
use App\Models\AutolistItem;
use App\Models\Brand;
use App\Models\MediaAsset;
use App\Models\Post;
use App\Models\PostSeries;
use App\Models\PostTarget;
use App\Models\Studio\Design;
use App\Models\Studio\DesignExport;
use App\Models\User;
use App\Services\MediaUploadService;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

// T-06 (A-12): las referencias a media de destinos, series y autolistas son relacionales (FK restrict); la comprobacion de uso es una consulta.

beforeEach(function () {
    Storage::fake('local');
    $this->user = User::factory()->create();
    $this->brand = Brand::factory()->for($this->user)->create();
    $this->asset = fn () => MediaAsset::factory()->for($this->user)->create();
    $this->target = fn (PostStatus $s = PostStatus::Draft, ?array $media = null) => PostTarget::factory()->create([
        'post_id' => Post::factory()->create(['user_id' => $this->user->id, 'brand_id' => $this->brand->id])->id, 'status' => $s, 'media' => $media,
    ]);
});

test('la media propia de un destino se guarda en post_target_media y el atributo conserva su forma', function () {
    $a = ($this->asset)();
    $cover = ($this->asset)();
    $t = ($this->target)(PostStatus::Draft, [['id' => $a->id, 'alt' => 'foto', 'cover_id' => $cover->id], ['id' => $cover->id, 'alt' => null, 'cover_id' => null]]);

    expect(DB::table('post_target_media')->where('post_target_id', $t->id)->orderBy('position')->pluck('media_asset_id')->all())->toBe([$a->id, $cover->id]);
    expect($t->fresh()->media)->toBe([['id' => $a->id, 'alt' => 'foto', 'cover_id' => $cover->id], ['id' => $cover->id, 'alt' => null, 'cover_id' => null]]);
    expect(PostTarget::withoutUserScope()->find(($this->target)()->id)->media)->toBeNull();
    expect(($this->target)(PostStatus::Draft, [])->fresh()->media)->toBe([]);

    $t->update(['media' => [['id' => $cover->id, 'alt' => null, 'cover_id' => null]]]);
    expect($t->fresh()->media)->toBe([['id' => $cover->id, 'alt' => null, 'cover_id' => null]]);
    $t->update(['media' => null]);
    expect($t->fresh()->media)->toBeNull()->and(DB::table('post_target_media')->where('post_target_id', $t->id)->count())->toBe(0);
});

test('borrar una media usada por un destino sin publicar, como portada, por una serie o una autolista falla con mensaje claro', function () {
    $svc = app(MediaUploadService::class);
    $inTarget = ($this->asset)();
    $asCover = ($this->asset)();
    $inSeries = ($this->asset)();
    $inAutolist = ($this->asset)();
    ($this->target)(PostStatus::Draft, [['id' => $inTarget->id, 'alt' => null, 'cover_id' => $asCover->id]]);
    PostSeries::withoutUserScope()->create(['user_id' => $this->user->id, 'brand_id' => $this->brand->id, 'frequency' => 'diaria', 'time_local' => '10:00', 'starts_on' => '2026-11-10', 'status' => PostSeries::ACTIVE, 'payload' => ['text' => 'x', 'media' => [$inSeries->id], 'targets' => []]]);
    $list = Autolist::query()->forceCreate(['user_id' => $this->user->id, 'brand_id' => $this->brand->id, 'name' => 'L', 'targets' => [], 'slots' => []]);
    $item = new AutolistItem(['text' => 'hola', 'media' => [$inAutolist->id]]);
    $item->user_id = $this->user->id;
    $item->autolist_id = $list->id;
    $item->position = 1;
    $item->save();

    foreach ([$inTarget, $asCover, $inSeries, $inAutolist] as $m) {
        expect(fn () => $svc->delete($m))->toThrow(DomainException::class, 'El archivo se usa en');
        expect(MediaAsset::withoutUserScope()->find($m->id))->not->toBeNull();
    }
});

test('la comprobacion de uso no carga filas de destinos en PHP', function () {
    $a = ($this->asset)();
    ($this->target)(PostStatus::Draft, [['id' => $a->id, 'alt' => null, 'cover_id' => null]]);
    ($this->target)(PostStatus::Draft, [['id' => ($this->asset)()->id, 'alt' => null, 'cover_id' => null]]);

    $hydrated = 0;
    PostTarget::retrieved(function () use (&$hydrated) {
        $hydrated++;
    });
    expect($a->usedByPosts())->toBeTrue()->and($hydrated)->toBe(0);
});

test('una media de un destino publicado se puede borrar y los destinos pendientes siguen protegidos', function () {
    $svc = app(MediaUploadService::class);
    $published = ($this->asset)();
    $pending = ($this->asset)();
    $pt = ($this->target)(PostStatus::Published, [['id' => $published->id, 'alt' => null, 'cover_id' => null]]);
    ($this->target)(PostStatus::Scheduled, [['id' => $pending->id, 'alt' => null, 'cover_id' => null]]);

    $svc->delete($published);
    expect(MediaAsset::withoutUserScope()->find($published->id))->toBeNull()->and(DB::table('post_target_media')->where('post_target_id', $pt->id)->count())->toBe(0);
    expect(fn () => $svc->delete($pending))->toThrow(DomainException::class);
});

test('la FK restrict impide borrar la fila de una media referenciada aunque se salte el servicio', function () {
    $a = ($this->asset)();
    ($this->target)(PostStatus::Draft, [['id' => $a->id, 'alt' => null, 'cover_id' => null]]);
    expect(fn () => DB::table('media_assets')->where('id', $a->id)->delete())->toThrow(QueryException::class);
});

test('una serie cancelada y un item de autolista borrado dejan de bloquear', function () {
    $m = ($this->asset)();
    $s = PostSeries::withoutUserScope()->create(['user_id' => $this->user->id, 'brand_id' => $this->brand->id, 'frequency' => 'diaria', 'time_local' => '10:00', 'starts_on' => '2026-11-10', 'status' => PostSeries::ACTIVE, 'payload' => ['media' => [$m->id]]]);
    expect($m->usedByPosts())->toBeTrue();
    $s->forceFill(['status' => PostSeries::CANCELLED])->save();
    expect($m->usedByPosts())->toBeFalse();
    app(MediaUploadService::class)->delete($m);
    expect(MediaAsset::withoutUserScope()->find($m->id))->toBeNull();
});

test('la migracion convierte el JSON existente, descarta ids huerfanos y es reversible', function () {
    $a = ($this->asset)();
    $cover = ($this->asset)();
    $own = ($this->target)(PostStatus::Draft, [['id' => $a->id, 'alt' => 'x', 'cover_id' => null]]);
    $inherit = ($this->target)();
    $empty = ($this->target)(PostStatus::Draft, []);
    $migration = require database_path('migrations/2026_12_18_090000_create_post_target_media_tables.php');

    $migration->down();
    DB::table('post_targets')->where('id', $own->id)->update(['media' => json_encode([['id' => $a->id, 'alt' => 'x', 'cover_id' => $cover->id], ['id' => 999999, 'alt' => null, 'cover_id' => null]])]);
    DB::table('post_targets')->where('id', $empty->id)->update(['media' => '[]']);
    $migration->up();

    expect(PostTarget::withoutUserScope()->find($own->id)->media)->toBe([['id' => $a->id, 'alt' => 'x', 'cover_id' => $cover->id]])
        ->and(PostTarget::withoutUserScope()->find($inherit->id)->media)->toBeNull()
        ->and(PostTarget::withoutUserScope()->find($empty->id)->media)->toBe([]);

    $migration->down();
    expect(json_decode(DB::table('post_targets')->where('id', $own->id)->value('media'), true))->toBe([['id' => $a->id, 'alt' => 'x', 'cover_id' => $cover->id]])
        ->and(DB::table('post_targets')->where('id', $inherit->id)->value('media'))->toBeNull()
        ->and(DB::table('post_targets')->where('id', $empty->id)->value('media'))->toBe('[]');
    $migration->up();
});

function mdrExport(User $user, Brand $brand, MediaAsset $m, string $status = 'ready', ?string $createdAt = null): DesignExport
{
    $design = Design::unguarded(fn () => Design::create(['user_id' => $user->id, 'brand_id' => $brand->id, 'name' => 'D', 'document' => ['pages' => []], 'width' => 1, 'height' => 1, 'page_count' => 1, 'revision' => 1]));
    $x = DesignExport::create(['user_id' => $user->id, 'brand_id' => $brand->id, 'design_id' => $design->id, 'revision' => 1, 'format' => 'png', 'pages' => ['p1'], 'media_ids' => [$m->id], 'status' => $status]);
    if ($createdAt !== null) {
        DB::table('studio_design_exports')->where('id', $x->id)->update(['created_at' => $createdAt]);
    }

    return $x;
}

test('la media de un export listo reciente bloquea el borrado; la de uno antiguo, no listo o sin export, no', function () {
    config(['studio.export_media_guard_days' => 30]);
    $recent = ($this->asset)();
    $old = ($this->asset)();
    $failed = ($this->asset)();
    mdrExport($this->user, $this->brand, $recent);
    mdrExport($this->user, $this->brand, $old, 'ready', now()->subDays(31)->toDateTimeString());
    mdrExport($this->user, $this->brand, $failed, 'failed');

    expect(fn () => app(MediaUploadService::class)->delete($recent))->toThrow(DomainException::class, 'El archivo se usa en');
    expect($recent->usedByPosts())->toBeTrue()->and($old->usedByPosts())->toBeFalse()->and($failed->usedByPosts())->toBeFalse();
});

test('la migracion no descarta en silencio la media huerfana de un destino sin publicar: lo marca fallido', function () {
    $a = ($this->asset)();
    $b = ($this->asset)();
    $scheduled = ($this->target)(PostStatus::Scheduled, [['id' => $a->id, 'alt' => null, 'cover_id' => null], ['id' => $b->id, 'alt' => null, 'cover_id' => null]]);
    $published = ($this->target)(PostStatus::Published, [['id' => $a->id, 'alt' => null, 'cover_id' => null], ['id' => $b->id, 'alt' => null, 'cover_id' => null]]);
    $migration = require database_path('migrations/2026_12_18_090000_create_post_target_media_tables.php');
    $migration->down();
    DB::table('media_assets')->where('id', $b->id)->delete();
    Log::spy();
    $migration->up();

    $s = DB::table('post_targets')->where('id', $scheduled->id)->first();
    expect($s->status)->toBe(PostStatus::Failed->value)->and($s->error_code)->toBe('media_inexistente')->and($s->error_message)->toContain((string) $b->id);
    Log::shouldHaveReceived('warning')->once();
    expect(DB::table('posts')->where('id', $scheduled->post_id)->value('status'))->toBe(PostStatus::Failed->value);
    $note = DB::table('notifications')->where('notifiable_id', $this->user->id)->first();
    expect($note)->not->toBeNull()->and($note->data)->toContain('Falta el archivo '.$b->id);
    expect(DB::table('post_targets')->where('id', $published->id)->value('status'))->toBe(PostStatus::Published->value);
    expect(PostTarget::withoutUserScope()->find($published->id)->media)->toBe([['id' => $a->id, 'alt' => null, 'cover_id' => null]]);
});

test('una serie o un elemento de autolista con media inexistente es un error de validacion y no deja filas', function () {
    $m = ($this->asset)();
    $before = PostSeries::withoutUserScope()->count();
    $series = new PostSeries(['frequency' => 'diaria', 'time_local' => '10:00', 'starts_on' => '2026-11-10', 'status' => PostSeries::ACTIVE, 'payload' => ['media' => [$m->id, 987654]]]);
    $series->user_id = $this->user->id;
    $series->brand_id = $this->brand->id;
    expect(fn () => DB::transaction(fn () => $series->save()))->toThrow(PostValidationException::class);
    expect(PostSeries::withoutUserScope()->count())->toBe($before);

    $list = Autolist::query()->forceCreate(['user_id' => $this->user->id, 'brand_id' => $this->brand->id, 'name' => 'L', 'targets' => [], 'slots' => []]);
    $item = new AutolistItem(['text' => 'x', 'media' => [987654]]);
    $item->user_id = $this->user->id;
    $item->autolist_id = $list->id;
    $item->position = 1;
    expect(fn () => DB::transaction(fn () => $item->save()))->toThrow(PostValidationException::class);
    expect(AutolistItem::withoutUserScope()->count())->toBe(0);
});

test('borrar un usuario con posts, media por destino, series y autolistas no falla y no deja filas', function () {
    $m = ($this->asset)();
    $cover = ($this->asset)();
    $t = ($this->target)(PostStatus::Scheduled, [['id' => $m->id, 'alt' => null, 'cover_id' => $cover->id]]);
    $t->post->media()->attach($m->id, ['position' => 0]);
    PostSeries::withoutUserScope()->create(['user_id' => $this->user->id, 'brand_id' => $this->brand->id, 'frequency' => 'diaria', 'time_local' => '10:00', 'starts_on' => '2026-11-10', 'status' => PostSeries::ACTIVE, 'payload' => ['media' => [$m->id]]]);
    $list = Autolist::query()->forceCreate(['user_id' => $this->user->id, 'brand_id' => $this->brand->id, 'name' => 'L', 'targets' => [], 'slots' => []]);
    $item = new AutolistItem(['text' => 'x', 'media' => [$m->id]]);
    $item->user_id = $this->user->id;
    $item->autolist_id = $list->id;
    $item->position = 1;
    $item->save();

    $this->user->delete();

    foreach (['post_target_media', 'post_series_media', 'autolist_item_media', 'post_media', 'media_assets', 'post_targets'] as $table) {
        expect(DB::table($table)->count())->toBe(0, $table);
    }
});

test('la migracion no marca fallidos los borradores ni toca los destinos en vuelo', function () {
    $a = ($this->asset)();
    $b = ($this->asset)();
    $mk = fn (PostStatus $st) => ($this->target)($st, [['id' => $a->id, 'alt' => null, 'cover_id' => null], ['id' => $b->id, 'alt' => null, 'cover_id' => null]]);
    $draft = $mk(PostStatus::Draft);
    $processing = $mk(PostStatus::Processing);
    $migration = require database_path('migrations/2026_12_18_090000_create_post_target_media_tables.php');
    $migration->down();
    DB::table('media_assets')->where('id', $b->id)->delete();
    Log::spy();
    $migration->up();

    expect(DB::table('post_targets')->where('id', $draft->id)->value('status'))->toBe(PostStatus::Draft->value)
        ->and(PostTarget::withoutUserScope()->find($draft->id)->media)->toBe([['id' => $a->id, 'alt' => null, 'cover_id' => null]])
        ->and(DB::table('post_targets')->where('id', $processing->id)->value('status'))->toBe(PostStatus::Processing->value)
        ->and(DB::table('post_targets')->where('id', $processing->id)->value('error_code'))->toBeNull();
    Log::shouldHaveReceived('warning')->atLeast()->twice();
});

test('la purga de referencias y el borrado del usuario son atomicos', function () {
    $m = ($this->asset)();
    ($this->target)(PostStatus::Draft, [['id' => $m->id, 'alt' => null, 'cover_id' => null]]);
    User::deleting(fn () => throw new RuntimeException('falla el borrado'));

    expect(fn () => $this->user->delete())->toThrow(RuntimeException::class);
    expect(DB::table('post_target_media')->count())->toBe(1)->and(User::query()->find($this->user->id))->not->toBeNull();
});

test('el error de borrado distingue el motivo', function () {
    config(['studio.export_media_guard_days' => 30]);
    $svc = app(MediaUploadService::class);
    $exp = ($this->asset)();
    $ser = ($this->asset)();
    $aut = ($this->asset)();
    mdrExport($this->user, $this->brand, $exp);
    PostSeries::withoutUserScope()->create(['user_id' => $this->user->id, 'brand_id' => $this->brand->id, 'frequency' => 'diaria', 'time_local' => '10:00', 'starts_on' => '2026-11-10', 'status' => PostSeries::ACTIVE, 'payload' => ['media' => [$ser->id]]]);
    $list = Autolist::query()->forceCreate(['user_id' => $this->user->id, 'brand_id' => $this->brand->id, 'name' => 'L', 'targets' => [], 'slots' => []]);
    $item = new AutolistItem(['text' => 'x', 'media' => [$aut->id]]);
    $item->user_id = $this->user->id;
    $item->autolist_id = $list->id;
    $item->position = 1;
    $item->save();

    expect(fn () => $svc->delete($exp))->toThrow(DomainException::class, 'exportacion reciente del estudio')
        ->and(fn () => $svc->delete($ser))->toThrow(DomainException::class, 'serie')
        ->and(fn () => $svc->delete($aut))->toThrow(DomainException::class, 'autolista');
});
