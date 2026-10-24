<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** C-04a: origen de la media (`upload|url|drive`), su referencia saneada y el estado de una importacion remota. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('media_assets', function (Blueprint $table) {
            $table->string('source', 10)->default('upload')->after('kind');
            $table->string('source_ref', 500)->nullable()->after('source'); // URL sin query ni credenciales, o fileId de Drive
            $table->json('import_state')->nullable()->after('source_ref'); // bytes, total, validador, pasadas...
        });
    }

    public function down(): void
    {
        Schema::table('media_assets', function (Blueprint $table) {
            $table->dropColumn(['source', 'source_ref', 'import_state']);
        });
    }
};
