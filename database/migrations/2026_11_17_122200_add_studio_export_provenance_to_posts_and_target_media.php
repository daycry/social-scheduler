<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('posts', $this->addProvenance(...));
        Schema::table('post_media', $this->addProvenance(...));

        // En instalaciones nuevas la migración T-06 crea esta tabla después con la misma FK.
        if (Schema::hasTable('post_target_media')) {
            Schema::table('post_target_media', $this->addProvenance(...));
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('post_target_media')) {
            Schema::table('post_target_media', fn (Blueprint $table) => $table->dropConstrainedForeignId('design_export_id'));
        }
        Schema::table('post_media', fn (Blueprint $table) => $table->dropConstrainedForeignId('design_export_id'));
        Schema::table('posts', fn (Blueprint $table) => $table->dropConstrainedForeignId('design_export_id'));
    }

    private function addProvenance(Blueprint $table): void
    {
        $table->foreignId('design_export_id')->nullable()->constrained('studio_design_exports')->nullOnDelete();
    }
};
