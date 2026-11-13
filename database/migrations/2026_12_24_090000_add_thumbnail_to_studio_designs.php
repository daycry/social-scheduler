<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Aditiva (T-35): miniatura cacheada por diseno. `thumbnail_revision` es la revision del diseno que se renderizo; si no coincide con
// `revision`, la miniatura esta caducada (se edito) y se regenera. Generar la miniatura no toca `revision` ni `updated_at`.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('studio_designs', function (Blueprint $table) {
            $table->string('thumbnail_path')->nullable();
            $table->unsignedInteger('thumbnail_revision')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('studio_designs', function (Blueprint $table) {
            $table->dropColumn(['thumbnail_path', 'thumbnail_revision']);
        });
    }
};
