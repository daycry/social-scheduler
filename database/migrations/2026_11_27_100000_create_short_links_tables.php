<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Acortador propio (T-89): enlaces `/s/{code}` de un usuario. El codigo es unico en toda la aplicacion.
        Schema::create('short_links', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('code', 8)->unique();
            $table->string('url', 2000);
            $table->string('label', 100)->nullable();
            $table->boolean('active')->default(true);
            $table->timestamps();

            $table->index(['user_id', 'id']);
        });

        // Clics AGREGADOS por dia UTC: ni IP, ni agente, ni referer, ni hash, ni tabla de eventos por visita.
        Schema::create('short_link_clicks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('short_link_id')->constrained()->cascadeOnDelete();
            $table->date('date');
            $table->unsignedInteger('count')->default(0);

            $table->unique(['short_link_id', 'date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('short_link_clicks');
        Schema::dropIfExists('short_links');
    }
};
