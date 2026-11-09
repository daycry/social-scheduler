<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Constructor de informes (T-21): plantillas por marca, programaciones con destinatarios y bloques del informe. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('report_templates', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('brand_id')->constrained()->cascadeOnDelete();
            $table->string('name', 80);
            $table->json('blocks');
            $table->unsignedSmallInteger('range_days')->default(30);
            $table->string('compare', 10)->default('none');
            $table->timestamps();

            $table->unique(['brand_id', 'name']);
            $table->index('user_id');
        });

        Schema::create('report_schedules', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('brand_id')->constrained()->cascadeOnDelete();
            $table->foreignId('report_template_id')->constrained()->cascadeOnDelete();
            $table->string('frequency', 8); // weekly | monthly
            $table->json('recipients');
            $table->boolean('active')->default(true);
            $table->timestamp('next_run_at')->nullable();
            $table->timestamp('last_run_at')->nullable();
            $table->string('last_error', 500)->nullable();
            $table->timestamps();

            $table->index(['active', 'next_run_at']);
            $table->index('user_id');
        });

        Schema::table('reports', function (Blueprint $table) {
            $table->json('blocks')->nullable();
            $table->unsignedBigInteger('schedule_id')->nullable()->index();
        });
    }

    public function down(): void
    {
        Schema::table('reports', function (Blueprint $table) {
            $table->dropIndex(['schedule_id']);
            $table->dropColumn(['blocks', 'schedule_id']);
        });
        Schema::dropIfExists('report_schedules');
        Schema::dropIfExists('report_templates');
    }
};
