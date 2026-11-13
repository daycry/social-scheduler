<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Aditiva (T-32): indice de los destinos manuales programados que recorren `ManualPublishService::notifyDue()` y `remindUpcoming()` en cada
// pasada del despachador (sin el, filtran por `status` y descartan casi todo). `posts.scheduled_at` ya tiene indice propio y compuestos
// (`status`, `scheduled_at`), asi que no se duplica.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('post_targets', function (Blueprint $table) {
            $table->index(['manual', 'status'], 'post_targets_manual_status_idx');
        });
    }

    public function down(): void
    {
        Schema::table('post_targets', function (Blueprint $table) {
            $table->dropIndex('post_targets_manual_status_idx');
        });
    }
};
