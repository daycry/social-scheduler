<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// Revision de la Fase 1 (intento 1). Solo aditiva (ADR-004): metadatos DCR del cliente, origen del token que sobrevive al
// borrado del cliente y si el codigo se pidio con redirect_uri explicita.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('oauth_clients', function (Blueprint $table) {
            $table->string('software_id', 255)->nullable()->after('redirect_uris');
            $table->string('software_version', 100)->nullable()->after('software_id');
            $table->string('created_ip', 45)->nullable()->after('software_version');
        });

        Schema::table('personal_access_tokens', function (Blueprint $table) {
            $table->string('origin', 10)->default('manual')->after('oauth_client_id');
        });
        DB::table('personal_access_tokens')->whereNotNull('oauth_client_id')->update(['origin' => 'oauth']);

        Schema::table('oauth_auth_codes', function (Blueprint $table) {
            $table->boolean('redirect_uri_sent')->default(true)->after('redirect_uri');
        });
    }

    public function down(): void
    {
        Schema::table('oauth_auth_codes', fn (Blueprint $t) => $t->dropColumn('redirect_uri_sent'));
        Schema::table('personal_access_tokens', fn (Blueprint $t) => $t->dropColumn('origin'));
        Schema::table('oauth_clients', fn (Blueprint $t) => $t->dropColumn(['software_id', 'software_version', 'created_ip']));
    }
};
