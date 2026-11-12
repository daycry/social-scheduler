<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Preferencia (T-23): correo ADEMAS del aviso en la app para los fallos de trabajos asincronos y la caducidad de conexiones. Apagada por defecto.
        Schema::table('users', function (Blueprint $table) {
            $table->boolean('notify_by_mail')->default(false);
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('notify_by_mail');
        });
    }
};
