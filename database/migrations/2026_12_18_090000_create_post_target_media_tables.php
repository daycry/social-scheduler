<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

// T-06 (A-12): las referencias a media dejan de ser JSON sin FK.
//  - `post_target_media`: la media propia de cada destino (antes `post_targets.media`), con FK restrict hacia `media_assets`.
//    `post_targets.has_own_media` distingue «hereda la comun» (false) de «lista propia, tambien vacia» (true).
//  - `post_series_media` y `autolist_item_media`: indices relacionales derivados (los mantienen los modelos) de la media que usan el
//    contenido de una serie activa y los elementos de una autolista; el JSON sigue siendo el contenido, el indice da la FK restrict.
// Reversible: `down()` reconstruye `post_targets.media` desde la tabla. No se puede poner una FK sobre un id huerfano: en un destino ya
// publicado se descarta; en uno que aun puede publicarse se marca `fallida` (`media_inexistente`, con los ids) y se registra en el log.
// Compatible con MySQL 8: nombres de indice <= 64 caracteres, tipos de FK iguales (unsignedBigInteger) y dropColumn sin indice.
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

        // Existencia en bloque: devuelve [id => true] de los ids que existen en media_assets.
        $existing = fn (array $ids) => $ids === [] ? [] : DB::table('media_assets')->whereIn('id', array_values(array_unique($ids)))->pluck('id')->flip()->map(fn () => true)->all();

        DB::table('post_targets')->whereNotNull('media')->select(['id', 'media', 'status'])->chunkById(200, function ($targets) use ($existing) {
            $lists = [];
            $ids = [];
            foreach ($targets as $t) {
                $lists[$t->id] = array_values(array_filter((array) json_decode((string) $t->media, true), 'is_array'));
                foreach ($lists[$t->id] as $m) {
                    $ids[] = (int) ($m['id'] ?? 0);
                    $ids[] = (int) ($m['cover_id'] ?? 0);
                }
            }
            $known = $existing(array_filter($ids));
            $rows = [];
            $broken = [];
            foreach ($targets as $t) {
                $missing = [];
                $position = 0;
                foreach ($lists[$t->id] as $m) {
                    $id = (int) ($m['id'] ?? 0);
                    $cover = (int) ($m['cover_id'] ?? 0);
                    if (! isset($known[$id])) {
                        $missing[] = $id;

                        continue;
                    }
                    if ($cover !== 0 && ! isset($known[$cover])) {
                        $missing[] = $cover;
                        $cover = 0;
                    }
                    $rows[] = ['post_target_id' => $t->id, 'media_asset_id' => $id, 'cover_id' => $cover ?: null, 'alt' => $m['alt'] ?? null, 'position' => $position++];
                }
                // Un destino que aun puede publicarse no pierde en silencio un archivo: se marca fallido con el motivo (los publicados, que ya
                // salieron, solo descartan la referencia).
                if ($missing !== [] && $t->status !== 'publicada') {
                    $broken[$t->id] = array_values(array_unique($missing));
                }
            }
            DB::table('post_targets')->whereIn('id', array_keys($lists))->update(['has_own_media' => true]);
            foreach (array_chunk($rows, 200) as $part) {
                DB::table('post_target_media')->insert($part);
            }
            foreach ($broken as $targetId => $missing) {
                $list = implode(', ', $missing);
                DB::table('post_targets')->where('id', $targetId)->update([
                    'status' => 'fallida', 'error_code' => 'media_inexistente',
                    'error_message' => "Falta el archivo $list de la media de este destino: editalo y vuelve a elegir la media.",
                ]);
                Log::warning('Migracion post_target_media: destino con media inexistente marcado como fallido', ['post_target_id' => $targetId, 'media_ids' => $missing]);
            }
        });

        DB::table('post_series')->where('status', 'activa')->select(['id', 'payload'])->chunkById(200, function ($series) use ($existing) {
            $byId = [];
            foreach ($series as $s) {
                $payload = (array) json_decode((string) $s->payload, true);
                $ids = array_map('intval', (array) ($payload['media'] ?? []));
                foreach ((array) ($payload['targets'] ?? []) as $t) {
                    foreach ((array) ($t['media'] ?? []) as $m) {
                        $ids[] = (int) ($m['id'] ?? 0);
                        $ids[] = (int) ($m['cover_id'] ?? 0);
                    }
                }
                $byId[$s->id] = array_values(array_unique(array_filter($ids)));
            }
            $known = $existing(array_merge(...array_values($byId) ?: [[]]));
            $rows = [];
            foreach ($byId as $seriesId => $ids) {
                foreach ($ids as $id) {
                    if (isset($known[$id])) {
                        $rows[] = ['post_series_id' => $seriesId, 'media_asset_id' => $id];
                    } else {
                        Log::warning('Migracion post_series_media: la serie referencia una media inexistente', ['post_series_id' => $seriesId, 'media_id' => $id]);
                    }
                }
            }
            foreach (array_chunk($rows, 200) as $part) {
                DB::table('post_series_media')->insert($part);
            }
        });

        DB::table('autolist_items')->whereNotNull('media')->select(['id', 'media'])->chunkById(200, function ($items) use ($existing) {
            $byId = [];
            foreach ($items as $i) {
                $byId[$i->id] = array_values(array_unique(array_filter(array_map('intval', (array) json_decode((string) $i->media, true)))));
            }
            $known = $existing(array_merge(...array_values($byId) ?: [[]]));
            $rows = [];
            foreach ($byId as $itemId => $ids) {
                foreach ($ids as $id) {
                    if (isset($known[$id])) {
                        $rows[] = ['autolist_item_id' => $itemId, 'media_asset_id' => $id];
                    } else {
                        Log::warning('Migracion autolist_item_media: el elemento referencia una media inexistente', ['autolist_item_id' => $itemId, 'media_id' => $id]);
                    }
                }
            }
            foreach (array_chunk($rows, 200) as $part) {
                DB::table('autolist_item_media')->insert($part);
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
