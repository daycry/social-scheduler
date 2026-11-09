<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // T-20: bloques enriquecidos (imagen, video de YouTube, separador, iconos sociales, formulario de email, posts recientes),
        // visibilidad programada y suscriptores del formulario propio (sin servicio externo).
        Schema::table('smart_link_blocks', function (Blueprint $table) {
            $table->unsignedBigInteger('media_id')->nullable();
            $table->json('data')->nullable();
            $table->timestamp('visible_from')->nullable();
            $table->timestamp('visible_until')->nullable();
        });

        Schema::create('smart_link_subscribers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('smart_link_id')->constrained()->cascadeOnDelete();
            $table->foreignId('smart_link_block_id')->constrained()->cascadeOnDelete();
            $table->string('email', 254);
            $table->timestamp('created_at')->nullable();

            $table->unique(['smart_link_block_id', 'email']);
            $table->index(['smart_link_id', 'id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('smart_link_subscribers');
        Schema::table('smart_link_blocks', function (Blueprint $table) {
            $table->dropColumn(['media_id', 'data', 'visible_from', 'visible_until']);
        });
    }
};
