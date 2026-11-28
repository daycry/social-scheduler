<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            // Feed .ics del calendario (T-90): solo se guarda el SHA-256 del token (el original se muestra una vez); nulo = sin feed.
            $table->string('calendar_feed_token_hash', 64)->nullable()->unique();
            $table->timestamp('calendar_feed_token_at')->nullable();
            // Fechas señaladas ocultas (T-90): visibles por defecto.
            $table->boolean('hide_marketing_dates')->default(false);
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropUnique(['calendar_feed_token_hash']);
            $table->dropColumn(['calendar_feed_token_hash', 'calendar_feed_token_at', 'hide_marketing_dates']);
        });
    }
};
