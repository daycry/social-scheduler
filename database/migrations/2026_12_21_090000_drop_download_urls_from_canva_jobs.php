<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // T-27: el PDF exportado entra en la biblioteca como un recurso mas; el enlace temporal de 24 h de Canva ya no se guarda.
        Schema::table('canva_jobs', function (Blueprint $table) {
            $table->dropColumn('download_urls');
        });
    }

    public function down(): void
    {
        Schema::table('canva_jobs', function (Blueprint $table) {
            $table->text('download_urls')->nullable()->after('output_media_ids');
        });
    }
};
