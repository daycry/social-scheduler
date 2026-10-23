<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Aditiva (ADR-005, C-09): notas internas del post, texto plano (hasta 5.000 caracteres, validado en la capa de aplicacion). Nunca se publican.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('posts', function (Blueprint $table) {
            $table->text('notes')->nullable()->after('text');
        });
    }

    public function down(): void
    {
        Schema::table('posts', fn (Blueprint $t) => $t->dropColumn('notes'));
    }
};
