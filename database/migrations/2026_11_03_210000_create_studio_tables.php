<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('studio_design_folders', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('brand_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->timestamps();
            $table->index(['user_id', 'brand_id']);
        });
        Schema::create('studio_designs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('brand_id')->constrained()->cascadeOnDelete();
            $table->foreignId('folder_id')->nullable()->constrained('studio_design_folders')->nullOnDelete();
            $table->string('name');
            $table->json('document');
            $table->unsignedInteger('width');
            $table->unsignedInteger('height');
            $table->unsignedSmallInteger('page_count');
            $table->unsignedInteger('revision')->default(1);
            $table->boolean('is_template')->default(false);
            $table->timestamps();
            $table->softDeletes();
            $table->index(['user_id', 'brand_id', 'updated_at']);
        });
        Schema::create('studio_design_revisions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('design_id')->constrained('studio_designs')->cascadeOnDelete();
            $table->unsignedInteger('revision');
            $table->string('name');
            $table->json('document');
            $table->timestamps();
            $table->unique(['design_id', 'revision']);
        });
        Schema::create('studio_brand_kits', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('brand_id')->constrained()->cascadeOnDelete();
            $table->json('palette');
            $table->json('fonts');
            $table->json('logos');
            $table->text('guidelines')->nullable();
            $table->text('voice')->nullable();
            $table->timestamps();
            $table->unique(['user_id', 'brand_id']);
        });
        Schema::create('studio_assets', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('brand_id')->constrained()->cascadeOnDelete();
            $table->foreignId('media_asset_id')->constrained()->restrictOnDelete();
            $table->timestamps();
            $table->unique(['brand_id', 'media_asset_id']);
            $table->index(['user_id', 'brand_id']);
        });
        Schema::create('studio_design_exports', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('brand_id')->constrained()->cascadeOnDelete();
            $table->foreignId('design_id')->constrained('studio_designs')->cascadeOnDelete();
            $table->unsignedInteger('revision');
            $table->string('format', 12);
            $table->json('pages');
            $table->json('media_ids')->nullable();
            $table->json('options')->nullable();
            $table->string('status', 20)->default('pending');
            $table->string('disk')->nullable();
            $table->text('path')->nullable();
            $table->text('error')->nullable();
            $table->timestamps();
            $table->index(['user_id', 'brand_id', 'design_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('studio_design_exports');
        Schema::dropIfExists('studio_assets');
        Schema::dropIfExists('studio_brand_kits');
        Schema::dropIfExists('studio_design_revisions');
        Schema::dropIfExists('studio_designs');
        Schema::dropIfExists('studio_design_folders');
    }
};
