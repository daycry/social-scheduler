<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Aditiva (T-17): media propia por destino. Nulo = el destino hereda la media comun del post (`post_media`).
// Con valor: lista JSON ordenada de {id, alt, cover_id}. Json nativo en MySQL y texto en SQLite: sin SQL especifico.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('post_targets', function (Blueprint $table) {
            $table->json('media')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('post_targets', function (Blueprint $table) {
            $table->dropColumn('media');
        });
    }
};
