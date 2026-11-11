<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Aditiva (T-19): aprobacion del post (`approval_status` nulo = no requiere aprobacion: pendiente | aprobado | rechazado)
// y pertenencia a una serie recurrente. Sin SQL especifico: compatible con MySQL y SQLite.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('posts', function (Blueprint $table) {
            $table->string('approval_status', 20)->nullable()->index();
            $table->text('approval_comment')->nullable();
            $table->timestamp('approval_requested_at')->nullable();
            $table->timestamp('approval_decided_at')->nullable();
            $table->unsignedBigInteger('approval_decided_by')->nullable();
            $table->unsignedBigInteger('series_id')->nullable()->index();
            $table->boolean('series_exception')->default(false);
        });
    }

    public function down(): void
    {
        Schema::table('posts', function (Blueprint $table) {
            $table->dropIndex(['approval_status']);
            $table->dropIndex(['series_id']);
            $table->dropColumn(['approval_status', 'approval_comment', 'approval_requested_at', 'approval_decided_at', 'approval_decided_by', 'series_id', 'series_exception']);
        });
    }
};
