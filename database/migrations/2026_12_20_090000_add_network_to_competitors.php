<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // T-25: competidores de Bluesky y YouTube ademas de Instagram. `external_id` = identificador estable de la red (DID de
        // Bluesky, id de canal de YouTube); el usuario/handle se guarda en `username` (los handles de Bluesky llegan a 253 caracteres).
        Schema::table('competitors', function (Blueprint $table) {
            $table->dropUnique(['brand_id', 'username']);
        });

        Schema::table('competitors', function (Blueprint $table) {
            $table->string('network', 20)->default('instagram')->after('brand_id');
            $table->string('external_id', 100)->nullable()->after('username');
            $table->string('username', 253)->change();
            $table->unique(['brand_id', 'network', 'username']);
        });
    }

    public function down(): void
    {
        Schema::table('competitors', function (Blueprint $table) {
            $table->dropUnique(['brand_id', 'network', 'username']);
        });

        Schema::table('competitors', function (Blueprint $table) {
            $table->dropColumn(['network', 'external_id']);
            $table->unique(['brand_id', 'username']);
        });
    }
};
