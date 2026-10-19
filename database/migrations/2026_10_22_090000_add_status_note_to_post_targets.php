<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('post_targets', function (Blueprint $table) {
            // Aclaracion para el usuario cuando «publicada» tiene matices (p. ej. borrador en la bandeja de TikTok).
            $table->string('status_note', 300)->nullable()->after('error_message');
        });
    }

    public function down(): void
    {
        Schema::table('post_targets', function (Blueprint $table) {
            $table->dropColumn('status_note');
        });
    }
};
