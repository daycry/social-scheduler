<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Aditiva (C-03): la plantilla de la biblioteca conserva la campaña del post del que sale; si se borra, queda sin ella.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('post_templates', function (Blueprint $table) {
            $table->foreignId('campaign_id')->nullable()->after('brand_id')->constrained('campaigns')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('post_templates', function (Blueprint $table) {
            $table->dropConstrainedForeignId('campaign_id');
        });
    }
};
