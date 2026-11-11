<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Aditiva (T-19): colaboradores invitados por marca con rol (hoy solo `aprobador`). Revocables; una fila por marca y usuario.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('brand_collaborators', function (Blueprint $table) {
            $table->id();
            $table->foreignId('brand_id')->constrained()->cascadeOnDelete();
            $table->foreignId('owner_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('role', 20)->default('aprobador');
            $table->timestamps();

            $table->unique(['brand_id', 'user_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('brand_collaborators');
    }
};
