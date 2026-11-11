<?php

use App\Models\Brand;
use App\Models\Studio\Design;
use App\Models\Studio\DesignRevision;
use App\Models\Studio\StudioFont;
use App\Models\User;
use App\Services\Studio\StudioFonts;
use App\Services\Studio\StudioRenderer;
use App\Services\Studio\StudioService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpKernel\Exception\HttpException;

function retentionDocument(string $text = 'Hola'): array
{
    return ['schema_version' => 1, 'width' => 1080, 'height' => 1080, 'pages' => [
        ['id' => 'page_1', 'name' => 'Primera', 'background' => '#ffffff', 'duration' => 5, 'elements' => [
            ['id' => 'title', 'type' => 'text', 'x' => 20, 'y' => 30, 'width' => 600, 'height' => 100, 'text' => $text, 'fill' => '#111827', 'fontFamily' => 'Arial', 'fontSize' => 64],
        ]],
    ]];
}

/** Revisiones visibles en el historial; con `$all` también las sustituidas por un autoguardado. */
function revisionRows(Design $design, bool $all = false): array
{
    return DesignRevision::withoutUserScope()->where('design_id', $design->id)->when(! $all, fn ($q) => $q->whereNull('superseded_at'))->orderBy('revision')->pluck('revision')->all();
}

function saveDesign(object $test, Design $design, string $text, bool $autosave): Design
{
    return $test->studio->update($test->user, $design, ['name' => 'Diseño', 'document' => retentionDocument($text), 'revision' => $design->revision, 'autosave' => $autosave]);
}

function retentionFont(object $test): StudioFont
{
    Storage::disk('local')->put('fonts/f.woff2', 'x');

    return StudioFont::withoutUserScope()->create(['user_id' => $test->user->id, 'brand_id' => $test->brand->id, 'name' => 'F', 'family' => 'Fuente', 'style' => 'normal', 'weight' => 400,
        'format' => 'woff2', 'mime' => 'font/woff2', 'size' => 10, 'sha256' => str_repeat('a', 64), 'source_sha256' => str_repeat('b', 64), 'disk' => 'local', 'path' => 'fonts/f.woff2', 'status' => 'ready']);
}

beforeEach(function () {
    Storage::fake('local');
    $this->user = User::factory()->create();
    $this->brand = Brand::factory()->for($this->user)->create();
    $this->actingAs($this->user);
    $this->studio = app(StudioService::class);
    $this->design = $this->studio->create($this->user, $this->brand, ['name' => 'Diseño', 'document' => retentionDocument()]);
});

test('autosaves within the minimum interval rewrite the recent revision instead of adding one', function () {
    config(['studio.revisions.min_interval_seconds' => 300]);
    $design = saveDesign($this, $this->design, 'uno', true);
    $design = saveDesign($this, $design, 'dos', true);
    $design = saveDesign($this, $design, 'tres', true);

    expect($design->revision)->toBe(4)->and(revisionRows($design))->toBe([1, 4]);
    $latest = DesignRevision::withoutUserScope()->where('design_id', $design->id)->where('revision', 4)->first();
    expect($latest->document['pages'][0]['elements'][0]['text'])->toBe('tres');
});

test('an explicit save or an expired interval always creates a revision', function () {
    config(['studio.revisions.min_interval_seconds' => 300]);
    $design = saveDesign($this, $this->design, 'uno', true);
    $design = saveDesign($this, $design, 'manual', false);
    expect(revisionRows($design))->toBe([1, 2, 3]);

    $this->travel(10)->minutes();
    $design = saveDesign($this, $design, 'tarde', true);
    expect(revisionRows($design))->toBe([1, 2, 3, 4]);
});

test('a zero interval disables coalescing', function () {
    config(['studio.revisions.min_interval_seconds' => 0]);
    $design = saveDesign($this, $this->design, 'uno', true);
    $design = saveDesign($this, $design, 'dos', true);
    expect(revisionRows($design))->toBe([1, 2, 3]);
});

