<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('posts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            // Borrar la MARCA (accion explicita del usuario) borra sus publicaciones; borrar/desconectar una
            // CUENTA nunca (ver post_targets.social_account_id).
            $table->foreignId('brand_id')->constrained()->cascadeOnDelete();
            $table->text('text')->nullable();
            $table->timestamp('scheduled_at')->nullable(); // siempre en UTC
            $table->string('status', 20)->default('borrador'); // agregado de sus targets
            $table->timestamps();

            $table->index(['user_id', 'scheduled_at']);
            $table->index(['brand_id', 'scheduled_at']);
            $table->index(['status', 'scheduled_at']);
        });

        Schema::create('post_targets', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('post_id')->constrained()->cascadeOnDelete();
            // NO cascadeOnDelete: si la cuenta se borra la publicacion pendiente se conserva (cuenta null)
            // y el publicador la marcara `fallida` con motivo; desconectar o pasar a requiere_reconexion
            // solo cambia el estado de la cuenta.
            $table->foreignId('social_account_id')->nullable()->constrained()->nullOnDelete();
            $table->string('network', 20);
            $table->string('content_type', 40);
            $table->string('status', 20)->default('borrador');
            $table->json('options')->nullable();
            $table->string('external_id')->nullable();
            $table->string('external_url', 500)->nullable();
            $table->string('external_reference')->nullable(); // contenedor de IG, publish_id de TikTok...
            $table->unsignedInteger('attempts')->default(0);
            $table->string('error_code', 60)->nullable();
            $table->string('error_message', 500)->nullable();
            $table->timestamp('started_at')->nullable(); // entro en `publicando`
            $table->timestamp('next_attempt_at')->nullable();
            $table->timestamp('published_at')->nullable();
            $table->timestamps();

            $table->unique(['post_id', 'social_account_id', 'content_type'], 'post_targets_unique_per_post');
            $table->index(['status', 'next_attempt_at']);
            $table->index(['user_id', 'status']);
            $table->index('social_account_id');
        });

        Schema::create('post_media', function (Blueprint $table) {
            $table->id();
            $table->foreignId('post_id')->constrained()->cascadeOnDelete();
            // restrict: una media usada por una publicacion no se puede borrar por debajo.
            $table->foreignId('media_asset_id')->constrained()->restrictOnDelete();
            $table->unsignedSmallInteger('position')->default(0);

            $table->unique(['post_id', 'position']);
        });

        Schema::create('publish_attempts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('post_target_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('attempt_no');
            $table->string('outcome', 30); // iniciado | publicado | procesando | transitorio | permanente | cuota
            $table->string('error_code', 60)->nullable();
            $table->string('message', 500)->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();

            $table->index(['post_target_id', 'attempt_no']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('publish_attempts');
        Schema::dropIfExists('post_media');
        Schema::dropIfExists('post_targets');
        Schema::dropIfExists('posts');
    }
};
