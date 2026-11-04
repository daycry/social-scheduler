<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('studio_designs', fn (Blueprint $table) => $table->json('kit_reference')->nullable());
        Schema::table('studio_design_revisions', fn (Blueprint $table) => $table->json('kit_reference')->nullable());
    }

    public function down(): void
    {
        Schema::table('studio_design_revisions', fn (Blueprint $table) => $table->dropColumn('kit_reference'));
        Schema::table('studio_designs', fn (Blueprint $table) => $table->dropColumn('kit_reference'));
    }
};
