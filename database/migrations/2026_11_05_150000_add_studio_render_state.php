<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('studio_design_exports', function (Blueprint $table) {
            $table->json('render_state')->nullable();
            $table->json('render_progress')->nullable();
        });
        // Allocation records deliberately survive parent FK cascades so orphan cleanup remains traceable.
        Schema::create('studio_av_workspaces', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id')->index();
            $table->unsignedBigInteger('brand_id');
            $table->unsignedBigInteger('export_id')->unique();
            $table->uuid('uuid')->unique();
            $table->string('fingerprint', 64);
            $table->json('plan')->nullable();
            $table->json('source_metadata')->nullable();
            $table->unsignedBigInteger('media_id')->nullable();
            $table->unsignedBigInteger('media_reserved_bytes')->default(0);
            $table->json('media_receipt')->nullable();
            $table->boolean('media_retiring')->default(false);
            $table->timestamp('media_cleanup_after')->nullable();
            $table->index(['media_retiring', 'media_cleanup_after'], 'studio_av_media_retirement');
            $table->index(['media_retiring', 'active_until'], 'studio_av_recovery_due');
            $table->timestamp('active_until')->index();
            $table->timestamps();
        });
        Schema::create('studio_av_leases', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignId('workspace_id')->constrained('studio_av_workspaces')->cascadeOnDelete();
            $table->uuid('generation');
            $table->string('phase', 20);
            $table->unsignedBigInteger('item');
            $table->string('category', 20);
            $table->unsignedBigInteger('reserved_bytes')->default(0);
            $table->unsignedBigInteger('actual_bytes')->nullable();
            $table->string('status', 20)->default('running');
            $table->json('result')->nullable();
            $table->timestamp('expires_at')->index();
            $table->timestamp('started_at')->nullable();
            $table->unsignedBigInteger('queue_job_id')->nullable();
            $table->boolean('input_published')->default(false);
            $table->timestamps();
            $table->index(['workspace_id', 'category', 'status']);
            $table->index(['workspace_id', 'category', 'status', 'input_published'], 'studio_av_input_promotion');
            $table->index(['workspace_id', 'phase', 'item', 'created_at', 'id'], 'studio_av_lease_source_pin');
        });
        Schema::create('studio_av_steps', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workspace_id')->constrained('studio_av_workspaces')->cascadeOnDelete();
            $table->uuid('generation');
            $table->unsignedInteger('position');
            $table->string('phase', 20);
            $table->unsignedBigInteger('item');
            $table->uuid('source_id')->nullable();
            $table->string('target_phase', 20)->nullable();
            $table->unsignedBigInteger('target_item')->nullable();
            $table->uuid('lease_id')->nullable();
            $table->uuid('result_lease_id')->nullable();
            $table->unique(['workspace_id', 'generation', 'position']);
            $table->index(['workspace_id', 'generation', 'phase', 'item']);
            $table->index(['workspace_id', 'generation', 'result_lease_id'], 'studio_av_step_result_membership');
            $table->foreign('lease_id')->references('id')->on('studio_av_leases')->nullOnDelete();
            $table->foreign('source_id')->references('id')->on('studio_av_leases')->nullOnDelete();
            $table->foreign('result_lease_id')->references('id')->on('studio_av_leases')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('studio_av_steps');
        Schema::dropIfExists('studio_av_leases');
        Schema::dropIfExists('studio_av_workspaces');
        Schema::table('studio_design_exports', fn (Blueprint $table) => $table->dropColumn(['render_state', 'render_progress']));
    }
};
