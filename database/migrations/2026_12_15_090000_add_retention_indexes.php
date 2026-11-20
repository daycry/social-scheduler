<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** Tablas podadas por `model:prune` (A-08) y la columna por la que se filtran: sin índice la poda recorre la tabla entera. */
    private const INDEXES = [
        'audit_logs' => 'created_at',
        'publish_attempts' => 'created_at',
        'studio_ai_jobs' => 'created_at',
        'studio_image_jobs' => 'created_at',
        'canva_jobs' => 'created_at',
        'studio_design_exports' => 'created_at',
        'studio_design_revisions' => 'created_at',
    ];

    public function up(): void
    {
        foreach (self::INDEXES as $table => $column) {
            Schema::table($table, fn (Blueprint $t) => $t->index($column, "{$table}_{$column}_prune"));
        }
    }

    public function down(): void
    {
        foreach (self::INDEXES as $table => $column) {
            Schema::table($table, fn (Blueprint $t) => $t->dropIndex("{$table}_{$column}_prune"));
        }
    }
};
