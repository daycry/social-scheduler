<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Buzon de comentarios de Instagram/Facebook (PA-10): comentarios de publicaciones PROPIAS (sin mensajes directos).
        Schema::create('inbox_comments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('brand_id')->constrained()->cascadeOnDelete();
            $table->foreignId('social_account_id')->constrained()->cascadeOnDelete();
            $table->foreignId('post_target_id')->nullable()->constrained()->nullOnDelete();
            $table->string('network', 20);
            $table->string('external_id', 100);
            // Comentario al que responde (respuestas de terceros o nuestras); null = comentario de primer nivel.
            $table->string('parent_external_id', 100)->nullable();
            $table->string('post_external_id', 100)->nullable();
            $table->string('author_name')->nullable();
            $table->text('text')->nullable();
            // Respuesta escrita por la propia cuenta (desde aqui o desde la red).
            $table->boolean('is_own')->default(false);
            $table->boolean('hidden')->default(false);
            $table->timestamp('commented_at')->nullable();
            $table->timestamp('read_at')->nullable();
            $table->timestamps();

            $table->unique(['social_account_id', 'external_id']);
            $table->index(['user_id', 'brand_id', 'is_own', 'read_at']);
            $table->index(['user_id', 'commented_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('inbox_comments');
    }
};
