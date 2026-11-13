<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Aditiva (T-32): marca de que ya se envio el recordatorio ANTES de la hora de un destino de publicacion manual (una sola vez por hora
// programada; se rearma al reprogramar, igual que `manual_notified_at`).
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('post_targets', function (Blueprint $table) {
            $table->timestamp('manual_reminded_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('post_targets', function (Blueprint $table) {
            $table->dropColumn('manual_reminded_at');
        });
    }
};
