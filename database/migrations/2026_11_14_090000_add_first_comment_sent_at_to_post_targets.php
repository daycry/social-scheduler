<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Aditiva: marca (escrita ANTES del POST) de que el primer comentario pudo enviarse. Reintentar no la borra: sin ella no hay
// forma de saber si un timeout del POST dejo el comentario creado en la red.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('post_targets', function (Blueprint $table) {
            $table->timestamp('first_comment_sent_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('post_targets', function (Blueprint $table) {
            $table->dropColumn('first_comment_sent_at');
        });
    }
};
