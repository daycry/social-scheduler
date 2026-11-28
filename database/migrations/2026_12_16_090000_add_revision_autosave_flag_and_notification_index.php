<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Marca las revisiones que nacen de un autoguardado: solo estas se funden entre si; los puntos de control no se tocan (A-07).
        Schema::table('studio_design_revisions', fn (Blueprint $table) => $table->boolean('autosave')->default(false));
        // La poda diaria de notificaciones leidas filtra por read_at.
        Schema::table('notifications', fn (Blueprint $table) => $table->index('read_at', 'notifications_read_at_prune'));
    }

    public function down(): void
    {
        Schema::table('notifications', fn (Blueprint $table) => $table->dropIndex('notifications_read_at_prune'));
        Schema::table('studio_design_revisions', fn (Blueprint $table) => $table->dropColumn('autosave'));
    }
};
