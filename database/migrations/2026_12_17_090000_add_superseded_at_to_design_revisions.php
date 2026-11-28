<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Un autoguardado sustituye a la revision de autoguardado anterior sin borrarla: otro cliente (colaborador, otra pestaña)
        // puede seguir usandola como base de una exportacion. La poda la elimina pasado el margen `retention.design_revisions.superseded_minutes` (15 min) y solo se conserva una sustituida por diseño y autor.
        Schema::table('studio_design_revisions', function (Blueprint $table) {
            $table->timestamp('superseded_at')->nullable();
            $table->index('superseded_at', 'studio_design_revisions_superseded_prune');
        });
    }

    public function down(): void
    {
        Schema::table('studio_design_revisions', function (Blueprint $table) {
            $table->dropIndex('studio_design_revisions_superseded_prune');
            $table->dropColumn('superseded_at');
        });
    }
};
