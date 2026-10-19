<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('media_assets', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('disk', 30);
            $table->string('path');
            $table->string('original_name');
            $table->string('mime', 100);
            $table->string('kind', 10); // image | video
            $table->unsignedBigInteger('declared_size');
            $table->unsignedBigInteger('size')->nullable();
            $table->unsignedInteger('width')->nullable();
            $table->unsignedInteger('height')->nullable();
            $table->decimal('duration', 10, 3)->nullable(); // segundos
            $table->decimal('fps', 7, 3)->nullable();
            $table->string('codec', 30)->nullable();
            $table->string('audio_codec', 30)->nullable();
            $table->string('status', 20)->default('pendiente'); // pendiente | listo | invalido
            $table->string('upload_id')->nullable(); // multipart de S3/R2
            $table->string('error', 500)->nullable();
            $table->timestamps();

            $table->index(['user_id', 'status']);
            $table->unique(['disk', 'path']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('media_assets');
    }
};
