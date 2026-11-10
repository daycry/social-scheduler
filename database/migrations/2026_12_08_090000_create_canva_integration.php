<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Conexion con Canva (T-16): una por marca. Los tokens van cifrados por el modelo (`encrypted`) y nunca salen por la API.
        Schema::create('canva_connections', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('brand_id')->constrained()->cascadeOnDelete();
            $table->string('canva_user_id', 80)->nullable();
            $table->string('display_name', 120)->nullable();
            $table->text('access_token');
            $table->text('refresh_token')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->text('scopes')->nullable();
            $table->text('capabilities')->nullable();
            $table->timestamp('capabilities_at')->nullable();
            $table->string('status', 20)->default('active');
            $table->timestamps();
            $table->unique('brand_id');
            $table->index('user_id');
        });

        // Trabajos asincronos contra Canva: exportar un diseno a la biblioteca (`export`) o enviar un recurso a Canva (`upload`).
        Schema::create('canva_jobs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('brand_id')->constrained()->cascadeOnDelete();
            $table->string('kind', 10);
            $table->string('design_id', 80)->nullable();
            $table->string('title', 255)->nullable();
            $table->string('format', 10)->nullable();
            $table->unsignedBigInteger('media_id')->nullable();
            $table->text('options')->nullable();
            $table->string('canva_job_id', 80)->nullable();
            $table->string('status', 20)->default('queued');
            $table->unsignedSmallInteger('polls')->default(0);
            $table->text('output_media_ids')->nullable();
            $table->text('download_urls')->nullable();
            $table->string('canva_asset_id', 80)->nullable();
            $table->string('error_code', 40)->nullable();
            $table->string('error', 500)->nullable();
            $table->timestamps();
            $table->index(['user_id', 'brand_id', 'status']);
        });

        // Origen de un recurso importado (id del diseno de Canva y enlace): `source = canva`, el resto en JSON.
        Schema::table('media_assets', function (Blueprint $table) {
            $table->text('origin')->nullable()->after('import_state');
        });
    }

    public function down(): void
    {
        Schema::table('media_assets', function (Blueprint $table) {
            $table->dropColumn('origin');
        });
        Schema::dropIfExists('canva_jobs');
        Schema::dropIfExists('canva_connections');
    }
};
