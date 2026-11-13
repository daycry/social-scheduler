<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Aditiva (T-32): cuando se cambio por ultima vez la REGLA de la serie (frecuencia, dias, hora, inicio, fin, numero de veces). «Recuperar
// fechas perdidas» solo ofrece fechas posteriores: una fecha que la regla vigente no tenia cuando debio generarse no se «perdio».
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('post_series', function (Blueprint $table) {
            $table->timestamp('rule_changed_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('post_series', function (Blueprint $table) {
            $table->dropColumn('rule_changed_at');
        });
    }
};
