<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Aditiva: el ultimo fallo al generar ocurrencias de una serie (antes se tragaba y la serie seguia «activa» sin rastro).
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('post_series', function (Blueprint $table) {
            $table->text('last_error')->nullable()->after('status');
            $table->timestamp('last_error_at')->nullable()->after('last_error');
        });
    }

    public function down(): void
    {
        Schema::table('post_series', function (Blueprint $table) {
            $table->dropColumn(['last_error', 'last_error_at']);
        });
    }
};
