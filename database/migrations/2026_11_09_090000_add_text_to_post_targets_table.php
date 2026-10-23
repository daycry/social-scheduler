<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Aditiva (ADR-005, C-03): texto propio de un destino. NULL = vinculado a la plantilla (`posts.text`); '' = texto propio vacio.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('post_targets', function (Blueprint $table) {
            $table->text('text')->nullable()->after('options');
        });
    }

    public function down(): void
    {
        Schema::table('post_targets', fn (Blueprint $t) => $t->dropColumn('text'));
    }
};
