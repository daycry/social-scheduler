<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Aditiva (T-19): destino de publicacion manual. No se envia a la red: a la hora se avisa al usuario (`manual_notified_at`)
// y el usuario lo marca como publicado.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('post_targets', function (Blueprint $table) {
            $table->boolean('manual')->default(false);
            $table->timestamp('manual_notified_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('post_targets', function (Blueprint $table) {
            $table->dropColumn(['manual', 'manual_notified_at']);
        });
    }
};
