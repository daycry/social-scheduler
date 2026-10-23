<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Aditiva (ADR-004): duracion del acceso elegida en el consentimiento. NULL en codigos anteriores = duracion por defecto.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('oauth_auth_codes', function (Blueprint $table) {
            $table->unsignedSmallInteger('token_days')->nullable()->after('abilities');
        });
    }

    public function down(): void
    {
        Schema::table('oauth_auth_codes', fn (Blueprint $t) => $t->dropColumn('token_days'));
    }
};
