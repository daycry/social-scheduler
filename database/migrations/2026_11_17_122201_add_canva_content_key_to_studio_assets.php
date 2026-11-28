<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const INDEX = 'studio_assets_brand_canva_content_unique';

    public function up(): void
    {
        Schema::table('studio_assets', function (Blueprint $table): void {
            $table->string('canva_content_key', 64)->nullable();
            $table->unique(['brand_id', 'canva_content_key'], self::INDEX);
        });
    }

    public function down(): void
    {
        Schema::table('studio_assets', function (Blueprint $table): void {
            $table->dropUnique(self::INDEX);
            $table->dropColumn('canva_content_key');
        });
    }
};
