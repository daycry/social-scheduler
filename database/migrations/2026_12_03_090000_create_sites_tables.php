<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * T-15 · Sitios: borrador y version publicada viven en el mismo registro (JSON), asi editar nunca toca lo publicado.
 * Compatible con MySQL y SQLite (solo tipos portables, sin SQL especifico).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sites', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('brand_id')->constrained()->cascadeOnDelete();
            $table->string('slug', 40)->unique();
            $table->string('title', 100);
            $table->string('description', 300)->nullable();
            $table->string('theme_source', 10)->default('kit');
            $table->string('theme_color', 7)->nullable();
            $table->string('theme_mode', 5)->default('light');
            $table->boolean('noindex')->default(false);
            $table->string('access_mode', 10)->default('public');
            $table->string('password_hash')->nullable();
            // Sube al cambiar modo/contrasena o al revocar: invalida de golpe todas las sesiones de acceso ya emitidas.
            $table->unsignedInteger('access_version')->default(1);
            $table->json('draft');
            $table->json('published')->nullable();
            $table->boolean('is_published')->default(false);
            $table->timestamp('published_at')->nullable();
            $table->unsignedInteger('revision')->default(1);
            $table->unsignedInteger('published_revision')->nullable();
            $table->timestamps();
            $table->index(['user_id', 'brand_id']);
        });

        Schema::create('site_tokens', function (Blueprint $table) {
            $table->id();
            $table->foreignId('site_id')->constrained()->cascadeOnDelete();
            $table->string('label', 60);
            $table->string('token_hash', 64)->unique();
            $table->timestamp('expires_at')->nullable();
            $table->timestamp('revoked_at')->nullable();
            $table->timestamp('last_used_at')->nullable();
            $table->timestamp('created_at')->nullable();
        });

        // Solo contadores diarios agregados por pagina: ni IP, ni agente, ni referer.
        Schema::create('site_visits', function (Blueprint $table) {
            $table->id();
            $table->foreignId('site_id')->constrained()->cascadeOnDelete();
            $table->string('page_key', 16);
            $table->date('date');
            $table->unsignedInteger('count')->default(0);
            $table->unique(['site_id', 'page_key', 'date']);
        });

        Schema::create('site_messages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('site_id')->constrained()->cascadeOnDelete();
            $table->string('page_key', 16);
            $table->string('block_key', 16);
            $table->string('name', 100);
            $table->string('email', 254);
            $table->text('message');
            $table->timestamp('created_at')->nullable();
            $table->index(['site_id', 'id']);
        });

        // Encuesta: resultados agregados por opcion; `options_hash` separa los votos si se cambian las opciones al republicar.
        Schema::create('site_poll_votes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('site_id')->constrained()->cascadeOnDelete();
            $table->string('block_key', 16);
            $table->string('options_hash', 40);
            $table->unsignedTinyInteger('option_index');
            $table->unsignedInteger('count')->default(0);
            $table->unique(['site_id', 'block_key', 'options_hash', 'option_index'], 'site_poll_votes_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('site_poll_votes');
        Schema::dropIfExists('site_messages');
        Schema::dropIfExists('site_visits');
        Schema::dropIfExists('site_tokens');
        Schema::dropIfExists('sites');
    }
};
