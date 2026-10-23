<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Biblioteca (PA-06): contenido reutilizable. `targets` guarda [{account_id, content_type}] tal como los
        // pide PostService; al usar la plantilla solo se conservan los que siguen siendo validos.
        Schema::create('post_templates', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('brand_id')->constrained()->cascadeOnDelete();
            $table->string('name', 120);
            $table->text('text')->nullable();
            $table->json('targets')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'brand_id']);
        });

        Schema::create('post_template_media', function (Blueprint $table) {
            $table->id();
            $table->foreignId('post_template_id')->constrained()->cascadeOnDelete();
            // Borrar el fichero lo quita de la plantilla (a diferencia de una publicacion, aqui no hay rastro externo).
            $table->foreignId('media_asset_id')->constrained()->cascadeOnDelete();
            $table->unsignedSmallInteger('position')->default(0);

            $table->unique(['post_template_id', 'position']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('post_template_media');
        Schema::dropIfExists('post_templates');
    }
};
