<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Autolistas (PA-09): una cola de contenido con franjas semanales (dia ISO + hora en la zona de la marca).
        Schema::create('autolists', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('brand_id')->constrained()->cascadeOnDelete();
            $table->string('name', 120);
            $table->boolean('active')->default(true);
            // Al llegar al final de la cola, vuelve a empezar (reciclado).
            $table->boolean('recycle')->default(false);
            // [{account_id, content_type}] destinos de cada publicacion generada.
            $table->json('targets');
            // [{day: 1..7 (ISO, lunes=1), time: "HH:MM"}] en la zona horaria de la marca.
            $table->json('slots');
            // Posicion (0-based) del siguiente elemento de la cola.
            $table->unsignedInteger('cursor')->default(0);
            $table->timestamp('last_generated_at')->nullable();
            $table->timestamps();

            $table->index('user_id');
            $table->index(['active', 'brand_id']);
        });

        Schema::create('autolist_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('autolist_id')->constrained()->cascadeOnDelete();
            // Plantilla de la Biblioteca (si se borra, el elemento conserva su texto/media propios, si los tiene).
            $table->foreignId('post_template_id')->nullable()->constrained('post_templates')->nullOnDelete();
            $table->text('text')->nullable();
            $table->json('media')->nullable(); // ids de MediaAsset en orden
            $table->unsignedInteger('position')->default(0);
            $table->unsignedInteger('times_used')->default(0);
            $table->timestamp('last_used_at')->nullable();
            $table->timestamps();

            $table->index(['autolist_id', 'position']);
        });

        // Registro de franjas ya generadas: la unicidad (lista + instante UTC) hace idempotente el job diario.
        Schema::create('autolist_slots', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('autolist_id')->constrained()->cascadeOnDelete();
            $table->timestamp('slot_at');
            $table->foreignId('post_id')->nullable()->constrained()->nullOnDelete();
            $table->string('skipped_reason', 255)->nullable();
            $table->timestamps();

            $table->unique(['autolist_id', 'slot_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('autolist_slots');
        Schema::dropIfExists('autolist_items');
        Schema::dropIfExists('autolists');
    }
};
