<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('studio_fonts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('brand_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('family', 100);
            $table->string('style', 12);
            $table->unsignedSmallInteger('weight');
            $table->string('format', 8);
            $table->string('mime', 32);
            $table->unsignedInteger('size');
            $table->string('sha256', 64);
            $table->string('source_sha256', 64);
            $table->string('disk');
            $table->string('path');
            $table->string('status', 16)->default('ready');
            $table->timestamp('archived_at')->nullable();
            $table->timestamps();
            $table->index(['user_id', 'brand_id', 'archived_at']);
        });
        Schema::create('studio_font_usages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('font_id')->constrained('studio_fonts')->cascadeOnDelete();
            $table->foreignId('revision_id')->constrained('studio_design_revisions')->cascadeOnDelete();
            $table->unique(['font_id', 'revision_id']);
        });
        Schema::table('studio_brand_kits', fn (Blueprint $table) => $table->json('font_ids')->nullable());
        Schema::create('studio_brand_kit_fonts', function (Blueprint $table) {
            $table->foreignId('kit_id')->constrained('studio_brand_kits')->cascadeOnDelete();
            $table->foreignId('font_id')->constrained('studio_fonts')->cascadeOnDelete();
            $table->primary(['kit_id', 'font_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('studio_brand_kit_fonts');
        Schema::table('studio_brand_kits', fn (Blueprint $table) => $table->dropColumn('font_ids'));
        Schema::dropIfExists('studio_font_usages');
        Schema::dropIfExists('studio_fonts');
    }
};
