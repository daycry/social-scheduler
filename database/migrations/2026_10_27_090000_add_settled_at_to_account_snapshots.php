<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // `settled_at`: el snapshot ya no se reintenta (esta completo o salio de la ventana de reintentos). `null` = pendiente.
        Schema::table('account_snapshots', function (Blueprint $table) {
            $table->timestamp('settled_at')->nullable()->after('collected_at');
        });
    }

    public function down(): void
    {
        Schema::table('account_snapshots', function (Blueprint $table) {
            $table->dropColumn('settled_at');
        });
    }
};
