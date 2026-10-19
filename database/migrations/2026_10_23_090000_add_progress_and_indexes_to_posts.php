<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('post_targets', function (Blueprint $table) {
            // Progreso reanudable de publicaciones de varios pasos (p. ej. carrusel de IG: hijos creados/listos, padre).
            $table->json('progress')->nullable()->after('external_reference');
        });

        Schema::table('posts', function (Blueprint $table) {
            // El despachador y la recuperacion filtran por `scheduled_at` sin `user_id`/`brand_id`/`status` por delante.
            $table->index('scheduled_at');
        });
    }

    public function down(): void
    {
        Schema::table('posts', function (Blueprint $table) {
            $table->dropIndex(['scheduled_at']);
        });

        Schema::table('post_targets', function (Blueprint $table) {
            $table->dropColumn('progress');
        });
    }
};
