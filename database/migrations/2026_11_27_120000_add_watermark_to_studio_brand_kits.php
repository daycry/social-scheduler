<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('studio_brand_kits', function (Blueprint $table) {
            // Marca de agua automática (T-91): logotipo del kit, posición, opacidad, tamaño y margen; nulo = sin configurar.
            $table->json('watermark')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('studio_brand_kits', function (Blueprint $table) {
            $table->dropColumn('watermark');
        });
    }
};
