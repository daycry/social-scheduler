<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('studio_design_shares', function (Blueprint $table) {
            $table->id();
            $table->foreignId('design_id')->constrained('studio_designs')->cascadeOnDelete();
            $table->foreignId('owner_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('role', 12);
            $table->timestamps();
            $table->unique(['design_id', 'user_id']);
            $table->index('user_id');
        });
        Schema::create('studio_design_links', function (Blueprint $table) {
            $table->id();
            $table->foreignId('design_id')->constrained('studio_designs')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->char('token_hash', 64)->unique();
            $table->string('role', 12);
            $table->timestamp('expires_at')->nullable();
            $table->timestamp('revoked_at')->nullable();
            $table->timestamp('last_used_at')->nullable();
            $table->timestamps();
            $table->index('design_id');
        });
        Schema::create('studio_design_comments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('design_id')->constrained('studio_designs')->cascadeOnDelete();
            $table->unsignedBigInteger('author_id')->nullable()->index();
            $table->unsignedBigInteger('link_id')->nullable();
            $table->string('author_name', 80);
            $table->unsignedBigInteger('parent_id')->nullable()->index();
            $table->string('page_id', 64)->nullable();
            $table->string('element_id', 64)->nullable();
            $table->unsignedInteger('revision');
            $table->text('body');
            $table->timestamp('resolved_at')->nullable();
            $table->unsignedBigInteger('resolved_by')->nullable();
            $table->timestamps();
            $table->index(['design_id', 'created_at']);
        });
        Schema::table('studio_design_revisions', fn (Blueprint $table) => $table->unsignedBigInteger('author_id')->nullable());
    }

    public function down(): void
    {
        Schema::table('studio_design_revisions', fn (Blueprint $table) => $table->dropColumn('author_id'));
        Schema::dropIfExists('studio_design_comments');
        Schema::dropIfExists('studio_design_links');
        Schema::dropIfExists('studio_design_shares');
    }
};
