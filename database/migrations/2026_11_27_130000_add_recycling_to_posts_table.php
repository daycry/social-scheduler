<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Aditiva (T-92): reciclado inteligente. `recycle_family_id` es la clave ESTABLE de la familia de un contenido: el id del post ORIGINAL
// (en los reciclados; nulo en el original, que es su propia clave). Sin FK a proposito: si el original se purga de la papelera, sus
// reciclados conservan la clave y el intervalo minimo sigue contando sobre el contenido y no sobre un id que ya no existe.
// `recycle_dismissed_at` es cuando el usuario descarto la propuesta de este post (vuelve a valorarse pasado el intervalo).
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('posts', function (Blueprint $table) {
            $table->unsignedBigInteger('recycle_family_id')->nullable()->index();
            $table->timestamp('recycle_dismissed_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('posts', function (Blueprint $table) {
            $table->dropIndex(['recycle_family_id']);
            $table->dropColumn(['recycle_family_id', 'recycle_dismissed_at']);
        });
    }
};
