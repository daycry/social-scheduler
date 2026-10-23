<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Identidades del login social (proveedor + id estable del proveedor) vinculadas a un usuario. Solo aditiva (ADR-004).
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('social_identities', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('provider', 20);
            $table->string('provider_user_id', 191);
            $table->string('email')->nullable();
            $table->timestamp('linked_at')->nullable();
            $table->timestamp('last_login_at')->nullable();
            // Desvinculada: la vinculacion automatica no la vuelve a enlazar hasta que el usuario la vincule a mano.
            $table->timestamp('blocked_at')->nullable();
            $table->timestamps();

            $table->unique(['provider', 'provider_user_id']);
            $table->index(['user_id', 'provider']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('social_identities');
    }
};
