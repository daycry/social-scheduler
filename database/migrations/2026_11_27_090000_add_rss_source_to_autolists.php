<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Origen RSS/Atom de una autolista (T-88): URL del feed y estado del ultimo sondeo (fecha y error legible, sin datos del feed).
        Schema::table('autolists', function (Blueprint $table) {
            $table->string('rss_url', 2048)->nullable()->after('slots');
            $table->timestamp('rss_checked_at')->nullable()->after('rss_url');
            $table->string('rss_error', 255)->nullable()->after('rss_checked_at');
        });

        // Elemento nacido de una entrada de feed: `text` es el titulo y `link` su enlace; el texto de cada publicacion se compone al generarla, ajustado
        // al limite de cada destino (el enlace nunca se recorta).
        Schema::table('autolist_items', function (Blueprint $table) {
            $table->string('link', 2048)->nullable()->after('text');
        });

        // Entradas del feed ya vistas por lista y feed: la clave (sha256 del guid, el enlace o el contenido) es UNICA, asi que dos ejecuciones
        // simultaneas no duplican y una entrada quitada de la cola o editada en el feed no vuelve a entrar.
        Schema::create('autolist_rss_entries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('autolist_id')->constrained()->cascadeOnDelete();
            $table->char('feed_hash', 64); // sha256 de la URL del feed: las entradas vistas son de cada feed, no de la lista
            $table->char('entry_key', 64);
            $table->timestamp('created_at')->nullable();

            $table->unique(['autolist_id', 'feed_hash', 'entry_key']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('autolist_rss_entries');
        Schema::table('autolist_items', function (Blueprint $table) {
            $table->dropColumn('link');
        });
        Schema::table('autolists', function (Blueprint $table) {
            $table->dropColumn(['rss_url', 'rss_checked_at', 'rss_error']);
        });
    }
};
