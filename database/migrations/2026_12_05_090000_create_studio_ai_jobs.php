<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('studio_ai_jobs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('brand_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('source_media_id')->nullable();
            $table->string('kind', 20);
            $table->string('provider', 20);
            $table->string('model', 80);
            $table->text('prompt');
            $table->text('params')->nullable();
            $table->unsignedTinyInteger('count')->default(1);
            $table->string('status', 20)->default('queued');
            $table->unsignedTinyInteger('attempts')->default(0);
            $table->string('input_sha256', 64)->nullable();
            $table->string('fingerprint', 64);
            $table->string('idempotency_key', 64)->nullable();
            $table->text('output_media_ids')->nullable();
            $table->string('error_code', 40)->nullable();
            $table->string('error', 500)->nullable();
            $table->timestamps();
            $table->unique(['user_id', 'idempotency_key']);
            $table->index(['user_id', 'brand_id', 'status']);
            $table->index(['user_id', 'provider', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('studio_ai_jobs');
    }
};
