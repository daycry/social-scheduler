<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // T-20: ultimas publicaciones PUBLICAS de un competidor (Business Discovery `media`). Los likes y comentarios son null cuando
        // la cuenta los oculta: nunca se inventa una metrica que la API no da.
        Schema::create('competitor_posts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('competitor_id')->constrained()->cascadeOnDelete();
            $table->string('external_id', 40);
            $table->string('caption', 300)->nullable();
            $table->string('media_type', 20)->nullable();
            $table->string('permalink', 500)->nullable();
            $table->unsignedBigInteger('likes_count')->nullable();
            $table->unsignedBigInteger('comments_count')->nullable();
            $table->timestamp('posted_at')->nullable();
            $table->timestamps();

            $table->unique(['competitor_id', 'external_id']);
            $table->index(['competitor_id', 'posted_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('competitor_posts');
    }
};
