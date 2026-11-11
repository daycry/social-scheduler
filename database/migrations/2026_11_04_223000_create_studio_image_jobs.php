<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('studio_image_jobs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('brand_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('source_media_id');
            $table->foreignId('output_media_id')->nullable()->constrained('media_assets')->nullOnDelete();
            $table->string('operation', 40);
            $table->string('status', 20)->default('queued');
            $table->string('model', 80);
            $table->text('model_path');
            $table->string('model_sha256', 64);
            $table->string('input_sha256', 64);
            $table->string('error_code', 80)->nullable();
            $table->string('error', 500)->nullable();
            $table->timestamps();
            $table->index(['user_id', 'brand_id', 'source_media_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('studio_image_jobs');
    }
};
