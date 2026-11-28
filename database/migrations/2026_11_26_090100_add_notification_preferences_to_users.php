<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            // Resumen semanal (T-86): opt-in (un correo recurrente que nadie ha pedido no se envia solo); el parrafo de IA es otro opt-in.
            $table->boolean('notify_weekly_digest')->default(false);
            $table->boolean('weekly_digest_ai')->default(false);
            // Recordatorio previo de publicacion manual (T-32): encendido por defecto, como se comportaba antes de poder apagarlo.
            $table->boolean('notify_manual_reminder')->default(true);
            // Lunes (fecha local) de la semana cuyo resumen ya se reclamo: el claim atomico que impide un segundo envio.
            $table->date('weekly_digest_sent_for')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['notify_weekly_digest', 'weekly_digest_ai', 'notify_manual_reminder', 'weekly_digest_sent_for']);
        });
    }
};
