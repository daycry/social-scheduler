<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// T-84: estado de la exportación completa de datos (ZIP) de cada usuario. `in_progress` vale 1 solo mientras la exportación está en
// cola o en curso y NULL en el resto de estados: el índice único (user_id, in_progress) garantiza una sola en curso por usuario aunque
// lleguen dos peticiones a la vez (los NULL no chocan entre sí, en MySQL ni en SQLite). `heartbeat_at` es la marca de vida que escribe el job
// mientras trabaja: el atasco se mide sobre ella. `include_media` es la elección del usuario (con o sin los ficheros de media).
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('full_exports', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('status', 16)->default('pending');
            $table->boolean('in_progress')->nullable();
            $table->boolean('include_media')->default(true);
            $table->string('path')->nullable();
            $table->unsignedBigInteger('size')->nullable();
            $table->unsignedInteger('file_count')->nullable();
            $table->string('error', 500)->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('heartbeat_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamps();

            $table->unique(['user_id', 'in_progress']);
            $table->index(['status', 'expires_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('full_exports');
    }
};
