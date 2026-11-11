<?php

use App\Enums\PostStatus;
use App\Models\Autolist;
use App\Models\AutolistItem;
use App\Models\Brand;
use App\Models\MediaAsset;
use App\Models\Post;
use App\Models\PostSeries;
use App\Models\PostTarget;
use App\Models\User;
use App\Services\MediaUploadService;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
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
        expect(fn () => $svc->delete($m))->toThrow(DomainException::class, 'se usa en una publicacion');
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
