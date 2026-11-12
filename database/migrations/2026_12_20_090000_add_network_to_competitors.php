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
            $table->string('network', 20)->default('instagram')->after('brand_id');
            $table->string('external_id', 100)->nullable()->after('username');
            $table->string('username', 253)->change();
        });

        // ORDEN IMPORTANTE en MySQL: `brand_id` es una FK y el unico (brand_id, username) es el indice que la sostiene; borrarlo antes de
        // que exista otro indice que empiece por `brand_id` falla con el error 1553. Primero se crea el nuevo y despues se borra el antiguo.
        Schema::table('competitors', function (Blueprint $table) {
            $table->unique(['brand_id', 'network', 'username']);
        });

        Schema::table('competitors', function (Blueprint $table) {
            $table->dropUnique(['brand_id', 'username']);
        });
    }

    public function down(): void
    {
        // Mismo motivo, orden inverso: se recrea el unico antiguo antes de borrar el nuevo (que sostiene la FK de `brand_id`).
        Schema::table('competitors', function (Blueprint $table) {
            $table->unique(['brand_id', 'username']);
        });

        Schema::table('competitors', function (Blueprint $table) {
            $table->dropUnique(['brand_id', 'network', 'username']);
        });

        Schema::table('competitors', function (Blueprint $table) {
            $table->dropColumn(['network', 'external_id']);
        });
    }
};
