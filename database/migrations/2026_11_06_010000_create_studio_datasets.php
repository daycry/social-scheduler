<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('studio_datasets', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('brand_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->unsignedInteger('revision');
            $table->unsignedSmallInteger('row_count');
            $table->unsignedSmallInteger('column_count');
            $table->timestamp('archived_at')->nullable();
            $table->timestamps();
            $table->index(['user_id', 'brand_id', 'archived_at']);
        });
        Schema::create('studio_dataset_revisions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('dataset_id')->constrained('studio_datasets')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('brand_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('revision');
            $table->string('name');
            $table->unsignedSmallInteger('row_count');
            $table->unsignedSmallInteger('column_count');
            $table->unsignedInteger('json_bytes');
            $table->char('sha256', 64);
            $table->string('path')->unique();
            $table->timestamps();
            $table->unique(['dataset_id', 'revision']);
            $table->index(['user_id', 'brand_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('studio_dataset_revisions');
        Schema::dropIfExists('studio_datasets');
    }
};
