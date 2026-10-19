<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Foto diaria de las metricas de UNA cuenta. `metrics` guarda cada clave canonica (App\Analytics\Metric):
        // un valor `null` significa "no disponible" (la API no lo da), nunca 0. Una fila por cuenta y dia (upsert).
        Schema::create('account_snapshots', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('brand_id')->constrained()->cascadeOnDelete();
            $table->foreignId('social_account_id')->constrained()->cascadeOnDelete();
            $table->string('network', 20);
            $table->date('date');
            $table->json('metrics');
            $table->timestamp('collected_at')->nullable();
            $table->timestamps();

            $table->unique(['social_account_id', 'date']);
            $table->index(['brand_id', 'date']);
            $table->index(['user_id', 'date']);
        });

        // Metricas de una publicacion en un dia (la ultima lectura del dia; los acumulados crecen con el tiempo).
        Schema::create('post_metric_snapshots', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('post_target_id')->constrained()->cascadeOnDelete();
            $table->foreignId('social_account_id')->constrained()->cascadeOnDelete();
            $table->string('network', 20);
            $table->date('date');
            $table->json('metrics');
            $table->timestamp('collected_at')->nullable();
            $table->timestamps();

            $table->unique(['post_target_id', 'date']);
            $table->index(['social_account_id', 'date']);
            $table->index(['user_id', 'date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('post_metric_snapshots');
        Schema::dropIfExists('account_snapshots');
    }
};
