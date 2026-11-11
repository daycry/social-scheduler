<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Aditiva (T-19): series recurrentes. La clave unica (serie, fecha local) de `post_series_occurrences` hace idempotente la generacion.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('post_series', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('brand_id')->constrained()->cascadeOnDelete();
            $table->string('frequency', 12); // diaria | semanal | mensual
            $table->json('weekdays')->nullable(); // semanal: dias ISO 1 (lunes) .. 7 (domingo)
            $table->string('time_local', 5); // HH:MM en la zona de la marca
            $table->date('starts_on');
            $table->date('ends_on')->nullable();
            $table->unsignedSmallInteger('max_occurrences')->nullable();
            $table->json('payload'); // text, media, targets (misma forma que PostService::create)
            $table->string('status', 12)->default('activa'); // activa | cancelada
            $table->timestamp('cancelled_at')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'status']);
        });

        Schema::create('post_series_occurrences', function (Blueprint $table) {
            $table->id();
            $table->foreignId('series_id')->constrained('post_series')->cascadeOnDelete();
            $table->date('occurrence_date'); // fecha local de la marca
            $table->unsignedBigInteger('post_id')->nullable()->index();
            $table->timestamps();

            $table->unique(['series_id', 'occurrence_date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('post_series_occurrences');
        Schema::dropIfExists('post_series');
    }
};