test('pruning keeps the last N revisions and one per day, and drops the rest with their font usages', function () {
    config(['retention.design_revisions.keep_last' => 3, 'retention.design_revisions.daily_days' => 30]);
    $font = retentionFont($this);
    DesignRevision::withoutUserScope()->where('design_id', $this->design->id)->delete();

    // 12 revisiones: dos por día durante 6 días (a las 09:00 y a las 18:00).
    foreach (range(1, 12) as $n) {
        $at = now()->subDays(intdiv(12 - $n, 2))->setTime($n % 2 === 0 ? 18 : 9, 0);
        $row = DesignRevision::withoutUserScope()->create(['user_id' => $this->user->id, 'author_id' => $this->user->id, 'design_id' => $this->design->id, 'revision' => $n, 'name' => 'x',
            'document' => retentionDocument(), 'kit_reference' => null, 'created_at' => $at, 'updated_at' => $at]);
        DB::table('studio_font_usages')->insert(['user_id' => $this->user->id, 'font_id' => $font->id, 'revision_id' => $row->id]);
    }

    $this->artisan('model:prune', ['--model' => [DesignRevision::class]])->assertSuccessful();

    // Últimas 3 (10, 11, 12) más la última de cada día (2, 4, 6, 8, 10, 12).
    expect(revisionRows($this->design))->toBe([2, 4, 6, 8, 10, 11, 12])
        ->and(DB::table('studio_font_usages')->count())->toBe(7);
});

test('a font only used by pruned revisions can be deleted afterwards', function () {
    config(['retention.design_revisions.keep_last' => 1, 'retention.design_revisions.daily_days' => 1]);
    $font = retentionFont($this);
    DesignRevision::withoutUserScope()->where('design_id', $this->design->id)->update(['created_at' => now()->subDays(10)]);
    $old = DesignRevision::withoutUserScope()->where('design_id', $this->design->id)->first();
    DB::table('studio_font_usages')->insert(['user_id' => $this->user->id, 'font_id' => $font->id, 'revision_id' => $old->id]);
    $design = saveDesign($this, $this->design, 'nuevo', false);

    expect(fn () => app(StudioFonts::class)->delete($this->user, $this->brand, $font->id))->toThrow(HttpException::class);

    $this->artisan('model:prune', ['--model' => [DesignRevision::class]])->assertSuccessful();
    expect(revisionRows($design))->toBe([2]);

    app(StudioFonts::class)->delete($this->user, $this->brand, $font->id);
    expect(StudioFont::withoutUserScope()->whereKey($font->id)->exists())->toBeFalse();
});

function pinExport(object $test, Design $design, int $revision, string $status = 'pending'): void
{
    DB::table('studio_design_exports')->insert(['user_id' => $test->user->id, 'brand_id' => $test->brand->id, 'design_id' => $design->id, 'revision' => $revision,
        'format' => 'png', 'pages' => '["page_1"]', 'status' => $status, 'created_at' => now(), 'updated_at' => now()]);
}

test('the real editor payload (with kit_revision) coalesces autosaves through HTTP', function () {
    if (! Route::has('studio.index') && is_file(base_path('routes/studio.php'))) {
        Route::middleware('web')->group(base_path('routes/studio.php'));
        Route::getRoutes()->refreshNameLookups();
        Route::getRoutes()->refreshActionLookups();
    }
    config(['studio.revisions.min_interval_seconds' => 300]);
    $url = '/studio/designs/'.$this->design->id;
    $payload = fn (string $text, int $revision) => ['name' => 'Diseño', 'document' => retentionDocument($text), 'revision' => $revision, 'is_template' => false, 'folder_id' => null, 'kit_revision' => 1, 'autosave' => true];

    $this->patchJson($url, $payload('a', 1))->assertOk();
    $this->patchJson($url, $payload('b', 2))->assertOk();
    $this->patchJson($url, $payload('c', 3))->assertOk()->assertJsonPath('design.revision', 4);

    expect(revisionRows($this->design))->toBe([1, 4]);
});

test('an autosave never rewrites an explicit checkpoint', function () {
    config(['studio.revisions.min_interval_seconds' => 300]);
    $design = saveDesign($this, $this->design, 'manual', false);      // revisión 2, punto de control
    $design = saveDesign($this, $design, 'auto', true);               // revisión 3, nueva fila
    expect(revisionRows($design))->toBe([1, 2, 3]);
    $manual = DesignRevision::withoutUserScope()->where('design_id', $design->id)->where('revision', 2)->first();
    expect($manual->document['pages'][0]['elements'][0]['text'])->toBe('manual');
});

test('an autosave does not reuse the number of a revision pinned by an unfinished export', function () {
    config(['studio.revisions.min_interval_seconds' => 300]);
    $design = saveDesign($this, $this->design, 'uno', true);          // revisión 2 (autoguardado)
    pinExport($this, $design, 2);
    $design = saveDesign($this, $design, 'dos', true);

    $pinned = DesignRevision::withoutUserScope()->where('design_id', $design->id)->where('revision', 2)->first();
    expect($pinned)->not->toBeNull()->and($pinned->document['pages'][0]['elements'][0]['text'])->toBe('uno')
        ->and(revisionRows($design, true))->toBe([1, 2, 3]);
});

