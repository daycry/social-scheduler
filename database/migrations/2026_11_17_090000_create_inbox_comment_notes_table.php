<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Notas internas de un comentario del inbox (C-04d). Tabla aparte: el inbox hace upsert de `inbox_comments`
        // y las notas cuelgan de la fila, que se conserva al resincronizar.
        Schema::create('inbox_comment_notes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('inbox_comment_id')->constrained()->cascadeOnDelete();
            $table->text('body');
            $table->timestamps();

            $table->index(['inbox_comment_id', 'id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('inbox_comment_notes');
    }
};
