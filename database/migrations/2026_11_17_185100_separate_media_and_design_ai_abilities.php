<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const LEGACY_AI = 'studio:ai';

    private const DESIGN_AI = 'designs:ai';

    private const CUTOFFS = 'media_ability_backfill_cutoffs';

    public function up(): void
    {
        // Se conserva al revertir el código: una segunda actualización debe respetar
        // las claves creadas con permisos separados después del primer despliegue.
        if (! Schema::hasTable(self::CUTOFFS)) {
            Schema::create(self::CUTOFFS, function (Blueprint $table): void {
                $table->string('source', 32)->primary();
                $table->unsignedBigInteger('legacy_after')->nullable();
            });
        }

        $this->rewrite(upgrading: true);
    }

    public function down(): void
    {
        // El código anterior ignora media:*. Conserva las concesiones explícitas si se vuelve a actualizar.
        $this->rewrite(upgrading: false);
    }

    private function rewrite(bool $upgrading): void
    {
        DB::transaction(function () use ($upgrading): void {
            // Los códigos OAuth aún canjeables también contienen la lista que se copiará al token.
            foreach (['oauth_auth_codes', 'personal_access_tokens'] as $table) {
                $cutoff = DB::table(self::CUTOFFS)->where('source', $table)->lockForUpdate()->first();
                // Antes de la primera actualización todos los IDs existentes son históricos.
                $legacyAfter = $upgrading ? ($cutoff === null ? 0 : $cutoff->legacy_after) : null;
                $this->rewriteRows($table, $upgrading, $legacyAfter);

                if ($upgrading) {
                    DB::table(self::CUTOFFS)->updateOrInsert(['source' => $table], ['legacy_after' => null]);
                } elseif ($cutoff === null || $cutoff->legacy_after === null) {
                    $lastModernId = DB::table($table)->max('id') ?? 0;
                    DB::table(self::CUTOFFS)->updateOrInsert(['source' => $table], ['legacy_after' => $lastModernId]);
                }
            }
        }, attempts: 3);
    }

    private function rewriteRows(string $table, bool $upgrading, ?int $legacyAfter): void
    {
        [$from, $to] = $upgrading ? [self::LEGACY_AI, self::DESIGN_AI] : [self::DESIGN_AI, self::LEGACY_AI];
        DB::table($table)->select('id', 'abilities')->whereNotNull('abilities')->lockForUpdate()
            ->chunkById(250, function (Collection $rows) use ($table, $from, $to, $legacyAfter): void {
                foreach ($rows as $row) {
                    $current = json_decode($row->abilities);
                    if (! is_array($current) || array_filter($current, fn ($ability) => ! is_string($ability))) {
                        continue;
                    }

                    $next = array_map(fn (string $ability) => $ability === $from ? $to : $ability, $current);
                    if ($legacyAfter !== null && $row->id > $legacyAfter && ! in_array('*', $current, true)) {
                        foreach (['brands:read' => 'media:read', 'posts:write' => 'media:write'] as $legacy => $media) {
                            if (in_array($legacy, $current, true) && ! in_array($media, $next, true)) {
                                $next[] = $media;
                            }
                        }
                    }

                    if ($next !== $current) {
                        DB::table($table)->where('id', $row->id)->update([
                            'abilities' => json_encode(array_values(array_unique($next)), JSON_THROW_ON_ERROR),
                        ]);
                    }
                }
            });
    }
};