test('pruning keeps revisions pinned by unfinished exports', function () {
    config(['retention.design_revisions.keep_last' => 1, 'retention.design_revisions.daily_days' => 1]);
    $design = saveDesign($this, $this->design, 'uno', false);
    $design = saveDesign($this, $design, 'dos', false);
    DesignRevision::withoutUserScope()->where('design_id', $design->id)->update(['created_at' => now()->subDays(20)]);
    pinExport($this, $design, 2, 'failed');
    pinExport($this, $design, 1, 'ready');

    $this->artisan('model:prune', ['--model' => [DesignRevision::class]])->assertSuccessful();

    expect(revisionRows($design))->toBe([2, 3]);
});

test('pruning computes the keep set once and deletes in batches', function () {
    config(['retention.design_revisions.keep_last' => 1, 'retention.design_revisions.daily_days' => 1]);
    DesignRevision::withoutUserScope()->where('design_id', $this->design->id)->delete();
    foreach (range(1, 25) as $n) {
        DesignRevision::withoutUserScope()->create(['user_id' => $this->user->id, 'author_id' => $this->user->id, 'design_id' => $this->design->id, 'revision' => $n, 'name' => 'x',
            'document' => retentionDocument(), 'kit_reference' => null, 'created_at' => now()->subDays(30), 'updated_at' => now()->subDays(30)]);
    }
    $windowQueries = 0;
    DB::listen(function ($q) use (&$windowQueries) {
        if (str_contains($q->sql, 'row_number()')) {
            $windowQueries++;
        }
    });

    $deleted = (new DesignRevision)->pruneAll(10);

    expect($deleted)->toBe(24)->and($windowQueries)->toBe(1);
});

test('a superseded revision stays usable as an export base and is hidden from the history', function () {
    config(['studio.revisions.min_interval_seconds' => 300]);
    $design = saveDesign($this, $this->design, 'uno', true);    // 2
    $design = saveDesign($this, $design, 'dos', true);          // 3 sustituye a 2

    $export = app(StudioRenderer::class)->export($this->user, $design, ['revision' => 2, 'format' => 'png', 'pages' => ['page_1']]);
    expect($export->revision)->toBe(2);
    expect(DesignRevision::withoutUserScope()->where('design_id', $design->id)->where('revision', 2)->value('superseded_at'))->not->toBeNull();
    expect(collect($this->studio->versions($this->user, $design))->pluck('revision')->all())->toBe([3, 1]);
});

test('superseded revisions are pruned only after the grace period and never when pinned', function () {
    config(['retention.design_revisions.keep_last' => 50, 'retention.design_revisions.daily_days' => 90, 'retention.design_revisions.superseded_hours' => 24]);
    $design = saveDesign($this, $this->design, 'uno', false);   // 2
    $design = saveDesign($this, $design, 'dos', false);         // 3
    $design = saveDesign($this, $design, 'tres', false);        // 4
    $mark = fn (int $revision, $at) => DesignRevision::withoutUserScope()->where('design_id', $design->id)->where('revision', $revision)->update(['superseded_at' => $at]);
    $mark(2, now()->subHours(2));     // dentro del margen
    $mark(3, now()->subHours(30));    // pasado el margen
    $mark(4, now()->subHours(30));    // pasado el margen pero fijada
    pinExport($this, $design, 4, 'pending');

    $this->artisan('model:prune', ['--model' => [DesignRevision::class]])->assertSuccessful();

    expect(revisionRows($design, true))->toBe([1, 2, 4]);
});

test('an export validates its revision under a lock inside the transaction', function () {
    $design = saveDesign($this, $this->design, 'uno', false);
    $selects = 0;
    $inserted = false;
    DB::listen(function ($q) use (&$selects, &$inserted) {
        if (str_contains($q->sql, 'insert into "studio_design_exports"')) {
            $inserted = true;
        }
        if (! $inserted && str_starts_with($q->sql, 'select') && str_contains($q->sql, 'from "studio_design_revisions"')) {
            $selects++;
        }
    });
    app(StudioRenderer::class)->export($this->user, $design, ['revision' => 2, 'format' => 'png', 'pages' => ['page_1']]);
    // Una lectura para validar y otra, con bloqueo, justo antes de fijarla en la exportación.
    expect($selects)->toBeGreaterThanOrEqual(2);
});
