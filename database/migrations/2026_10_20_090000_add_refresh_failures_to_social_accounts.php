<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('social_accounts', function (Blueprint $table) {
            // Fallos transitorios de renovacion seguidos (se pone a 0 al renovar con exito).
            $table->unsignedSmallInteger('refresh_failures')->default(0)->after('last_refreshed_at');
        });
    }

    public function down(): void
    {
        Schema::table('social_accounts', function (Blueprint $table) {
            $table->dropColumn('refresh_failures');
        });
    }
};
