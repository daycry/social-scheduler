<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// T-06 (A-12): las referencias a media dejan de ser JSON sin FK.
//  - `post_target_media`: la media propia de cada destino (antes `post_targets.media`), con FK restrict hacia `media_assets`.
//    `post_targets.has_own_media` distingue «hereda la comun» (false) de «lista propia, tambien vacia» (true).
//  - `post_series_media` y `autolist_item_media`: indices relacionales derivados (los mantienen los modelos) de la media que usan el
//    contenido de una serie activa y los elementos de una autolista; el JSON sigue siendo el contenido, el indice da la FK restrict.
// Reversible: `down()` reconstruye `post_targets.media` desde la tabla. Las referencias a media que ya no existe se descartan (no se puede
// poner una FK sobre un id huerfano); las portadas huerfanas quedan en null.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('post_target_media', function (Blueprint $table) {
            $table->id();
            $table->foreignId('post_target_id')->constrained()->cascadeOnDelete();
            $table->foreignId('media_asset_id')->constrained()->restrictOnDelete();
            $table->foreignId('cover_id')->nullable()->constrained('media_assets')->restrictOnDelete();
            $table->text('alt')->nullable();
            $table->unsignedSmallInteger('position')->default(0);

            $table->unique(['post_target_id', 'position']);
            $table->index('media_asset_id');
            $table->index('cover_id');
        });

        Schema::create('post_series_media', function (Blueprint $table) {
            $table->id();
            $table->foreignId('post_series_id')->constrained('post_series')->cascadeOnDelete();
            $table->foreignId('media_asset_id')->constrained()->restrictOnDelete();

            $table->unique(['post_series_id', 'media_asset_id']);
            $table->index('media_asset_id');
        });

        Schema::create('autolist_item_media', function (Blueprint $table) {
            $table->id();
            $table->foreignId('autolist_item_id')->constrained()->cascadeOnDelete();
            $table->foreignId('media_asset_id')->constrained()->restrictOnDelete();

            $table->unique(['autolist_item_id', 'media_asset_id']);
            $table->index('media_asset_id');
        });

        Schema::table('post_targets', function (Blueprint $table) {
            $table->boolean('has_own_media')->default(false);
        });

        $existing = fn (array $ids) => $ids === [] ? [] : DB::table('media_assets')->whereIn('id', $ids)->pluck('id')->flip()->all();

        DB::table('post_targets')->whereNotNull('media')->orderBy('id')->select(['id', 'media'])->chunkById(200, function ($targets) use ($existing) {
            $lists = [];
            $ids = [];
            foreach ($targets as $t) {
                $lists[$t->id] = array_values(array_filter((array) json_decode((string) $t->media, true), 'is_array'));
                foreach ($lists[$t->id] as $m) {
                    $ids[] = (int) ($m['id'] ?? 0);
                    $ids[] = (int) ($m['cover_id'] ?? 0);
                }
            }
            $known = $existing(array_values(array_unique(array_filter($ids))));
            foreach ($lists as $targetId => $list) {
                DB::table('post_targets')->where('id', $targetId)->update(['has_own_media' => true]);
                $rows = [];
                foreach ($list as $m) {
                    $id = (int) ($m['id'] ?? 0);
                    if (! isset($known[$id])) {
                        continue;
                    }
                    $cover = (int) ($m['cover_id'] ?? 0);
                    $rows[] = ['post_target_id' => $targetId, 'media_asset_id' => $id, 'cover_id' => isset($known[$cover]) ? $cover : null, 'alt' => $m['alt'] ?? null, 'position' => count($rows)];
                }
                if ($rows !== []) {
                    DB::table('post_target_media')->insert($rows);
                }
            }
        });

        DB::table('post_series')->where('status', 'activa')->orderBy('id')->select(['id', 'payload'])->chunkById(200, function ($series) use ($existing) {
            foreach ($series as $s) {
                $payload = (array) json_decode((string) $s->payload, true);
                $ids = array_map('intval', (array) ($payload['media'] ?? []));
                foreach ((array) ($payload['targets'] ?? []) as $t) {
                    foreach ((array) ($t['media'] ?? []) as $m) {
                        $ids[] = (int) ($m['id'] ?? 0);
                        $ids[] = (int) ($m['cover_id'] ?? 0);
                    }
                }
                $ids = array_keys($existing(array_values(array_unique(array_filter($ids)))));
                if ($ids !== []) {
                    DB::table('post_series_media')->insert(array_map(fn ($id) => ['post_series_id' => $s->id, 'media_asset_id' => $id], $ids));
                }
            }
        });

        DB::table('autolist_items')->whereNotNull('media')->orderBy('id')->select(['id', 'media'])->chunkById(200, function ($items) use ($existing) {
            foreach ($items as $i) {
                $ids = array_keys($existing(array_values(array_unique(array_filter(array_map('intval', (array) json_decode((string) $i->media, true)))))));
                if ($ids !== []) {
                    DB::table('autolist_item_media')->insert(array_map(fn ($id) => ['autolist_item_id' => $i->id, 'media_asset_id' => $id], $ids));
                }
            }
        });

        Schema::table('post_targets', function (Blueprint $table) {
            $table->dropColumn('media');
        });
    }

    public function down(): void
    {
        Schema::table('post_targets', function (Blueprint $table) {
            $table->json('media')->nullable();
        });

        DB::table('post_targets')->where('has_own_media', true)->orderBy('id')->select(['id'])->chunkById(200, function ($targets) {
            foreach ($targets as $t) {
                $list = DB::table('post_target_media')->where('post_target_id', $t->id)->orderBy('position')->get()
                    ->map(fn ($r) => ['id' => (int) $r->media_asset_id, 'alt' => $r->alt, 'cover_id' => $r->cover_id === null ? null : (int) $r->cover_id])->all();
                DB::table('post_targets')->where('id', $t->id)->update(['media' => json_encode($list, JSON_UNESCAPED_UNICODE)]);
            }
        });

        Schema::table('post_targets', function (Blueprint $table) {
            $table->dropColumn('has_own_media');
        });
        Schema::dropIfExists('autolist_item_media');
        Schema::dropIfExists('post_series_media');
        Schema::dropIfExists('post_target_media');
    }
};
