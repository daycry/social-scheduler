<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Sin backfill: las referencias históricas permanecen personales.
        Schema::table('audit_logs', function (Blueprint $table) {
            $table->foreignId('brand_id')->nullable()->constrained()->nullOnDelete();
            $table->index(['brand_id', 'created_at']);
            $table->index(['user_id', 'action']);
            $table->index(['brand_id', 'user_id', 'action']);
        });

    }

    public function down(): void
    {
        Schema::table('audit_logs', function (Blueprint $table) {
            $table->dropForeign(['brand_id']);
            $table->dropIndex(['brand_id', 'created_at']);
            $table->dropIndex(['user_id', 'action']);
            $table->dropIndex(['brand_id', 'user_id', 'action']);
            $table->dropColumn('brand_id');
        });
    }
};
