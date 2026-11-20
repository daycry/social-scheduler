<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // En una instalación nueva, la migración histórica de creación se ejecuta después
        // e instala directamente el valor moderno. En una actualización, la tabla ya existe.
        if (! Schema::hasTable('brand_collaborators')) {
            return;
        }

        DB::table('brand_collaborators')->where('role', 'aprobador')->update(['role' => 'approver']);
        Schema::table('brand_collaborators', function (Blueprint $table): void {
            $table->string('role', 20)->default('approver')->change();
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('brand_collaborators')) {
            return;
        }

        DB::table('brand_collaborators')->where('role', 'approver')->update(['role' => 'aprobador']);
        Schema::table('brand_collaborators', function (Blueprint $table): void {
            $table->string('role', 20)->default('aprobador')->change();
        });
    }
};
